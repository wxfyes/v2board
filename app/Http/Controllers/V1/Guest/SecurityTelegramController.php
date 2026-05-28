<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class SecurityTelegramController extends Controller
{
    public function webhook(Request $request)
    {
        $data = $request->input();

        // 写入审计日志以供调试
        Log::info('TianQue Security Webhook Payload:', [
            'ip' => $request->ip(),
            'data' => $data
        ]);

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

        // 处理普通文本命令 (如: /check 或 /scan)
        if (isset($data['message'])) {
            $message = $data['message'];
            $chatId = $message['chat']['id'];
            $fromId = $message['from']['id'];
            $text = trim($message['text'] ?? '');

            // 安全校验：只有配置好的管理员才可以使用指令
            if (!$this->isUserAuthorized($botToken, $adminChatId, $fromId, $chatId)) {
                Log::warning("TianQue Security Webhook: Unauthorized command access attempt.", [
                    'from_id' => $fromId,
                    'chat_id' => $chatId,
                    'admin_chat_id' => $adminChatId
                ]);
                return response()->json(['status' => 'unauthorized']);
            }

            if ($text === '/check' || $text === '/scan' || $text === '/start') {
                $this->sendMessage($botToken, $chatId, "🔍 正在为您启动【天阙订阅安全审计扫描】，请稍候...");
                
                try {
                    // 调用 Artisan 命令行执行扫描
                    Artisan::call('v2board:detect-subscribers');
                    $this->sendMessage($botToken, $chatId, "✅ 审计扫描执行已结束！如有新捕获的异常用户，会在上方收到预警卡片。");
                } catch (\Exception $e) {
                    $this->sendMessage($botToken, $chatId, "❌ 扫描执行失败: " . $e->getMessage());
                }
            } elseif (strpos($text, '/whitelist ') === 0) {
                $param = trim(substr($text, 11));
                if (empty($param)) {
                    $this->sendMessage($botToken, $chatId, "⚠️ 格式错误，请使用: `/whitelist <ID或邮箱>`");
                    return response()->json(['status' => 'ok']);
                }

                $configPath = storage_path('tianque_config.json');
                $config = [];
                if (file_exists($configPath)) {
                    $config = json_decode(@file_get_contents($configPath), true) ?: [];
                }
                if (!isset($config['whitelist_users']) || !is_array($config['whitelist_users'])) {
                    $config['whitelist_users'] = [];
                }

                $val = is_numeric($param) ? (int)$param : $param;
                if (!in_array($val, $config['whitelist_users'], true)) {
                    $config['whitelist_users'][] = $val;
                    @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    $this->sendMessage($botToken, $chatId, "✅ 已成功将 `{$param}` 加入白名单，后续扫描将完全跳过该用户。");
                } else {
                    $this->sendMessage($botToken, $chatId, "ℹ️ 用户 `{$param}` 已经在白名单中。");
                }
            } elseif (strpos($text, '/unwhitelist ') === 0) {
                $param = trim(substr($text, 13));
                if (empty($param)) {
                    $this->sendMessage($botToken, $chatId, "⚠️ 格式错误，请使用: `/unwhitelist <ID或邮箱>`");
                    return response()->json(['status' => 'ok']);
                }

                $configPath = storage_path('tianque_config.json');
                $config = [];
                if (file_exists($configPath)) {
                    $config = json_decode(@file_get_contents($configPath), true) ?: [];
                }
                if (isset($config['whitelist_users']) && is_array($config['whitelist_users'])) {
                    $val = is_numeric($param) ? (int)$param : $param;
                    $key = array_search($val, $config['whitelist_users'], true);
                    if ($key !== false) {
                        unset($config['whitelist_users'][$key]);
                        $config['whitelist_users'] = array_values($config['whitelist_users']);
                        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                        $this->sendMessage($botToken, $chatId, "✅ 已成功将 `{$param}` 从白名单中移除。");
                    } else {
                        $this->sendMessage($botToken, $chatId, "ℹ️ 白名单中未找到用户 `{$param}`。");
                    }
                } else {
                    $this->sendMessage($botToken, $chatId, "ℹ️ 白名单中未找到用户 `{$param}`。");
                }
            } elseif ($text === '/honeypots' || $text === '/honeypotlist') {
                $configPath = storage_path('tianque_config.json');
                $honeypotUsers = [];
                if (file_exists($configPath)) {
                    $tianqueConfig = json_decode(@file_get_contents($configPath), true);
                    if (is_array($tianqueConfig) && isset($tianqueConfig['honeypot_users'])) {
                        $honeypotUsers = array_map('intval', $tianqueConfig['honeypot_users']);
                    }
                }

                if (empty($honeypotUsers)) {
                    $this->sendMessage($botToken, $chatId, "🍯 当前天阙蜜罐名单为空。");
                    return response()->json(['status' => 'ok']);
                }

                // 从数据库查询对应的用户信息
                $users = User::whereIn('id', $honeypotUsers)->get(['id', 'email']);
                $listStr = '';
                foreach ($honeypotUsers as $uid) {
                    $matchedUser = $users->firstWhere('id', $uid);
                    $email = $matchedUser ? $matchedUser->email : '未知邮箱或已被删除';
                    $listStr .= "• ID: `{$uid}` | 邮箱: `{$email}`\n";
                }

                $msg = "🍯 **「天阙」当前蜜罐名单 (共 " . count($honeypotUsers) . " 人)**:\n\n" . $listStr;
                $this->sendMessage($botToken, $chatId, $msg);
            } elseif ($text === '/whitelists' || $text === '/whitelistlist') {
                $configPath = storage_path('tianque_config.json');
                $whitelistUsers = [];
                if (file_exists($configPath)) {
                    $tianqueConfig = json_decode(@file_get_contents($configPath), true);
                    if (is_array($tianqueConfig) && isset($tianqueConfig['whitelist_users'])) {
                        $whitelistUsers = $tianqueConfig['whitelist_users'];
                    }
                }

                if (empty($whitelistUsers)) {
                    $this->sendMessage($botToken, $chatId, "🛡️ 当前审计白名单为空。");
                    return response()->json(['status' => 'ok']);
                }

                $listStr = '';
                foreach ($whitelistUsers as $item) {
                    $listStr .= "• `{$item}`\n";
                }

                $msg = "🛡️ **「天阙」当前白名单 (共 " . count($whitelistUsers) . " 人)**:\n\n" . $listStr;
                $this->sendMessage($botToken, $chatId, $msg);
            } elseif ($text === '/observed' || $text === '/flagged' || $text === '/flaggedlist') {
                $configPath = storage_path('tianque_config.json');
                $flaggedUsers = [];
                if (file_exists($configPath)) {
                    $tianqueConfig = json_decode(@file_get_contents($configPath), true);
                    if (is_array($tianqueConfig) && isset($tianqueConfig['flagged_users'])) {
                        $flaggedUsers = $tianqueConfig['flagged_users'];
                    }
                }

                if (empty($flaggedUsers)) {
                    $this->sendMessage($botToken, $chatId, "📋 当前「重点观察名单」为空，无异常用户需要审查。");
                    return response()->json(['status' => 'ok']);
                }

                $listStr = '';
                foreach ($flaggedUsers as $uid => $info) {
                    $email = $info['email'] ?? '未知邮箱';
                    $flagTime = isset($info['time']) ? date('Y-m-d H:i:s', $info['time']) : '未知时间';
                    $reasons = isset($info['reasons']) && is_array($info['reasons']) ? implode(' | ', $info['reasons']) : '未知触发规则';
                    $listStr .= "• **ID**: `{$uid}`\n"
                              . "  邮箱: `{$email}`\n"
                              . "  标记时间: `{$flagTime}`\n"
                              . "  触发规则: `{$reasons}`\n\n";
                }

                $msg = "📋 **「天阙」当前重点观察名单 (共 " . count($flaggedUsers) . " 人)**:\n\n"
                     . $listStr
                     . "💡 _您可以使用 `/whitelist <ID>` 放行他们，或使用 `/clearflag <ID>` 仅将其从观察名单移出。_";
                $this->sendMessage($botToken, $chatId, $msg);
            } elseif (strpos($text, '/clearflag ') === 0) {
                $param = trim(substr($text, 11));
                if (empty($param)) {
                    $this->sendMessage($botToken, $chatId, "⚠️ 格式错误，请使用: `/clearflag <ID或邮箱>`");
                    return response()->json(['status' => 'ok']);
                }

                $configPath = storage_path('tianque_config.json');
                $config = [];
                if (file_exists($configPath)) {
                    $config = json_decode(@file_get_contents($configPath), true) ?: [];
                }
                if (isset($config['flagged_users']) && is_array($config['flagged_users'])) {
                    $found = false;
                    if (isset($config['flagged_users'][(string)$param])) {
                        unset($config['flagged_users'][(string)$param]);
                        $found = true;
                    } else {
                        foreach ($config['flagged_users'] as $uid => $info) {
                            if (strtolower($info['email'] ?? '') === strtolower($param)) {
                                unset($config['flagged_users'][$uid]);
                                $found = true;
                                break;
                            }
                        }
                    }

                    if ($found) {
                        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                        $this->sendMessage($botToken, $chatId, "✅ 已成功将 `{$param}` 从重点观察名单中移出。");
                    } else {
                        $this->sendMessage($botToken, $chatId, "ℹ️ 观察名单中未找到用户 `{$param}`。");
                    }
                } else {
                    $this->sendMessage($botToken, $chatId, "ℹ️ 观察名单中未找到用户 `{$param}`。");
                }
            }
            return response()->json(['status' => 'ok']);
        }

        // 处理按钮回调消息
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

        // 安全校验：只有配置好的管理员 ID 才可以点击操作
        if (!$this->isUserAuthorized($botToken, $adminChatId, $fromId, $chatId)) {
            Log::warning("TianQue Security Webhook: Unauthorized callback action attempt.", [
                'from_id' => $fromId,
                'chat_id' => $chatId,
                'admin_chat_id' => $adminChatId
            ]);
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
                $actionResultStr = "【已移入天阙蜜罐】";
                break;

            case 'unhoneypot':
                $configPath = storage_path('tianque_config.json');
                $config = [];
                if (file_exists($configPath)) {
                    $config = json_decode(@file_get_contents($configPath), true) ?: [];
                }
                if (isset($config['honeypot_users']) && is_array($config['honeypot_users'])) {
                    $currentHoneypots = array_map('intval', $config['honeypot_users']);
                    $key = array_search($userId, $currentHoneypots, true);
                    if ($key !== false) {
                        unset($currentHoneypots[$key]);
                        $config['honeypot_users'] = array_values($currentHoneypots);
                        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    }
                }
                $actionResultStr = "【已从蜜罐中移出】";
                break;

            case 'ban':
                $user->banned = 1;
                $user->save();
                $actionResultStr = "【已封禁账号】";
                break;

            case 'unban':
                $user->banned = 0;
                $user->save();
                $actionResultStr = "【已解除封禁】";
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

        // --------------------------------------------------
        // 从重点观察名单中移除已处置用户
        // --------------------------------------------------
        $configPath = storage_path('tianque_config.json');
        if (file_exists($configPath)) {
            $config = json_decode(@file_get_contents($configPath), true) ?: [];
            if (isset($config['flagged_users']) && is_array($config['flagged_users'])) {
                if (isset($config['flagged_users'][(string)$userId])) {
                    unset($config['flagged_users'][(string)$userId]);
                    @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
            }
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

        // 动态查询最新的用户状态，重新生成操作按钮以完成切换
        $newInHoneypot = false;
        $configPath = storage_path('tianque_config.json');
        if (file_exists($configPath)) {
            $config = json_decode(@file_get_contents($configPath), true) ?: [];
            $honeypots = array_map('intval', $config['honeypot_users'] ?? []);
            if (in_array($userId, $honeypots, true)) {
                $newInHoneypot = true;
            }
        }
        $newIsBanned = (bool)$user->banned;

        $newKeyboard = [
            'inline_keyboard' => [
                [
                    $newInHoneypot 
                        ? ['text' => '↩️ 移出蜜罐', 'callback_data' => "unhoneypot:{$userId}"]
                        : ['text' => '🛡️ 放入蜜罐', 'callback_data' => "honeypot:{$userId}"],
                    $newIsBanned
                        ? ['text' => '🟢 解除封禁', 'callback_data' => "unban:{$userId}"]
                        : ['text' => '🚫 封禁账号', 'callback_data' => "ban:{$userId}"],
                    ['text' => '🔄 重置订阅', 'callback_data' => "reset:{$userId}"]
                ]
            ]
        ];

        $this->editMessageText($botToken, $chatId, $messageId, $newText, $newKeyboard);

        return response()->json(['status' => 'ok']);
    }

    /**
     * 判断发起指令或点击按钮的用户是否已被授权
     */
    private function isUserAuthorized($botToken, $adminChatId, $fromId, $chatId)
    {
        // 1. 直连匹配：如果管理员 ID 就是用户的个人 Chat ID
        if ((string)$fromId === (string)$adminChatId) {
            return true;
        }

        // 2. 群组内匹配：如果在允许的群组里直接发送命令
        if ((string)$chatId === (string)$adminChatId) {
            return true;
        }

        // 3. 跨群验证：如果在私聊里说话，但管理员配置的是群组 ID
        if (strpos((string)$adminChatId, '-') === 0) {
            $url = "https://api.telegram.org/bot{$botToken}/getChatMember";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt_array($ch, [
                CURLOPT_POSTFIELDS => http_build_query([
                    'chat_id' => $adminChatId,
                    'user_id' => $fromId,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5
            ]);
            $res = curl_exec($ch);
            curl_close($ch);

            $response = json_decode($res, true);
            if (isset($response['ok']) && $response['ok'] === true) {
                $status = $response['result']['status'] ?? '';
                // 只要是群主、管理员或群内成员，就允许在私聊中使用
                if (in_array($status, ['creator', 'administrator', 'member'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sendMessage($botToken, $chatId, $text)
    {
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt_array($ch, [
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $chatId,
                'text' => $text,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5
        ]);
        curl_exec($ch);
        curl_close($ch);
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

    private function editMessageText($botToken, $chatId, $messageId, $text, $replyMarkup = null)
    {
        $url = "https://api.telegram.org/bot{$botToken}/editMessageText";
        $postData = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];
        if ($replyMarkup !== null) {
            $postData['reply_markup'] = json_encode($replyMarkup);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt_array($ch, [
            CURLOPT_POSTFIELDS => http_build_query($postData),
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
