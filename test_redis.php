<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$scores = \Illuminate\Support\Facades\Redis::zrevrange('sub_risk_scores', 0, -1, 'WITHSCORES');
var_dump($scores);
