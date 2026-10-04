<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class Bind extends Telegram {
    public $command = '/bind';
    public $description = '将Telegram账号绑定到网站';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        if (!isset($message->args[0])) {
            abort(500, '参数有误，请携带订阅地址发送');
        }
        $rawInput = trim($message->args[0] ?? '');
        $token = '';
        if (strpos($rawInput, 'http://') === 0 || strpos($rawInput, 'https://') === 0) {
            $parsed = parse_url($rawInput);
            if (!empty($parsed['query'])) {
                parse_str($parsed['query'], $query);
                $token = $query['token'] ?? '';
            }
        } else {
            $token = $rawInput;
        }
        if (!$token) {
            abort(500, '参数有误，请发送正确的订阅地址或绑定 Token');
        }
        $submethod = (int)config('v2board.show_subscribe_method', 0);
        switch ($submethod) {
            case 0:
                break;
            case 1:
                if (!Cache::has("otpn_{$token}")) {
                    abort(403, 'token is error');
                }
                $usertoken = Cache::get("otpn_{$token}");
                $token = $usertoken;
                break;
            case 2:
                $usertoken = Cache::get("totp_{$token}");
                if (!$usertoken) {
                    $timestep = (int)config('v2board.show_subscribe_expire', 5) * 60;
                    $counter = floor(time() / $timestep);
                    $counterBytes = pack('N*', 0) . pack('N*', $counter);
                    $idhash = Helper::base64DecodeUrlSafe($token);
                    $parts = explode(':', $idhash, 2);
                    [$userid, $clienthash] = $parts;
                    if (!$userid || !$clienthash) {
                        abort(403, 'token is error');
                    }
                    $user = User::where('id', $userid)->select('token')->first();
                    if (!$user) {
                        abort(403, 'token is error');
                    }
                    $usertoken = $user->token;
                    $hash = hash_hmac('sha1', $counterBytes, $usertoken, false);
                    if ($clienthash !== $hash) {
                        abort(403, 'token is error');
                    }
                    Cache::put("totp_{$token}", $usertoken, $timestep);
                }
                $token = $usertoken;
                break;
            default:
                break;
        }
        $user = User::where('token', $token)->first();
        if (!$user) {
            abort(500, '用户不存在或 Token 无效');
        }
        if ($user->telegram_id) {
            abort(500, '该账号已经绑定了 Telegram 账号');
        }
        $user->telegram_id = $message->chat_id;
        if (!$user->save()) {
            abort(500, '设置失败');
        }
        $telegramService = $this->telegramService;
        $successText = "🎉 *Telegram 账号绑定成功！*\n" .
                       "———————————————\n" .
                       "👤 绑定账号：`{$user->email}`\n\n" .
                       "📌 *您现在可以随时向我发送：*\n" .
                       "• `/traffic` - 查询实时流量与用量明细\n" .
                       "• `/checkin` - 每日打卡签到领取免费流量 (发 签到 亦可)\n" .
                       "• `/getlatesturl` - 防失联获取最新可用官网\n" .
                       "• `/unbind` - 解除账号绑定\n\n" .
                       "祝您使用愉快！";
        $telegramService->sendMessage($message->chat_id, $successText, 'markdown');
    }
}
