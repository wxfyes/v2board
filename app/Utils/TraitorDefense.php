<?php

namespace App\Utils;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class TraitorDefense
{
    const CACHE_KEY = 'traitor_defense_data';
    const CACHE_TTL = 86400; // 缓存 24 小时，后台保存时自动刷新

    /**
     * 获取黑名单数据（优先读取 Redis 内存缓存，消除每次登录读盘开销）
     *
     * @return array
     */
    public static function getTraitorData(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $traitorListPath = storage_path('traitor_list.json');
            if (!file_exists($traitorListPath)) {
                return ['emails' => [], 'ips' => []];
            }

            $traitorData = json_decode(@file_get_contents($traitorListPath), true) ?: [];
            $emails = array_values(array_unique(array_filter(array_map('strtolower', $traitorData['emails'] ?? []))));
            $ips = array_values(array_unique(array_filter($traitorData['ips'] ?? [])));

            return [
                'emails' => $emails,
                'ips'    => $ips
            ];
        });
    }

    /**
     * 主动刷新黑名单缓存（后台保存时调用）
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * 判断指定 IP 是否匹配黑名单规则（支持绝对 IP 与 CIDR 网段，如 211.145.0.0/16）
     *
     * @param string $ip 待检测 IP
     * @return bool
     */
    public static function isIpTraitor(string $ip): bool
    {
        if (empty($ip)) {
            return false;
        }

        $traitorData = self::getTraitorData();
        $traitorIps = $traitorData['ips'] ?? [];
        if (empty($traitorIps)) {
            return false;
        }

        return self::matchIpList($ip, $traitorIps);
    }

    /**
     * 高性能 IP / CIDR 匹配函数（全量支持 IPv4 与 IPv6，支持纯 IP 与 CIDR 子网掩码）
     *
     * @param string $ip 目标 IP
     * @param array $rules 规则列表 (包含 IPv4/IPv6 纯 IP 或 IP/mask)
     * @return bool
     */
    public static function matchIpList(string $ip, array $rules): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        $isIpv4 = strlen($ipBin) === 4;
        $maxBits = $isIpv4 ? 32 : 128;

        foreach ($rules as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }

            if (strpos($rule, '/') !== false) {
                // CIDR 掩码匹配 (如 211.145.0.0/16, 2400:dd0d:2000::/64)
                list($subnet, $bitsStr) = explode('/', $rule, 2);
                $subnet = trim($subnet);
                $bits = (int)trim($bitsStr);

                $subnetBin = @inet_pton($subnet);
                if ($subnetBin === false) {
                    continue;
                }

                // 协议族版本必须一致 (IPv4 vs IPv6)
                if (strlen($ipBin) !== strlen($subnetBin)) {
                    continue;
                }

                if ($bits < 0 || $bits > $maxBits) {
                    continue;
                }

                if ($bits === 0) {
                    return true;
                }

                // 二进制掩码分块比对
                $bytes = (int)($bits / 8);
                $remBits = $bits % 8;

                if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
                    continue;
                }

                if ($remBits > 0) {
                    $mask = (0xFF << (8 - $remBits)) & 0xFF;
                    if ((ord($ipBin[$bytes]) & $mask) !== (ord($subnetBin[$bytes]) & $mask)) {
                        continue;
                    }
                }

                return true;
            } else {
                // 绝对 IP 匹配（二进制全等比对，自动兼容 IPv6 各种展开/缩写格式）
                $ruleBin = @inet_pton($rule);
                if ($ruleBin !== false && $ipBin === $ruleBin) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 判断用户是否享有“终极免死金牌”（管理员 或 天阙安全白名单用户）
     *
     * @param mixed $user 用户模型或数组
     * @return bool
     */
    public static function isWhitelistedUser($user): bool
    {
        if (empty($user)) {
            return false;
        }

        // 1. 管理员拥有绝对免死豁免权
        $isAdmin = is_array($user) ? !empty($user['is_admin']) : !empty($user->is_admin);
        if ($isAdmin) {
            return true;
        }

        // 2. 检查天阙安全白名单 (whitelist_users)
        try {
            $configPath = storage_path('tianque_config.json');
            if (file_exists($configPath)) {
                $config = json_decode(@file_get_contents($configPath), true) ?: [];
                $whitelist = $config['whitelist_users'] ?? [];
                if (is_array($whitelist) && !empty($whitelist)) {
                    $userId = (int)(is_array($user) ? ($user['id'] ?? 0) : ($user->id ?? 0));
                    $userEmail = strtolower((string)(is_array($user) ? ($user['email'] ?? '') : ($user->email ?? '')));

                    foreach ($whitelist as $item) {
                        if (is_numeric($item) && (int)$item === $userId) {
                            return true;
                        }
                        if (is_string($item) && strtolower(trim($item)) === $userEmail) {
                            return true;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // 静默容错
        }

        return false;
    }

    /**
     * 检查并自动将命中的内鬼用户加入蜜罐
     *
     * @param mixed $user 用户模型或数组
     * @param string $ip 客户端真实 IP
     * @param string $userAgent 客户端 UA
     * @param string $action 触发动作 (如 '注册', '登录', '第三方登录', '订阅拉取')
     * @return void
     */
    public static function checkAndHoneypot($user, string $ip, string $userAgent = 'unknown', string $action = '注册')
    {
        try {
            // 🛡️ 终极免死金牌：管理员或白名单用户拥有绝对豁免权，绝不判定为内鬼
            if (self::isWhitelistedUser($user)) {
                return;
            }

            $traitorData = self::getTraitorData();
            $traitorEmails = $traitorData['emails'] ?? [];
            $traitorIps = $traitorData['ips'] ?? [];

            // 若黑名单为空，极速退出（0 开销）
            if (empty($traitorEmails) && empty($traitorIps)) {
                return;
            }

            $userEmail = strtolower(is_array($user) ? ($user['email'] ?? '') : ($user->email ?? ''));
            $isTraitorEmail = !empty($traitorEmails) && in_array($userEmail, $traitorEmails, true);
            $isTraitorIp = self::matchIpList($ip, $traitorIps);

            if ($isTraitorEmail || $isTraitorIp) {
                // 命中内鬼特征
                $reason = [];
                if ($isTraitorEmail) $reason[] = "邮箱在黑名单中";
                if ($isTraitorIp) $reason[] = "IP在黑名单中(命中网段或绝对IP)";
                $reasonStr = implode("，", $reason);

                self::putIntoHoneypot($user, $ip, $userAgent, $action, $reasonStr);
            }
        } catch (\Throwable $e) {
            Log::channel('risk')->error('[内鬼防御] 检测异常', ['error' => $e->getMessage()]);
        }
    }

    public static function putIntoHoneypot($user, string $ip, string $userAgent, string $action, string $reasonStr)
    {
        // 🛡️ 终极免死金牌：管理员或白名单用户绝对禁止写入蜜罐！
        if (self::isWhitelistedUser($user)) {
            return;
        }

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

        if (!isset($config['flagged_users']) || !is_array($config['flagged_users'])) {
            $config['flagged_users'] = [];
        }

        $userId = (int)(is_array($user) ? ($user['id'] ?? 0) : ($user->id ?? 0));
        $userEmail = (string)(is_array($user) ? ($user['email'] ?? '') : ($user->email ?? ''));

        if ($userId <= 0) {
            return;
        }

        $currentHoneypots = array_map('intval', $config['honeypot_users']);

        // 如果用户已在蜜罐中，无需重复写盘和发告警，极速退出
        if (in_array($userId, $currentHoneypots, true)) {
            return;
        }

        // 将用户加入蜜罐名单
        $currentHoneypots[] = $userId;
        $config['honeypot_users'] = array_values(array_unique($currentHoneypots));
        $config['honeypot_times'][(string)$userId] = time();

        // 同时记录到 flagged_users 以便在安全审计中展示详细拦截原委
        $config['flagged_users'][(string)$userId] = [
            'email' => $userEmail,
            'time' => time(),
            'reasons' => ["内鬼防御系统前置拦截", $reasonStr]
        ];

        // 加排他锁写入配置，防止并发写入损坏 JSON
        $writeResult = @file_put_contents(
            $configPath,
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
        
        if ($writeResult !== false) {
            // 只有成功写入文件后，才清空 session 和发报警
            try {
                if ($user instanceof \App\Models\User) {
                    $authService = new \App\Services\AuthService($user);
                    $authService->removeAllSession();
                }
            } catch (\Throwable $ex) {}

            Log::channel('risk')->warning('[主动防御] 用户已自动导入蜜罐（黑名单触发）', [
                'user_id' => $userId,
                'email'   => $userEmail,
                'reason'  => $reasonStr,
                'action'  => $action
            ]);

            // 发送 TG 告警：使用 fastcgi_finish_request 彻底异步化，对客户端 0 毫秒阻塞
            self::sendTelegramAlertAsync($user, $ip, $userAgent, $action, $reasonStr);
        } else {
            Log::channel('risk')->error('[内鬼防御] tianque_config.json 写入失败，请检查 storage 目录及文件读写权限！');
        }
    }

    /**
     * 异步发送 Telegram 告警
     * 使用 register_shutdown_function + fastcgi_finish_request
     * 客户端此时已经拿到响应并关闭连接，PHP-FPM 在后台静默发送，绝不挂起前台请求
     */
    private static function sendTelegramAlertAsync($user, string $ip, string $userAgent, string $action, string $reasonStr)
    {
        register_shutdown_function(function () use ($user, $ip, $userAgent, $action, $reasonStr) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request(); // 立即将响应输出给客户端并断开连接
            }
            self::sendTelegramAlert($user, $ip, $userAgent, $action, $reasonStr);
        });
    }

    private static function sendTelegramAlert($user, string $ip, string $userAgent, string $action, string $reasonStr)
    {
        try {
            $botToken = config('services.telegram.bot_token')
                ?? $_ENV['TELEGRAM_BOT_TOKEN']
                ?? getenv('TELEGRAM_BOT_TOKEN')
                ?? env('TELEGRAM_BOT_TOKEN');

            $chatId = config('services.telegram.chat_id')
                ?? $_ENV['TELEGRAM_CHAT_ID']
                ?? getenv('TELEGRAM_CHAT_ID')
                ?? env('TELEGRAM_CHAT_ID');

            if (!$botToken || !$chatId) return;

            $userId = (int)(is_array($user) ? ($user['id'] ?? 0) : ($user->id ?? 0));
            $userEmail = (string)(is_array($user) ? ($user['email'] ?? '') : ($user->email ?? ''));

            $text = implode("\n", [
                "🚨 主动防御告警 (黑名单触发)",
                "━━━━━━━━━━━━",
                "👤 {$userEmail}（ID: {$userId}）",
                "🛠️ 触发动作：{$action}",
                "⚠️ 命中原因：{$reasonStr}",
                "🌐 IP：{$ip}",
                "🍯 状态：已第一时间打入蜜罐",
                "🕐 " . date('Y-m-d H:i:s'),
            ]);

            Http::timeout(2)->post(
                'https://api.telegram.org/bot' . trim($botToken) . '/sendMessage',
                [
                    'chat_id' => trim($chatId),
                    'text'    => $text
                ]
            );
        } catch (\Throwable $e) {
            // 静默处理，后台失败不影响任何正常业务
        }
    }
}

