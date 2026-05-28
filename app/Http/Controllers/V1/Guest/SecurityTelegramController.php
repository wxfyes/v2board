<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class SecurityTelegramController extends Controller
{
    public function webhook(Request $request)
    {
        $data = $request->input();
        if (!isset($data['callback_query'])) {
            return response()->json(['status' => 'ok']);
        }

        $callbackQuery = $data['callback_query'];
        $callbackQueryId = $callbackQuery['id'];
        $callbackData = $callbackQuery['data'];
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $fromId = $callbackQuery['from']['id'];
        $originalText = $callbackQuery['message']['text'] ?? '';

        // 读取 .env 中的安全配置
        $adminChatId = env('SECURITY_TG_CHAT');
        $botToken = env('SECURITY_TG_TOKEN');

        // 兼容配置缓存
        if (empty($adminChatId) || empty($botToken)) {
            $envPath = base_path('.env');
            if (file_exists($envPath)) {
                $envContent = @file_get_contents($envPath);
                if (!empty($envContent)) {
                    if (empty($adminChatId) && preg_match('/^SECURITY_TG_CHAT\s*=\s*(.*)$/m', $envContent, $matches)) {
                        $adminChatId = trim($matches[1], "\"' ");
                    }
                    if (empty($botToken) && preg_match('/^SECURITY_TG_TOKEN\s*=\s*(.*)$/m', $envContent, $matches)) {
                        $botToken = trim($matches[1], "\"' ");
                    }
                }
            }
        }

        // 安全校验：只有配置好的管理员 ID 才可以点击操作
        if ((string)$fromId !== (string)$adminChatId) {
            $this->answerCallbackQuery($botToken, $callbackQueryId, "⚠️ 越权警告：你不是该系统的授权管理员！");
            return response()->json(['status' => 'unauthorized']);
        }

        // 解析动作和用户ID，格式为: action:userId
        $parts = explode(':', $callbackData);
        if (count($parts) !== 2) {
            $this->answerCallbackQuery($botToken, $callbackQueryId, "❌ 无效的数据格式");
            return response()->json(['status' => 'invalid_data']);
        }

        $action = $parts[0];
        $userId = (int)$parts[1];

        $user = User::find($userId);
        if (!$user) {
            $this->answerCallbackQuery($botToken, $callbackQueryId, "❌ 未找到该用户 (ID: {$userId})");
            return response()->json(['status' => 'user_not_found']);
        }

        $actionResultStr = '';
        switch ($action) {
            case 'honeypot':
                $configPath = storage_path('tianque_config.json');
                $config = [];
                if (file_exists($configPath)) {
                    $config = json_decode(@file_get_contents($configPath), true) ?: [];
                }
                if (!isset($config['honeypot_users']) || !is_array($config['honeypot_users'])) {
                    $config['honeypot_users'] = [];
                }
                $currentHoneypots = array_map('intval', $config['honeypot_users']);
                if (!in_array($userId, $currentHoneypots, true)) {
                    $currentHoneypots[] = $userId;
                    $config['honeypot_users'] = $currentHoneypots;
                    @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
                $actionResultStr = "【已成功移入天阙蜜罐】";
                break;

            case 'ban':
                $user->banned = 1;
                $user->save();
                $actionResultStr = "【已封禁账号】";
                break;

            case 'reset':
                $user->token = $this->generateGuid();
                $user->uuid = $this->generateGuid(false);
                $user->save();
                $actionResultStr = "【已重置订阅 Token/UUID】";
                break;

            default:
                $this->answerCallbackQuery($botToken, $callbackQueryId, "❌ 未知操作");
                return response()->json(['status' => 'unknown_action']);
        }

        // 提示管理员操作成功
        $this->answerCallbackQuery($botToken, $callbackQueryId, "✅ 操作成功: {$actionResultStr}");

        // 修改原始电报消息，更新执行动作状态
        $newText = $originalText;
        if (preg_match('/⚙️ 执行动作:.*$/m', $newText)) {
            $newText = preg_replace('/⚙️ 执行动作:.*$/m', "⚙️ 执行动作: {$actionResultStr} (管理员 via TG)", $newText);
        } elseif (preg_match('/执行动作:.*$/m', $newText)) {
            $newText = preg_replace('/执行动作:.*$/m', "执行动作: {$actionResultStr} (管理员 via TG)", $newText);
        } else {
            $newText .= "\n⚙️ 执行动作: {$actionResultStr} (管理员 via TG)";
        }

        $this->editMessageText($botToken, $chatId, $messageId, $newText);

        return response()->json(['status' => 'ok']);
    }

    private function answerCallbackQuery($botToken, $callbackQueryId, $text)
    {
        $url = "https://api.telegram.org/bot{$botToken}/answerCallbackQuery";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt_array($ch, [
            CURLOPT_POSTFIELDS => http_build_query([
                'callback_query_id' => $callbackQueryId,
                'text' => $text,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function editMessageText($botToken, $chatId, $messageId, $text)
    {
        $url = "https://api.telegram.org/bot{$botToken}/editMessageText";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt_array($ch, [
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function generateGuid($trim = true)
    {
        $guid = sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
        return $trim ? str_replace('-', '', strtolower($guid)) : strtolower($guid);
    }
}
