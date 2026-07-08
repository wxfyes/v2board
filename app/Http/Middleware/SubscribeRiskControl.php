<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * 订阅风控中间件（全功能版）
 *
 * 记录方式：
 *  1. risk_logs 数据库表（含邮箱、User-Agent）
 *  2. Laravel 日志文件（storage/logs/risk-*.log）
 *  3. Telegram Bot 推送（响应后异步发送）
 *  4. Redis 累计风险计数（7 天滑动窗口）
 *  5. 高风险自动封禁（累计触发 >= BAN_THRESHOLD 次且单次评分 >= BAN_MIN_SCORE）
 */
class SubscribeRiskControl
{
    // ===================== 配置区 =====================

    // 频率限制
    const RATE_LIMIT    = 15;   // 时间窗口内最大请求次数
    const RATE_WINDOW   = 60;   // 时间窗口（秒）

    // 自动封禁：7 天内触发次数 >= BAN_THRESHOLD 且单次评分 >= BAN_MIN_SCORE 时封禁
    const BAN_THRESHOLD  = 3;   // 7 天内最多允许触发几次
    const BAN_MIN_SCORE  = 60;  // 触发封禁的最低单次评分
    const BAN_WINDOW     = 7;   // 累计统计窗口（天）

    // ==================================================



    // -------------------------------------------------------------------------
    // 主入口
    // -------------------------------------------------------------------------

    public function handle(Request $request, Closure $next)
    {
        $user = \App\Models\User::where('token', $request->query('token'))->first();

        if (!$user || !empty($user->is_admin)) {
            return $next($request);
        }

        $ip        = $this->getRealIp($request);
        $userAgent = $request->userAgent() ?? 'unknown';

        // ① 频率限制
        if ($this->isRateLimited($user->id)) {
            $reason = '高频订阅（' . self::RATE_WINDOW . 's 内超过 ' . self::RATE_LIMIT . ' 次）';
            $this->alert($user, $ip, $userAgent, $reason, 80, $request);
            return response()->json(['message' => 'Too Many Requests'], 429);
        }

        // ② IP 跨区域检测
        $this->checkRegion($user, $ip, $userAgent, $request);

        return $next($request);
    }



    // -------------------------------------------------------------------------
    // 获取真实 IP（三级回退，兼容 Cloudflare）
    // -------------------------------------------------------------------------

    private function getRealIp(Request $request): string
    {
        $cfIp = $request->header('CF-Connecting-IP');
        if ($cfIp && filter_var(trim($cfIp), FILTER_VALIDATE_IP)) {
            return trim($cfIp);
        }

        $xff = $request->header('X-Forwarded-For');
        if ($xff) {
            foreach (explode(',', $xff) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP,
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $candidate;
                }
            }
        }

