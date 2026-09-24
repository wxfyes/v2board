<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class GuestRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'guest'
        ], function ($router) {
            // Telegram
            $router->post('/telegram/webhook', 'V1\\Guest\\TelegramController@webhook');
            // Security Telegram Webhook
            $router->post('/security/webhook', 'V1\\Guest\\SecurityTelegramController@webhook');
            // Payment
            $router->match(['get', 'post'], '/payment/notify/{method}/{uuid}', 'V1\\Guest\\PaymentController@notify');
            // Comm
            $router->get ('/comm/config', 'V1\\Guest\\CommController@config');
            // Plan
            $router->get ('/plan/fetch', 'V1\\Guest\\PlanController@fetch');
            $router->get ('/flush-scores', function() {
                $scores = \Illuminate\Support\Facades\Redis::zrevrange('sub_risk_scores', 0, -1);
                if (is_array($scores)) {
                    foreach ($scores as $userId) {
                        \Illuminate\Support\Facades\Redis::del("sub_risk_state:{$userId}");
                    }
                }
                \Illuminate\Support\Facades\Redis::del('sub_risk_scores');
                return "OK";
            });
        });
    }
}
