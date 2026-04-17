<?php

namespace App\Console\Commands;

use App\Jobs\SendBobEmailJob;
use App\Models\MailLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class BobUtilMinute extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bob:util_minute';

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
        $register_time = $config['register']['time'];
        $register_users = User::where('created_at', '>=', strtotime("-{$register_time} minute"))->get();
        $register_users->each(function ($user) use ($config) {
            $this->info("已发送注册未下单邮件提醒：".$user->email);
            if (Order::query()->where(['user_id' => $user->id, 'status' => 3])->doesntExist()) {
                $this->sendMail($user->email, $config['register']['title'], $config['register']['content']);
            }
        });

        $unpaid_time = $config['unpaid']['time'];
        $unpaid_orders = Order::where('created_at', '>=', strtotime("-{$unpaid_time} minute"))
            ->where('status', 0)
            ->get();
        $unpaid_orders->each(function ($order) use ($config){
            $email = User::query()->where('id', $order->user_id)->value('email');
            $this->info("已发送订单已创建未付款邮件提醒：".$email);
            $this->sendMail($email, $config['unpaid']['title'], $config['unpaid']['content']);
        });
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
