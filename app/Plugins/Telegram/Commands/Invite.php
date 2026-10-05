<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;

class Invite extends Telegram {
    public $command = '/invite';
    public $description = '查看专属邀请短链与返利统计';

    public function handle($message, $match = []) {
        $telegramService = $this->telegramService;
        if (!$message->is_private) return;

        $user = User::where('telegram_id', $message->chat_id)->first();
        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown');
            return;
        }

        // 1. 获取用户有效未失效的邀请码，若无则自动生成
        $inviteCode = InviteCode::where('user_id', $user->id)->where('status', 0)->first();
        if (!$inviteCode) {
            $inviteCode = new InviteCode();
            $inviteCode->user_id = $user->id;
            $inviteCode->code = Helper::randomChar(8);
            $inviteCode->status = 0;
            $inviteCode->save();
        }

        $code = $inviteCode->code;

        // 2. 获取专属邀请短链根域名并构建邀请短链
        $customDomain = $this->getCustomInviteDomain();
        if (!empty($customDomain)) {
            // 清理可能存在的前缀协议与末尾斜杠
            $cleanDomain = preg_replace('#^https?://#i', '', $customDomain);
            $cleanDomain = rtrim($cleanDomain, '/');

            if (str_starts_with(strtolower($cleanDomain), 'www.')) {
                // 若显式为 www 开头，使用标准哈希路由
                $inviteUrl = "https://{$cleanDomain}/#/register?code={$code}";
            } else {
                // 以邀请码开头组成的二级域名短链: https://[邀请码].[根域名]
                $inviteUrl = "https://{$code}.{$cleanDomain}";
            }
        } else {
            // 未配置短链根域名时回退为站点默认标准注册链接
            $appUrl = rtrim(config('v2board.app_url') ?: config('app.url'), '/');
            $inviteUrl = "{$appUrl}/#/register?code={$code}";
        }

        // 3. 计算返利与统计数据
        $commissionRate = (int)($user->commission_rate ?: config('v2board.invite_commission', 10));
        $inviteCount = User::where('invite_user_id', $user->id)->count();
        $totalCommission = number_format(CommissionLog::where('invite_user_id', $user->id)->sum('get_amount') / 100, 2);
        $balance = number_format(($user->commission_balance ?? 0) / 100, 2);

        // 4. 发送美观排版消息 (代码块单行点击一键复制)
        $text = "🎁 *您的专属推广邀请*\n" .
                "———————————————\n" .
                "🔑 *专属邀请码：* `{$code}`\n" .
                "🔗 *专属邀请链接：*\n" .
                "`{$inviteUrl}`\n\n" .
                "📊 *推广与返利数据：*\n" .
                "• 返佣比例：`{$commissionRate}%`\n" .
                "• 累计邀请：`{$inviteCount} 人`\n" .
                "• 累计获赠佣金：`¥{$totalCommission}`\n" .
                "• 当前可用佣金：`¥{$balance}`\n\n" .
                "💡 _提示：好友通过您的专属二级域名链接访问，将自动跳转至注册页面并锁定邀请码返利！_";

        $telegramService->sendMessage($message->chat_id, $text, 'markdown');
    }

    /**
     * 智能获取站长配置的专属邀请短链根域名
     */
    private function getCustomInviteDomain(): string
    {
        // 优先从当前启用主题配置中获取
        $activeTheme = config('v2board.frontend_theme', 'v2nexus');
        $themeDomain = config("theme.{$activeTheme}.custom_invite_domain");
        if (!empty($themeDomain)) {
            return trim($themeDomain);
        }

        // 尝试从 v2nexus 或 ez 的已存主题配置读取
        foreach (['v2nexus', 'ez', 'default'] as $th) {
            $d = config("theme.{$th}.custom_invite_domain");
            if (!empty($d)) return trim($d);

            $configFile = base_path("config/theme/{$th}.php");
            if (file_exists($configFile)) {
                $savedConfig = include $configFile;
                if (is_array($savedConfig) && !empty($savedConfig['custom_invite_domain'])) {
                    return trim($savedConfig['custom_invite_domain']);
                }
            }
        }

        // 尝试从 v2board 全局配置或系统环境读取
        if (!empty(config('v2board.custom_invite_domain'))) {
            return trim(config('v2board.custom_invite_domain'));
        }
        if (!empty(env('CUSTOM_INVITE_DOMAIN'))) {
            return trim(env('CUSTOM_INVITE_DOMAIN'));
        }

        return '';
    }
}
