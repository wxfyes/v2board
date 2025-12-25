<?php

namespace App\Console\Commands;

use App\Jobs\SendBobEmailJob;
use App\Models\MailLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class BobUtilDay extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bob:util_day';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'bob scheduled inspection tasks~';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $config = config('bobutil');
        $start_7day = strtotime(date('Y-m-d 00:00:00', strtotime('+7 day')));
        $end_7day = strtotime(date('Y-m-d 23:59:59', strtotime('+7 day')));
        $expired_7day_users = User::query()
            ->where('expired_at', '>', $start_7day)
            ->where('expired_at', '<=', $end_7day)
            ->get();
        $expired_7day_users->each(function ($user) use ($config) {
            $this->info("已发送用户还有7天到期邮件提醒：" . $user->email);
            $this->sendMail($user->email, $config['user_expire'][0]['title'], $config['user_expire'][0]['content']);
        });

        $start_1day = strtotime(date('Y-m-d 00:00:00', strtotime('+1 day')));
        $end_1day = strtotime(date('Y-m-d 23:59:59', strtotime('+1 day')));
        $expired_1day_users = User::query()
            ->where('expired_at', '>', $start_1day)
            ->where('expired_at', '<=', $end_1day)
            ->get();
        $expired_1day_users->each(function ($user) use ($config) {
            $this->info("已发送用户还有1天到期邮件提醒：" . $user->email);
            $this->sendMail($user->email, $config['user_expire'][1]['title'], $config['user_expire'][1]['content']);
        });

        $flow_out_users = User::query()->whereRaw('u + d > transfer_enable')->get();
        $flow_out_users->each(function ($user) use ($config) {
            $log = MailLog::where(['email' => $user->email, 'subject' => $config['flow_out']['title']])->doesntExist();
            if ($log) {
                $this->info("已发送用户流量已用尽邮件提醒：" . $user->email);
                $this->sendMail($user->email, $config['flow_out']['title'], $config['flow_out']['content']);
            }
        });

        // 用户已过期召回（根据配置动态处理多个时间点）
        foreach ($config['user_expired'] as $expiredConfig) {
            $days = $expiredConfig['days'] ?? 7;
            $start = strtotime(date('Y-m-d 00:00:00', strtotime("-{$days} day")));
            $end = strtotime(date('Y-m-d 23:59:59', strtotime("-{$days} day")));
            $expiredUsers = User::query()
                ->where('expired_at', '>', $start)
                ->where('expired_at', '<=', $end)
                ->get();
            $expiredUsers->each(function ($user) use ($expiredConfig, $days) {
                $this->info("已发送用户已过期{$days}天召回邮件提醒：" . $user->email);
                $this->sendMail($user->email, $expiredConfig['title'], $expiredConfig['content']);
            });
        }
    }

    private function sendMail($email, $subject, $content)
    {
        if (config('bobutil.mail')) {
            Config::set('mail.host', config('bobutil.mail.host', env('mail.host')));
            Config::set('mail.port', config('bobutil.mail.port', env('mail.port')));
            Config::set('mail.encryption', config('bobutil.mail.encryption', env('mail.encryption')));
            Config::set('mail.username', config('bobutil.mail.username', env('mail.username')));
            Config::set('mail.password', config('bobutil.mail.password', env('mail.password')));
            Config::set('mail.from.address', config('bobutil.mail.from_address', env('mail.from.address')));
            Config::set('mail.from.name', config('v2board.app_name', 'V2Board'));
        }
        $params = [
            'template_name' => 'notify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'content' => $content
            ]
        ];

        $params['template_name'] = 'mail.' . config('v2board.email_template', 'default') . '.' . $params['template_name'];
        try {
            Mail::send(
                $params['template_name'],
                $params['template_value'],
                function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                }
            );
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        $log = [
            'email' => $email,
            'subject' => $subject,
            'template_name' => $params['template_name'],
            'error' => isset($error) ? $error : NULL
        ];

        MailLog::create($log);
        $log['config'] = config('mail');
        return $log;
    }
}
