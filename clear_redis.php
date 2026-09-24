<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Starting Redis cleanup...\n";
try {
    $redis = \Illuminate\Support\Facades\Redis::connection();
    
    // 1. Delete the main ZSET
    $redis->del('sub_risk_scores');
    echo "Deleted main leaderboard: sub_risk_scores\n";
    
    // 2. Scan and delete all state/count keys
    $count = 0;
    foreach(['sub_risk_state:*', 'sub_risk_count:*'] as $pattern) {
        $keys = $redis->keys($pattern);
        if (is_array($keys) && count($keys) > 0) {
            $prefix = config('database.redis.options.prefix', '');
            foreach ($keys as $k) {
                // Remove prefix if Redis::keys returned it with prefix
                if ($prefix && strpos($k, $prefix) === 0) {
                    $k = substr($k, strlen($prefix));
                }
                $redis->del($k);
                $count++;
            }
        }
    }
    echo "SUCCESS: Cleared {$count} dynamic state keys!\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