        return $request->ip();
    }

    // -------------------------------------------------------------------------
    // 频率限制
    // -------------------------------------------------------------------------

    private function isRateLimited(int $userId): bool
    {
        try {
            $key   = "sub_rate:{$userId}";
            $count = Redis::incr($key);
            if ((int) $count === 1) Redis::expire($key, self::RATE_WINDOW);
            return $count > self::RATE_LIMIT;
        } catch (\Throwable $e) {
            Log::channel('risk')->warning('[风控] Redis 不可用，跳过频率检测', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // IP 跨区域检测
    // -------------------------------------------------------------------------

    private function checkRegion($user, string $ip, string $userAgent, Request $request): void
    {
        try {
            $current = $this->getCountry($ip);
            if (!$current) return;

            $cacheKey = "sub_region:{$user->id}";
            $last     = null;

            try {
                $last = Redis::get($cacheKey);
                Redis::setex($cacheKey, 86400, $current);
            } catch (\Throwable $e) {
                Log::channel('risk')->warning('[风控] Redis 地区缓存异常', ['error' => $e->getMessage()]);
            }

            if ($last && $last !== $current) {
                $reason = "IP 跨区域：{$last} → {$current}";
                $this->alert($user, $ip, $userAgent, $reason, 60, $request);
            }
        } catch (\Throwable $e) {
            Log::channel('risk')->warning('[风控] 地区检测异常', ['error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // IP 归属国家查询（带缓存）
    // -------------------------------------------------------------------------

    private function getCountry(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        try {
            $cached = Redis::get("ip_country:{$ip}");
            if ($cached) return $cached;
        } catch (\Throwable $e) {}

        try {
            $res = Http::timeout(2)
                ->get("http://ip-api.com/json/{$ip}", ['fields' => 'status,countryCode'])
                ->json();

            if (($res['status'] ?? '') === 'success' && !empty($res['countryCode'])) {
                try { Redis::setex("ip_country:{$ip}", 3600, $res['countryCode']); } catch (\Throwable $e) {}
                return $res['countryCode'];
            }
        } catch (\Throwable $e) {
            Log::channel('risk')->info('[风控] IP 查询失败（跳过）', ['ip' => $ip]);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // 统一告警入口
    // -------------------------------------------------------------------------

    private function alert($user, string $ip, string $userAgent, string $reason, int $score, Request $request): void
    {
        // 1. 写数据库
        $this->writeLog($user, $ip, $userAgent, $reason, $score);

        // 2. 写日志文件
        $this->writeFile($user, $ip, $userAgent, $reason, $score);

        // 3. 累计风险计数 + 判断是否自动封禁
        $triggerCount = $this->incrementRiskCount($user->id);
        if ($score >= self::BAN_MIN_SCORE && $triggerCount >= self::BAN_THRESHOLD) {
            $this->autoBan($user, $reason, $triggerCount);
        }

        // 4. Telegram（同步直接发送，确保兼容 Webman/Workerman 容器）
        $this->sendTelegram($user, $ip, $userAgent, $reason, $score, $triggerCount);
    }

    // -------------------------------------------------------------------------
    // 1. 写 risk_logs 数据库表
    // -------------------------------------------------------------------------

    private function writeLog($user, string $ip, string $userAgent, string $reason, int $score): void
    {
        try {
            DB::table('risk_logs')->insert([
                'user_id'    => $user->id,
                'email'      => $user->email,
                'ip'         => $ip,
                'user_agent' => mb_substr($userAgent, 0, 255), // 防止超长截断
                'risk_score' => $score,
                'reason'     => $reason,
                'detected_at'=> now(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('risk')->error('[风控] 写库失败', ['error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // 2. 写日志文件
    // -------------------------------------------------------------------------

    private function writeFile($user, string $ip, string $userAgent, string $reason, int $score): void
    {
        Log::channel('risk')->warning('[风控] 异常订阅', [
            'user_id'    => $user->id,
            'email'      => $user->email,
            'ip'         => $ip,
            'user_agent' => $userAgent,
            'reason'     => $reason,
            'risk_score' => $score,
        ]);
    }

    // -------------------------------------------------------------------------
    // 3. Redis 累计风险计数（7 天滑动窗口）
    // -------------------------------------------------------------------------

    private function incrementRiskCount(int $userId): int
    {
        try {
            $key   = "sub_risk_count:{$userId}";
            $count = Redis::incr($key);
            if ((int) $count === 1) {
                Redis::expire($key, self::BAN_WINDOW * 86400);
            }
            return (int) $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // 自动封禁
    // -------------------------------------------------------------------------

    private function autoBan($user, string $reason, int $triggerCount): void
    {
        try {
            // v2board 封禁字段为 banned，值为 1
            \App\Models\User::where('id', $user->id)->update(['banned' => 1]);

            Log::channel('risk')->warning('[风控] 用户已自动封禁', [
                'user_id'       => $user->id,
                'email'         => $user->email,
                'trigger_count' => $triggerCount,
                'reason'        => $reason,
            ]);
        } catch (\Throwable $e) {
            Log::channel('risk')->error('[风控] 自动封禁失败', ['error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // 4. Telegram 推送（响应后执行）
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // 4. Telegram 推送（同步直接发送）
    // -------------------------------------------------------------------------

    private function sendTelegram($user, string $ip, string $userAgent, string $reason, int $score, int $triggerCount): void
    {
        // 四重环境变量备用读取，彻底解决 Laravel 缓存、Webman、Swoole 常驻进程无法读取 env() 的痛点
        $botToken = config('services.telegram.bot_token')
            ?? $_ENV['TELEGRAM_BOT_TOKEN']
            ?? getenv('TELEGRAM_BOT_TOKEN')
            ?? env('TELEGRAM_BOT_TOKEN');

        $chatId = config('services.telegram.chat_id')
            ?? $_ENV['TELEGRAM_CHAT_ID']
            ?? getenv('TELEGRAM_CHAT_ID')
            ?? env('TELEGRAM_CHAT_ID');

        if (!$botToken || !$chatId) {
            Log::channel('risk')->info('[风控] 未配置 TELEGRAM_BOT_TOKEN 或 TELEGRAM_CHAT_ID，跳过 TG 推送');
            return;
        }

        $emoji    = $score >= 80 ? '🔴' : ($score >= 60 ? '🟠' : '🟡');
        $banWarn  = ($score >= self::BAN_MIN_SCORE && $triggerCount >= self::BAN_THRESHOLD)
                    ? "\n🚫 已自动封禁" : '';

        $text = implode("\n", [
            "{$emoji} 订阅风控告警{$banWarn}",
            "━━━━━━━━━━━━",
            "👤 {$user->email}（ID: {$user->id}）",
            "🌐 IP：{$ip}",
            "📱 客户端：{$userAgent}",
            "⚠️ 原因：{$reason}",
            "📊 评分：{$score}/100  |  7天累计：{$triggerCount}次",
            "🕐 " . now()->toDateTimeString(),
        ]);

        try {
            Http::timeout(5)->post(
                'https://api.telegram.org/bot' . trim($botToken) . '/sendMessage',
                ['chat_id' => trim($chatId), 'text' => $text]
            );
        } catch (\Throwable $e) {
            Log::channel('risk')->warning('[风控] Telegram 推送失败', ['error' => $e->getMessage()]);
        }
    }
}
