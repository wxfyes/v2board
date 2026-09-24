<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$scores = \Illuminate\Support\Facades\Redis::zrevrange('sub_risk_scores', 0, -1);
if (is_array($scores)) {
    foreach ($scores as $userId) {
        \Illuminate\Support\Facades\Redis::del("sub_risk_state:{$userId}");
    }
}
\Illuminate\Support\Facades\Redis::del('sub_risk_scores');
echo "All risk scores cleared!\n";
