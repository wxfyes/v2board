<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;

echo "==================================================\n";
echo "🔍 开始根据用户提供的特异特征扫描内鬼账号...\n";
echo "特征条件:\n";
echo " 1. 用户 ID > 10000\n";
echo " 2. 24h内拉取 UA 包含 clash-verge/733\n";
echo " 3. 24h内从 5 个及以上不同国内省份拉取\n";
echo " 4. 24h内主要使用阿里云/Alibaba 节点的 IP\n";
echo "==================================================\n\n";

// 是否自动执行恢复写入蜜罐
$autoRestore = isset($argv[1]) && $argv[1] === '--restore';

// 1. 初筛：ID > 10000 且 client_type 包含关键字以减少数据库和网络请求开销
$users = User::where('id', '>', 10000)
    ->whereNotNull('client_type')
    ->where('client_type', 'like', '%clash-verge%')
    ->where('client_type', 'like', '%733%')
    ->get(['id', 'email', 'client_type', 'banned']);

echo "ℹ️ 初筛找到可能有相关 UA 记录的用户共 " . $users->count() . " 个，开始深入精筛分析...\n\n";

$now = time();
$matchedUsers = [];

function getIpDetails($ip) {
    return \Illuminate\Support\Facades\Cache::remember('ip_detail_audit_spec_' . $ip, 86400 * 30, function() use ($ip) {
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 2]]);
            $res = @file_get_contents("http://ip-api.com/json/{$ip}?lang=zh-CN", false, $ctx);
            if ($res) {
                $data = json_decode($res, true);
                if (isset($data['status']) && $data['status'] === 'success') {
                    return [
                        'countryCode' => $data['countryCode'] ?? 'CN',
                        'country' => $data['country'] ?? '',
                        'region' => $data['regionName'] ?? '',
                        'city' => $data['city'] ?? '',
                        'isp' => strtolower(($data['isp'] ?? '') . ' ' . ($data['org'] ?? ''))
                    ];
                }
            }
        } catch (\Exception $e) {}
        return null;
    });
}

foreach ($users as $user) {
    $history = json_decode($user->client_type, true) ?: [];
    
    // 过滤出 24 小时内的拉取记录
    $logs24h = array_filter($history, function($h) use ($now) {
        return ($now - ($h['time'] ?? 0)) <= 86400;
    });
    
    if (empty($logs24h)) {
        continue;
    }
    
    // 验证 UA 条件
    $hasTargetUa = false;
    foreach ($logs24h as $log) {
        $uaLower = strtolower($log['ua'] ?? '');
        if (strpos($uaLower, 'clash-verge/733') !== false || (strpos($uaLower, 'clash-verge') !== false && strpos($uaLower, '733') !== false)) {
            $hasTargetUa = true;
            break;
        }
    }
    
    if (!$hasTargetUa) {
        continue;
    }
    
    // 提取不重复的 IP 列表
    $ips = [];
    foreach ($logs24h as $log) {
        $ip = trim($log['ip'] ?? '');
        if (!empty($ip) && $ip !== '127.0.0.1') {
            $ips[] = $ip;
        }
    }
    $uniqueIps = array_values(array_unique($ips));
    
    // 如果 24h 内独立 IP 小于 5 个，肯定无法满足 5 个省份拉取的条件
    if (count($uniqueIps) < 5) {
        continue;
    }
    
    // 逐个查询 IP 的省份和 ISP 特征
    $regions = [];
    $aliyunCount = 0;
    $chinaCount = 0;
    $ipDetailsList = [];
    
    foreach ($uniqueIps as $ip) {
        $details = getIpDetails($ip);
        if ($details) {
            $ipDetailsList[] = [
                'ip' => $ip,
                'region' => $details['region'],
                'city' => $details['city'],
                'isp' => $details['isp']
            ];
            
            // 判定是否是国内 IP
            if ($details['countryCode'] === 'CN' || $details['country'] === '中国') {
                $chinaCount++;
            }
            
            // 判定是否是阿里云 IP
            if (strpos($details['isp'], 'alibaba') !== false || strpos($details['isp'], 'aliyun') !== false || strpos($details['isp'], '阿里云') !== false) {
                $aliyunCount++;
            }
            
            if (!empty($details['region'])) {
                $regions[] = $details['region'];
            }
        }
    }
    
    $uniqueRegions = array_values(array_unique($regions));
    
    // 判断精筛条件：国内省份数量 >= 5
    if (count($uniqueRegions) >= 5) {
        $matchedUsers[] = [
            'id' => $user->id,
            'email' => $user->email,
            'banned' => $user->banned,
            'china_ip_count' => $chinaCount,
            'aliyun_ip_count' => $aliyunCount,
            'regions' => $uniqueRegions,
            'ips' => $ipDetailsList
        ];
    }
}

if (empty($matchedUsers)) {
    echo "❌ 未能扫描到完全符合该特征的用户。\n";
    exit(0);
}

echo "🎉 精筛分析完成！成功捕获到符合特异特征的嫌疑账号共 " . count($matchedUsers) . " 个：\n\n";

$restoreUserIds = [];

foreach ($matchedUsers as $item) {
    echo "--------------------------------------------------\n";
    echo "👤 用户邮箱: " . $item['email'] . " (ID: " . $item['id'] . ")\n";
    echo "   账号状态: " . ($item['banned'] ? "🚫 已封禁" : "🟢 正常") . "\n";
    echo "   国内拉取 IP 数: " . $item['china_ip_count'] . " 个\n";
    echo "   阿里云 IP 数: " . $item['aliyun_ip_count'] . " 个\n";
    echo "   24h拉取覆盖省份 (" . count($item['regions']) . " 个): " . implode(', ', $item['regions']) . "\n";
    echo "   拉取 IP 明细:\n";
    foreach ($item['ips'] as $ipInfo) {
        echo "     - " . $ipInfo['ip'] . " (" . $ipInfo['region'] . $ipInfo['city'] . ") -> ISP: " . $ipInfo['isp'] . "\n";
    }
    $restoreUserIds[] = $item['id'];
}
echo "--------------------------------------------------\n\n";

if ($autoRestore) {
    echo "⚙️ 正在执行恢复：将上述用户加入本地蜜罐灰名单...\n";
    $configPath = storage_path('tianque_config.json');
    $config = [];
    if (file_exists($configPath)) {
        $config = json_decode(@file_get_contents($configPath), true) ?: [];
    }
    if (!isset($config['honeypot_users']) || !is_array($config['honeypot_users'])) {
        $config['honeypot_users'] = [];
    }
    
    $existing = array_map('intval', $config['honeypot_users']);
    $addedCount = 0;
    foreach ($restoreUserIds as $uid) {
        if (!in_array($uid, $existing, true)) {
            $existing[] = $uid;
            $config['honeypot_users'] = $existing;
            if (!isset($config['honeypot_times'])) {
                $config['honeypot_times'] = [];
            }
            $config['honeypot_times'][(string)$uid] = time();
            $addedCount++;
        }
    }
    
    @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    // 修复配置文件的所有权，以防权限卡住
    @chmod($configPath, 0755);
    
    echo "✅ 成功将 {$addedCount} 个新扫描到的账号加回本地蜜罐！当前蜜罐用户总数: " . count($existing) . " 个。\n";
} else {
    echo "💡 提示：若要直接将扫描出的这批账号一键接管并恢复进蜜罐，请执行以下命令运行该脚本：\n";
    echo "   php scratch_audit.php --restore\n";
}
echo "==================================================\n";
