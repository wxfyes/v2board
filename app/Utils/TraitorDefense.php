<?php

namespace App\Utils;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class TraitorDefense
{
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
            $traitorListPath = storage_path('traitor_list.json');
            if (!file_exists($traitorListPath)) {
                return; // 没有黑名单文件直接跳过
            }

            $traitorData = json_decode(@file_get_contents($traitorListPath), true) ?: [];
            $traitorEmails = array_map('strtolower', $traitorData['emails'] ?? []);
            $traitorIps = $traitorData['ips'] ?? [];

            $isTraitorEmail = in_array(strtolower($user->email), $traitorEmails, true);
            $isTraitorIp = in_array($ip, $traitorIps, true);

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

        if (!in_array($userId, $currentHoneypots, true)) {
            // 将用户加入蜜罐名单
            $currentHoneypots[] = $userId;
            $config['honeypot_users'] = $currentHoneypots;
            $config['honeypot_times'][(string)$userId] = time();

            // 同时记录到 flagged_users 以便在安全审计中展示详细拦截原委
            $config['flagged_users'][(string)$userId] = [
                'email' => $user->email,
                'time' => time(),
                'reasons' => ["内鬼防御系统前置拦截", $reasonStr]
            ];

            @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            // 清除可能存在的 session
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

            // 发送 TG 告警
            self::sendTelegramAlert($user, $ip, $userAgent, $action, $reasonStr);
        }
    }

    private static function sendTelegramAlert($user, string $ip, string $userAgent, string $action, string $reasonStr)
    {
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
            "📱 UA：{$userAgent}",
            "🍯 状态：已第一时间打入蜜罐",
            "🕐 " . date('Y-m-d H:i:s'),
        ]);

        try {
            Http::timeout(5)->post(
                'https://api.telegram.org/bot' . trim($botToken) . '/sendMessage',
                [
                    'chat_id' => trim($chatId),
                    'text'    => $text
                ]
            );
        } catch (\Throwable $e) {}
    }
}
