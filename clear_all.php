<?php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$env = file_get_contents(__DIR__ . '/.env');
preg_match('/REDIS_PASSWORD=(.*)/', $env, $matches);
$password = trim($matches[1] ?? '');
if ($password && $password !== 'null') {
    $redis->auth($password);
}

$keys = $redis->keys('*sub_risk_*');
$count = 0;
if (!empty($keys)) {
    // 分批次删除，突破数量限制
    $chunks = array_chunk($keys, 1000);
    foreach ($chunks as $chunk) {
        $redis->del($chunk);
        $count += count($chunk);
    }
}

echo "\n====================================\n";
echo "清理完成！共删除了 {$count} 个风控脏数据缓存！\n";
echo "现在系统积分已经彻底清零归位。\n";
echo "====================================\n\n";
