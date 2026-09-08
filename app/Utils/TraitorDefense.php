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

            // 修复：检查写入是否成功。如果没权限写入，就不要发 TG 报警，否则会无限发导致卡死
            $writeResult = @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            
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

                // 发送 TG 告警 (移交给异步任务或缩短超时时间)
                self::sendTelegramAlert($user, $ip, $userAgent, $action, $reasonStr);
            } else {
                Log::channel('risk')->error('[内鬼防御] tianque_config.json 写入失败，请检查 storage 目录及文件读写权限！');
            }
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
            "🍯 状态：已第一时间打入蜜罐",
            "🕐 " . date('Y-m-d H:i:s'),
        ]);

        // 修复：将发 TG 改为极短的 1 秒超时，且捕获所有异常，彻底防止 API 阻断导致 PHP 卡死
        try {
            Http::timeout(1)->post(
                'https://api.telegram.org/bot' . trim($botToken) . '/sendMessage',
                [
                    'chat_id' => trim($chatId),
                    'text'    => $text
                ]
            );
        } catch (\Throwable $e) {}
    }
}
