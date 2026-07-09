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
    const BAN_THRESHOLD  = 5;   // 7 天内最多允许触发几次
    const BAN_MIN_SCORE  = 80;  // 触发封禁的最低单次评分，调高至 80（跨国 60 分只告警不自动封禁）
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

        // 🛡️ 校验天阙白名单（若在白名单中则直接放行）
        if ($this->isUserWhitelisted($user)) {
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
            $geo = $this->getGeoInfo($ip);
            if (!$geo || empty($geo['countryCode'])) return;

            $country = $geo['countryCode'];
            $region  = $geo['region'] ?? ''; // 省份代码，如 GD, SH, SN
            $hosting = $geo['hosting'] ?? false;
            $org     = $geo['org'] ?? '';
            $as      = $geo['as'] ?? '';

            // ① 云服务器厂商/机房 IP 精准拦截 (阿里云、腾讯云、华为云、AWS、Google Cloud、Microsoft Azure、甲骨文、Hetzner、中华电信HiNet等)
            $idcKeywords = [
                'Alibaba', 'Tencent', 'Huawei', 'Amazon', 'AWS', 'Google', 'Microsoft', 
                'Azure', 'Oracle', 'DigitalOcean', 'Linode', 'Vultr', 'Choopa', 'OVH', 
                'Zenlayer', 'Leaseweb', 'Cloudflare', 'Fastly', 'Hetzner', 'QuadraNet',
                'ColoCrossing', 'Psychz', '中华电信', 'HiNet'
            ];
            
            $isTopIdc = false;
            $orgLower = strtolower($org);
            $asLower  = strtolower($as);
            foreach ($idcKeywords as $kw) {
                $kwLower = strtolower($kw);
                if (strpos($orgLower, $kwLower) !== false || strpos($asLower, $kwLower) !== false) {
                    $isTopIdc = true;
                    break;
                }
            }

            if ($isTopIdc) {
                $reason = "机房/中转IP封禁：检测到拉取源属于顶级IDC [{$org} | {$as}]";
                // 顶级 IDC 无论国内外，直接 100 分秒封入蜜罐
                $this->alert($user, $ip, $userAgent, $reason, 100, $request);
                return;
            }

            // ② 核心体验优化：如果不是机房托管IP（即普通住宅/移动网络），直接绿灯放行，不再做跨国/跨省检测，彻底根治误报
            if (!$hosting) {
                return;
            }

            // ③ 跨国检测（配合 UA 检测进行白名单放行，彻底防止同设备换代理误封）
            $countryCacheKey = "sub_region:{$user->id}";
            $lastCountry     = null;
            try {
                $lastCountry = Redis::get($countryCacheKey);
                Redis::setex($countryCacheKey, 86400, $country);
            } catch (\Throwable $e) {}

            if ($lastCountry && $lastCountry !== $country) {
                // 读取上一次拉取的 UA
                $uaCacheKey = "sub_ua:{$user->id}";
                $lastUa     = null;
                try {
                    $lastUa = Redis::get($uaCacheKey);
                    Redis::setex($uaCacheKey, 86400, $userAgent);
                } catch (\Throwable $e) {}

                // 只有当国家发生了变动，且客户端的 User-Agent 也变动了，才计入跨国异常（防止自己切代理误封）
                if ($lastUa && $lastUa !== $userAgent) {
                    $reason = "IP 跨国界异设备(机房源)：{$lastCountry}({$lastUa}) → {$country}({$userAgent}) [机房: {$org}]";
                    $this->alert($user, $ip, $userAgent, $reason, 60, $request);
                }
            }

            // ④ 国内跨省检测（24小时内拉取 IP 覆盖省份数量 >= 3 直接拉闸秒封）
            if ($country === 'CN' && $region) {
                $provCacheKey = "sub_provinces:{$user->id}";
                try {
                    $isNew = Redis::sadd($provCacheKey, $region);
                    if ($isNew) {
                        $card = Redis::scard($provCacheKey);
                        if ((int)$card === 1) {
                            Redis::expire($provCacheKey, 86400); // 首次写入，生存期 24 小时
                        }

                        if ((int)$card >= 3) {
                            $provinces = Redis::smembers($provCacheKey);
                            $provList  = implode(', ', $provinces);
                            $reason    = "24小时内国内跨省异常(机房源)：已覆盖 {$card} 个省份 ({$provList})";
                            // 扣 100 分直接秒封
                            $this->alert($user, $ip, $userAgent, $reason, 100, $request);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::channel('risk')->warning('[风控] 国内省份数量检测异常', ['error' => $e->getMessage()]);
                }
            }

        } catch (\Throwable $e) {
            Log::channel('risk')->warning('[风控] 地区检测异常', ['error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // IP 归属地理查询（带缓存，缓存国家及省份代码）
    // -------------------------------------------------------------------------

    private function getGeoInfo(string $ip): ?array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        try {
            $cached = Redis::get("ip_geo:{$ip}");
            if ($cached) return json_decode($cached, true);
        } catch (\Throwable $e) {}

        try {
            $res = Http::timeout(2)
                ->get("http://ip-api.com/json/{$ip}", ['fields' => 'status,countryCode,region,hosting,org,as'])
                ->json();

            if (($res['status'] ?? '') === 'success') {
                try { Redis::setex("ip_geo:{$ip}", 3600, json_encode($res)); } catch (\Throwable $e) {}
                return $res;
            }
        } catch (\Throwable $e) {
            Log::channel('risk')->info('[风控] IP 地理查询失败（跳过）', ['ip' => $ip]);
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
        // 如果评分等于 100（极其严重的违规），或者累计次数触发了阈值，则直接封禁
        if ($score >= 100 || ($score >= self::BAN_MIN_SCORE && $triggerCount >= self::BAN_THRESHOLD)) {
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
    // 自动导入蜜罐
    // -------------------------------------------------------------------------

    private function autoBan($user, string $reason, int $triggerCount): void
    {
        try {
            $configPath = storage_path('tianque_config.json');
            $config = [];
            if (file_exists($configPath)) {
                $config = json_decode(@file_get_contents($configPath), true) ?: [];
            }

            if (!isset($config['honeypot_users']) || !is_array($config['honeypot_users'])) {
                $config['honeypot_users'] = [];
            }
            if (!isset($config['honeypot_times']) || !is_array($config['honeypot_times'])) {
                $config['honeypot_times'] = [];
            }

            $userId = (int)$user->id;
            $currentHoneypots = array_map('intval', $config['honeypot_users']);

            if (!in_array($userId, $currentHoneypots, true)) {
                // 将用户加入蜜罐名单
                $currentHoneypots[] = $userId;
                $config['honeypot_users'] = $currentHoneypots;
                $config['honeypot_times'][(string)$userId] = time();

                // 从疑似标记名单中移出（如果存在）
                if (isset($config['flagged_users']) && is_array($config['flagged_users'])) {
                    if (isset($config['flagged_users'][(string)$userId])) {
                        unset($config['flagged_users'][(string)$userId]);
                    }
                }

                @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                // 强制清除该用户所有活跃登录 Session，迫使其客户端重连拉取最新的诱饵节点
                try {
                    $authService = new \App\Services\AuthService($user);
                    $authService->removeAllSession();
                } catch (\Throwable $ex) {}

                Log::channel('risk')->warning('[风控] 用户已自动导入蜜罐（假封禁）', [
                    'user_id'       => $userId,
                    'email'         => $user->email,
                    'trigger_count' => $triggerCount,
                    'reason'        => $reason,
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('risk')->error('[风控] 自动导入蜜罐失败', ['error' => $e->getMessage()]);
        }
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

        $isBanned = ($score >= 100 || ($score >= self::BAN_MIN_SCORE && $triggerCount >= self::BAN_THRESHOLD));
        $emoji    = $score >= 80 ? '🔴' : ($score >= 60 ? '🟠' : '🟡');
        $banWarn  = $isBanned ? "\n🍯 已自动导入蜜罐" : '';

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

        // 构建行内键盘按钮
        $keyboard = [
            'inline_keyboard' => [
                [
                    $isBanned 
                        ? ['text' => '↩️ 移出蜜罐', 'callback_data' => "unhoneypot:{$user->id}"]
                        : ['text' => '🍯 放入蜜罐', 'callback_data' => "honeypot:{$user->id}"],
                    ['text' => '🛡️ 设为白名单', 'callback_data' => "whitelist:{$user->id}"],
                    ['text' => '🔄 重置订阅', 'callback_data' => "reset:{$user->id}"]
                ]
            ]
        ];

        try {
            Http::timeout(5)->post(
                'https://api.telegram.org/bot' . trim($botToken) . '/sendMessage',
                [
                    'chat_id'      => trim($chatId),
                    'text'         => $text,
                    'reply_markup' => json_encode($keyboard)
                ]
            );
        } catch (\Throwable $e) {
            Log::channel('risk')->warning('[风控] Telegram 推送失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 判断用户是否在天阙安全白名单中
     */
    private function isUserWhitelisted($user): bool
    {
        try {
            $configPath = storage_path('tianque_config.json');
            if (file_exists($configPath)) {
                $config = json_decode(@file_get_contents($configPath), true) ?: [];
                if (isset($config['whitelist_users']) && is_array($config['whitelist_users'])) {
                    $userId = (int)$user->id;
                    $userEmail = strtolower($user->email);
                    
                    foreach ($config['whitelist_users'] as $item) {
                        // 匹配用户 ID
                        if (is_numeric($item) && (int)$item === $userId) {
                            return true;
                        }
                        // 匹配邮箱
                        if (is_string($item) && strtolower($item) === $userEmail) {
                            return true;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::channel('risk')->warning('[风控] 校验白名单异常', ['error' => $e->getMessage()]);
        }
        return false;
    }
}
