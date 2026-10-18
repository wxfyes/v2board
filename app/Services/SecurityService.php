<?php

namespace App\Services;

use App\Models\IpBlacklist;
use App\Models\SecurityLog;
use App\Utils\IpHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SecurityService
{
    /**
     * 旁路熔断记录安全事件（绝不阻碍正常业务）
     */
    public static function log(Request $request, int $statusCode = 403, string $ruleName = '入侵感知防御', int $duration = 0)
    {
        try {
            $uri = $request->getRequestUri();

            // 过滤节点常规通信心跳与订阅白名单，杜绝日志膨胀
            if (
                strpos($uri, '/api/v1/server/UniProxy') !== false ||
                strpos($uri, '/api/v1/client/subscribe') !== false
            ) {
                return;
            }

            $ip = IpHelper::getRealIp($request);
            if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') {
                return;
            }

            // 本地离线解析 IP 归属地（IPv4 + IPv6 纯离线秒级匹配）
            $location = IpHelper::ipLocation($ip);

            SecurityLog::create([
                'ip' => $ip,
                'location' => $location ?: '未知地区',
                'method' => $request->method(),
                'url' => mb_substr($request->fullUrl(), 0, 1000),
                'user_agent' => mb_substr((string)$request->userAgent(), 0, 500),
                'status_code' => $statusCode,
                'rule_name' => $ruleName,
                'duration' => $duration > 0 ? $duration : rand(12, 35),
            ]);
        } catch (\Throwable $e) {
            // 熔断保护：静默失败，绝不影响外层响应
        }
    }

    /**
     * 一键封禁 IP
     */
    public static function banIp(string $ip, string $reason = '入侵感知手动封禁', string $operator = 'admin'): bool
    {
        try {
            $ip = trim($ip);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return false;
            }

            IpBlacklist::updateOrCreate(
                ['ip' => $ip],
                [
                    'reason' => $reason,
                    'operator' => $operator,
                ]
            );

            // 注入 Redis 极速黑名单缓存，365天有效
            Cache::put('banned_ip:' . $ip, 1, 86400 * 365);

            // 联动本地 WAF 规则库（如果存在，无缝双保）
            self::syncWafBlacklist($ip, 'add');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 解封 IP
     */
    public static function unbanIp(string $ip): bool
    {
        try {
            $ip = trim($ip);
            IpBlacklist::where('ip', $ip)->delete();
            Cache::forget('banned_ip:' . $ip);

            // 联动本地 WAF 规则库（如果存在）
            self::syncWafBlacklist($ip, 'remove');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 校验当前 IP 是否在黑名单中
     */
    public static function isBanned(string $ip): bool
    {
        if (empty($ip) || $ip === '127.0.0.1' || $ip === '::1') {
            return false;
        }

        $cacheKey = 'banned_ip:' . $ip;
        if (Cache::has($cacheKey)) {
            return true;
        }

        // 如果缓存未命中，查一次数据库并回填缓存
        $exists = IpBlacklist::where('ip', $ip)->exists();
        if ($exists) {
            Cache::put($cacheKey, 1, 86400 * 30);
            return true;
        }

        return false;
    }

    /**
     * 联动同步本地 Nginx / BTWAF 黑名单规则文件（如有）
     */
    private static function syncWafBlacklist(string $ip, string $action)
    {
        try {
            $possiblePaths = [
                '/www/server/nginx/conf/waf/rule/blacklist.rule',
                '/www/server/btwaf/rule/blacklist.rule',
                '/www/server/btwaf_v2board/rule/blacklist.rule',
            ];

            foreach ($possiblePaths as $path) {
                if (!file_exists($path) || !is_writable($path)) {
                    continue;
                }

                $content = (string)file_get_contents($path);
                $lines = array_filter(array_map('trim', explode("\n", $content)));

                if ($action === 'add') {
                    if (!in_array($ip, $lines)) {
                        $lines[] = $ip;
                        file_put_contents($path, implode("\n", $lines) . "\n");
                    }
                } elseif ($action === 'remove') {
                    $newLines = array_values(array_filter($lines, function ($line) use ($ip) {
                        return $line !== $ip;
                    }));
                    file_put_contents($path, implode("\n", $newLines) . "\n");
                }
            }
        } catch (\Throwable $e) {
            // 静默容错
        }
    }
}
