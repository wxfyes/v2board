<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class Start extends Telegram {
    public $command = '/start';
    public $description = '欢迎与开始命令';

    public function handle($message, $match = []) {
        if (!$message->is_private) return;
        $telegramService = $this->telegramService;

        // 若携带参数，则为网站一键唤起绑定深链接: /start <token>
        if (isset($message->args[0]) && !empty(trim($message->args[0]))) {
            $bindCmd = new Bind();
            $bindCmd->handle($message, $match);
            return;
        }

        // 无参数时，检查当前发送者是否已绑定
        $user = User::where('telegram_id', $message->chat_id)->first();
        if ($user) {
            $text = "👋 *您好，欢迎使用专属服务机器人！*\n" .
                    "———————————————\n" .
                    "✅ 您的账号已成功绑定：`{$user->email}`\n\n" .
                    "📌 *常用快捷指令：*\n" .
                    "• `/traffic` - 查询剩余流量与使用明细\n" .
                    "• `/checkin` - 每日打卡签到领取免费流量 (发 签到 亦可)\n" .
                    "• `/getlatesturl` - 获取防失联最新官方访问网址\n" .
                    "• `/unbind` - 解除当前账号与 Telegram 的绑定\n\n" .
                    "💡 直接向我发送上述指令即可快速交互！";
            $telegramService->sendMessage($message->chat_id, $text, 'markdown');
        } else {
            $text = "👋 *您好！欢迎使用服务机器人*\n" .
                    "———————————————\n" .
                    "您当前尚未绑定网站账号。\n\n" .
                    "🔗 *如何绑定账号：*\n" .
                    "1. 登录网站用户中心 -> 进入【个人中心】\n" .
                    "2. 找到 Telegram 绑定卡片，点击【立即绑定】即可一键唤起自动绑定；\n" .
                    "3. 或在此处直接发送绑定指令：\n" .
                    "   `/bind 您的订阅地址或Token`\n\n" .
                    "绑定后可享受流量查询、每日打卡签到、到期预警与防失联推送！";
            $telegramService->sendMessage($message->chat_id, $text, 'markdown');
        }
    }
}
