<?php

namespace App\Utils;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

            $traitorData = file_exists($traitorListPath) ? (json_decode(@file_get_contents($traitorListPath), true) ?: []) : null;
            $emails = is_array($traitorData) ? array_values(array_unique(array_filter(array_map('strtolower', $traitorData['emails'] ?? [])))) : [];
            $ips = is_array($traitorData) ? array_values(array_unique(array_filter($traitorData['ips'] ?? []))) : [];

            // 若本地文件不存在，或仅包含初始示例占位数据，主动尝试从数据库持久化备份表自愈
            if (!file_exists($traitorListPath) || $emails === ['example_traitor@gmail.com']) {
                $dbData = self::getBackupFromDb('traitor_list');
                if (!empty($dbData) && is_array($dbData) && (!empty($dbData['emails']) || !empty($dbData['ips']))) {
                    $dbEmails = array_values(array_unique(array_filter(array_map('strtolower', $dbData['emails'] ?? []))));
                    $dbIps = array_values(array_unique(array_filter($dbData['ips'] ?? [])));
                    @file_put_contents(
                        $traitorListPath,
                        json_encode(['emails' => $dbEmails, 'ips' => $dbIps], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                        LOCK_EX
                    );
                    return ['emails' => $dbEmails, 'ips' => $dbIps];
                }
            }

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

        $config = self::getTianqueConfig();

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
        $writeResult = self::saveTianqueConfig($config);
        
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

    /**
     * 确保安全配置持久化备份表存在（静默自愈建表，0 依赖，免敲 migrate 命令）
     */
    public static function ensureBackupTableExists(): bool
    {
        try {
            if (!Schema::hasTable('v2_security_configs')) {
                DB::statement("
                    CREATE TABLE IF NOT EXISTS `v2_security_configs` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `key` varchar(64) NOT NULL,
                        `value` longtext NULL,
                        `updated_at` int(11) NOT NULL DEFAULT 0,
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `idx_security_key` (`key`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
            }
            return true;
        } catch (\Throwable $e) {
            Log::channel('risk')->error('[安全系统] 自愈建表检测失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 从数据库安全备份表读取数据
     */
    public static function getBackupFromDb(string $key): ?array
    {
        try {
            self::ensureBackupTableExists();
            $record = DB::table('v2_security_configs')->where('key', $key)->first();
            if ($record && !empty($record->value)) {
                $data = json_decode($record->value, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        } catch (\Throwable $e) {
            // 静默容错
        }
        return null;
    }

    /**
     * 保存备份到数据库安全备份表
     */
    public static function saveBackupToDb(string $key, array $data): bool
    {
        try {
            self::ensureBackupTableExists();
            return DB::table('v2_security_configs')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => json_encode($data, JSON_UNESCAPED_UNICODE),
                    'updated_at' => time()
                ]
            );
        } catch (\Throwable $e) {
            Log::channel('risk')->error("[安全系统] 同步写入数据库备份失败 ({$key}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取天阙防御与审计配置（三级自愈体系：本地优先 -> 数据库灾后复原 -> 默认纯净模板）
     */
    public static function getTianqueConfig(): array
    {
        $configPath = storage_path('tianque_config.json');

        // 第一级：本地文件存在（高并发拉订阅 0 数据库压力，极速读取）
        if (file_exists($configPath)) {
            $content = @file_get_contents($configPath);
            $config = json_decode($content, true);
            if (is_array($config)) {
                return $config;
            }
        }

        // 第二级：本地文件缺失（如老站损毁换机），尝试从数据库安全备份表拉取并满血复原
        $dbConfig = self::getBackupFromDb('tianque_config');
        if (!empty($dbConfig)) {
            @file_put_contents(
                $configPath,
                json_encode($dbConfig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                LOCK_EX
            );
            return $dbConfig;
        }

        // 第三级：全新安装纯净新站，生成初始标准骨架，并同步写盘和入库作为底座
        $defaultConfig = [
            'ip_limit' => 10,
            'audit_ua_enabled' => true,
            'audit_ua_keywords' => [
                'curl', 'wget', 'python', 'python-requests', 'go-http', 'go-http-client',
                'urllib', 'httpclient', 'postman', 'aria2', 'ClashMetaForAndroid/733',
                'clash-verge/v2.3.1', 'clash'
            ],
            'banned_strategy' => 'bait',
            'banned_redirect_url' => '',
            'subconverter_enable' => true,
            'subconverter_url' => 'https://api.wcc.best/sub',
            'banned_keywords' => '',
            'replace_keyword_to' => '精品线路',
            'banned_traffic_enable' => false,
            'banned_traffic_min' => 100,
            'banned_traffic_max' => 300,
            'honeypot_users' => [],
            'honeypot_times' => (object)[],
            'flagged_users' => (object)[],
            'whitelist_users' => [],
            'banned_ips' => [],
            'ignore_ips' => [],
            'jump_servers' => []
        ];

        @file_put_contents(
            $configPath,
            json_encode($defaultConfig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );

        $parsed = json_decode(json_encode($defaultConfig), true);
        self::saveBackupToDb('tianque_config', $parsed);

        return $parsed;
    }

    /**
     * 保存天阙配置（双轨写入：本地加排他锁原子落盘 + 数据库备份持久化）
     */
    public static function saveTianqueConfig(array $config): bool
    {
        $configPath = storage_path('tianque_config.json');
        $writeResult = @file_put_contents(
            $configPath,
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );

        // 同步存入数据库保险箱，老站灾备永不丢失
        self::saveBackupToDb('tianque_config', $config);

        return $writeResult !== false;
    }

    /**
     * 保存内鬼黑名单数据（双轨写入：本地文件 + 数据库保险箱 + 刷新 Redis 缓存）
     */
    public static function saveTraitorData(array $data): bool
    {
        $traitorListPath = storage_path('traitor_list.json');
        $writeResult = @file_put_contents(
            $traitorListPath,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );

        // 同步存入数据库保险箱
        self::saveBackupToDb('traitor_list', $data);

        // 刷新 Redis 内存缓存
        self::clearCache();

        return $writeResult !== false;
    }
}

