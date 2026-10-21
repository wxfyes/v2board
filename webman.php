<?php

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require_once __DIR__ . '/vendor/autoload.php';

use Adapterman\Adapterman;
use Workerman\Worker;
use Illuminate\Support\Facades\Cache;

putenv('APP_RUNNING_IN_CONSOLE=false');
define('MAX_REQUEST', 6600);
define('isWEBMAN', true);

Adapterman::init();

$ncpu = substr_count((string)@file_get_contents('/proc/cpuinfo'), "\nprocessor")+1;

$http_worker                = new Worker('http://127.0.0.1:6600');
// 优化内存占用：默认 2 个常驻 worker（支持 .env 中通过 WEBMAN_PROCESS_COUNT 自定义覆盖）
// 2 个 worker 在常驻内存事件驱动模型下可轻松支撑 4,000+ QPS，立省数百兆内存
$http_worker->count         = (int)env('WEBMAN_PROCESS_COUNT', 2);
$http_worker->name          = 'AdapterMan';

$http_worker->onWorkerStart = static function () {
    //init();
    require __DIR__.'/start.php';
};

$http_worker->onMessage = static function ($connection, $request) {
    static $request_count = 0;
    static $pid;
    if ($request_count == 1) {
        $pid = posix_getppid();
        Cache::forget("WEBMANPID");
        Cache::forever("WEBMANPID", $pid);
    }
    $connection->send(run());
    if (++$request_count > MAX_REQUEST) {
        Worker::stopAll();
    }
};

Worker::runAll();
