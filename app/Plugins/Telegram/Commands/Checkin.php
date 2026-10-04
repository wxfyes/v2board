<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Services\CheckinService;
use App\Utils\Helper;

class Checkin extends Telegram {
    public $command = '/checkin';
    public $description = '每日签到打卡';

    public function handle($message, $match = []) {
        $telegramService = $this->telegramService;
        if (!$message->is_private) return;
        
        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先在用户中心绑定 Telegram 账号', 'markdown');
            return;
        }

        try {
            $checkinService = new CheckinService();
            $result = $checkinService->doCheckin($user);

            // 重新拉取用户获取最新流量总量
            $freshUser = User::find($user->id);
            $gainStr = $result['traffic_gain_formatted'];
            $monthUsedStr = $result['month_used_formatted'];
            $monthLimitStr = $result['month_limit_formatted'];
            $totalRemainingStr = Helper::trafficConvert(max(0, $freshUser->transfer_enable - ($freshUser->u + $freshUser->d)));

            $text = "🎁 *每日打卡签到成功！*\n" .
                    "———————————————\n" .
                    "🎉 今日奖励：`+{$gainStr}`\n" .
                    "📊 本月累计：`{$monthUsedStr} / {$monthLimitStr}`\n" .
                    "🚥 当前剩余：`{$totalRemainingStr}`\n\n" .
                    "💡 明天记得继续来打卡哦！";
            $telegramService->sendMessage($message->chat_id, $text, 'markdown');
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $telegramService->sendMessage($message->chat_id, "⚠️ {$msg}", 'markdown');
        }
    }
}
