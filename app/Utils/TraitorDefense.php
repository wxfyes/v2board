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
     * 检查并自动将命中的内鬼用户加入蜜罐
     *
     * @param \App\Models\User $user 用户模型
     * @param string $ip 客户端真实 IP
     * @param string $userAgent 客户端 UA
     * @param string $action 触发动作 (如 '注册', '登录', '第三方登录')
     * @return void
     */
    public static function checkAndHoneypot($user, string $ip, string $userAgent = 'unknown', string $action = '注册')
    {
        try {
            $traitorData = self::getTraitorData();
            $traitorEmails = $traitorData['emails'] ?? [];
            $traitorIps = $traitorData['ips'] ?? [];

            // 若黑名单为空，极速退出（0 开销）
            if (empty($traitorEmails) && empty($traitorIps)) {
                return;
            }

            $userEmail = strtolower($user->email ?? '');
            $isTraitorEmail = !empty($traitorEmails) && in_array($userEmail, $traitorEmails, true);
            $isTraitorIp = !empty($traitorIps) && in_array($ip, $traitorIps, true);

            if ($isTraitorEmail || $isTraitorIp) {
                // 命中内鬼特征
                $reason = [];
                if ($isTraitorEmail) $reason[] = "邮箱在黑名单中";
                if ($isTraitorIp) $reason[] = "IP在黑名单中";
                $reasonStr = implode("，", $reason);

                self::putIntoHoneypot($user, $ip, $userAgent, $action, $reasonStr);
            }
        } catch (\Throwable $e) {
            Log::channel('risk')->error('[内鬼防御] 检测异常', ['error' => $e->getMessage()]);
        }
    }

    private static function putIntoHoneypot($user, string $ip, string $userAgent, string $action, string $reasonStr)
    {
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

        $userId = (int)$user->id;
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
            'email' => $user->email,
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
                $authService = new \App\Services\AuthService($user);
                $authService->removeAllSession();
            } catch (\Throwable $ex) {}

            Log::channel('risk')->warning('[主动防御] 用户已自动导入蜜罐（黑名单触发）', [
                'user_id' => $userId,
                'email'   => $user->email,
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

            $text = implode("\n", [
                "🚨 主动防御告警 (黑名单触发)",
                "━━━━━━━━━━━━",
                "👤 {$user->email}（ID: {$user->id}）",
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

