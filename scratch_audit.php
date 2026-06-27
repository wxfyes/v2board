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

$users = User::where('id', '>', 10000)
    ->whereNotNull('client_type')
    ->where('client_type', 'like', '%clash-verge%')
    ->where('client_type', 'like', '%733%')
    ->get(['id', 'email', 'client_type', 'banned']);

echo "ℹ️ 初筛找到含有相关 UA 历史记录的用户共 " . $users->count() . " 个，开始深入调试精筛过程：\n\n";

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
    echo "--------------------------------------------------\n";
    echo "👤 正在分析用户: " . $user->email . " (ID: " . $user->id . ")\n";
    echo "   账号状态: " . ($user->banned ? "🚫 已封禁" : "🟢 正常") . "\n";
    
    $history = json_decode($user->client_type, true) ?: [];
    
    // 打印其历史 UA 种类
    $uas = array_unique(array_map(function($h) { return $h['ua'] ?? ($h['type'] ?? ''); }, $history));
    echo "   👉 历史出现过的所有 UA 列表: " . implode(' | ', $uas) . "\n";
    
    // 过滤出 24 小时内的拉取记录
    $logs24h = array_filter($history, function($h) use ($now) {
        return ($now - ($h['time'] ?? 0)) <= 86400;
    });
    
    echo "   👉 最近 24h 内拉取活跃次数: " . count($logs24h) . " 次\n";
    if (empty($logs24h)) {
        echo "   ❌ 排除原因: 该用户最近 24 小时内没有任何订阅更新拉取历史。\n";
        continue;
    }
    
    // 验证 24h 内 UA 条件
    $hasTargetUa = false;
    $activeUas = [];
    foreach ($logs24h as $log) {
        $ua = $log['ua'] ?? ($log['type'] ?? '');
        $activeUas[] = $ua;
        $uaLower = strtolower($ua);
        if (strpos($uaLower, 'clash-verge/733') !== false || (strpos($uaLower, 'clash-verge') !== false && strpos($uaLower, '733') !== false)) {
            $hasTargetUa = true;
        }
    }
    $activeUas = array_unique($activeUas);
    echo "   👉 最近 24h 活跃 UA: " . implode(' | ', $activeUas) . "\n";
    
    if (!$hasTargetUa) {
        echo "   ❌ 排除原因: 该用户最近 24 小时拉取的 UA 里不包含 'clash-verge/733' (该 UA 仅存在于更早的历史里)。\n";
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
    echo "   👉 最近 24h 独立 IP 数量: " . count($uniqueIps) . " 个\n";
    
    if (count($uniqueIps) < 5) {
        echo "   ❌ 排除原因: 最近 24h 独立拉取的 IP 数量小于 5 个 (目前 IP 列表: " . implode(', ', $uniqueIps) . ")，无法满足 5 个不同省份拉取的特征。\n";
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
            
            if ($details['countryCode'] === 'CN' || $details['country'] === '中国') {
                $chinaCount++;
            }
            
            if (strpos($details['isp'], 'alibaba') !== false || strpos($details['isp'], 'aliyun') !== false || strpos($details['isp'], '阿里云') !== false) {
                $aliyunCount++;
            }
            
            if (!empty($details['region'])) {
                $regions[] = $details['region'];
            }
        }
    }
    
    $uniqueRegions = array_values(array_unique($regions));
    echo "   👉 最近 24h 独立 IP 归属地省份数量: " . count($uniqueRegions) . " 个 (省份列表: " . implode(', ', $uniqueRegions) . ")\n";
    echo "   👉 最近 24h 独立 IP 中阿里云节点个数: " . $aliyunCount . " 个\n";
    
    if (count($uniqueRegions) < 5) {
        echo "   ❌ 排除原因: 虽独立 IP 数足够，但去重后省份数量仅为 " . count($uniqueRegions) . " 个，未达到 5 个不同省份拉取的判定标准。\n";
        continue;
    }
    
    if ($aliyunCount === 0) {
        echo "   ❌ 排除原因: 最近 24 小时拉取的 IP 中，没有检测到阿里云 (Alibaba/Aliyun) 所有权的云主机 IP。\n";
        continue;
    }
    
    // 完全吻合
    $matchedUsers[] = [
        'id' => $user->id,
        'email' => $user->email,
        'banned' => $user->banned,
        'china_ip_count' => $chinaCount,
        'aliyun_ip_count' => $aliyunCount,
        'regions' => $uniqueRegions,
        'ips' => $ipDetailsList
    ];
    echo "   ✅ 完全吻合特异内鬼条件！\n";
}

echo "--------------------------------------------------\n\n";

if (empty($matchedUsers)) {
    echo "❌ 未能扫描到完全符合该特征的活跃用户。\n";
    exit(0);
}

echo "🎉 精精筛捕获完毕，共匹配到 " . count($matchedUsers) . " 个账号！\n";
echo "==================================================\n";
