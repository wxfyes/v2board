<?php
/**
 * 天阙安全审计面板 - 独立运行插件 (免路由缓存/免常驻内存重启拦截)
 * 物理路径：public/tianque_detect.php
 */

// ==========================================
// 1. 安全访问密钥 (请在下面自定义修改以防泄漏)
// ==========================================
$securityToken = 'tianque_audit_key_888';

if (!isset($_GET['token']) || $_GET['token'] !== $securityToken) {
    header('HTTP/1.1 403 Forbidden');
    die('<h1>403 Forbidden</h1><p>安全密钥校验失败，无法访问。</p>');
}

// ==========================================
// 2. 数据库连接初始化 (自动解析 .env)
// ==========================================
function getDbConfig() {
    $envPath = __DIR__ . '/../.env';
    if (!file_exists($envPath)) {
        die('Error: .env file not found.');
    }
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $config[trim($name)] = trim($value, " \t\n\r\0\x0B\"'");
    }
    return $config;
}

$dbConfig = getDbConfig();
$host = $dbConfig['DB_HOST'] ?? '127.0.0.1';
$port = $dbConfig['DB_PORT'] ?? '3306';
$dbname = $dbConfig['DB_DATABASE'] ?? '';
$username = $dbConfig['DB_USERNAME'] ?? '';
$password = $dbConfig['DB_PASSWORD'] ?? '';

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// GUID 生成函数
function generateGuid($trim = true) {
    $guid = sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
    return $trim ? str_replace('-', '', strtolower($guid)) : strtolower($guid);
}

// 流量单位换算
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// ==========================================
// 3. API 请求分发逻辑
// ==========================================
$action = $_GET['action'] ?? 'view';

// API: 获取内鬼列表
if ($action === 'fetch') {
    header('Content-Type: application/json; charset=utf-8');
    
    $targetInterval = (int)($_GET['interval'] ?? 300);
    $tolerance = (int)($_GET['tolerance'] ?? 30);
    $maxTrafficMb = (int)($_GET['max_traffic'] ?? 50); // 流量判定阈值，默认50MB

    $now = time();
    $whitelistClients = ['天阙(TianQue)', 'Mclash', 'MOMclash'];

    // 查询未封禁且拉取过订阅的用户，包含上传/下载流量字段 (u/d)
    $stmt = $pdo->prepare("SELECT id, email, u, d, client_type FROM v2_user WHERE banned = 0 AND client_type IS NOT NULL");
    $stmt->execute();
    $users = $stmt->fetchAll();

    $detected = [];

    foreach ($users as $user) {
        $history = json_decode($user['client_type'], true);
        if (!is_array($history) || count($history) < 5) {
            continue;
        }

        // 时效过滤放宽到 24 小时，以便能捕获长周期（如每几小时拉一次）的探测行为
        if ($now - $history[0]['time'] > 86400) {
            continue;
        }

        // 白名单过滤
        $hasWhitelistClient = false;
        foreach ($history as $item) {
            if (in_array($item['type'], $whitelistClients)) {
                $hasWhitelistClient = true;
                break;
            }
        }
        if ($hasWhitelistClient) {
            continue;
        }

        // 跨度过滤 (5次的总跨度必须大于600秒，防止连点误伤)
        $totalSpan = $history[0]['time'] - $history[count($history) - 1]['time'];
        if ($totalSpan < 600) {
            continue;
        }

        // 计算拉取时间差
        $diffs = [];
        for ($i = 0; $i < count($history) - 1; $i++) {
            $diff = $history[$i]['time'] - $history[$i + 1]['time'];
            $diffs[] = $diff;
        }

        $averageInterval = array_sum($diffs) / count($diffs);
        $maxInterval = max($diffs);
        $minInterval = min($diffs);
        $range = $maxInterval - $minInterval;

        // 判定规则：
        // 1. 匹配管理员设定的目标秒数 (如 300秒)，且抖动极小
        $isTargetInterval = abs($averageInterval - $targetInterval) <= $tolerance && $range <= $tolerance;
        
        // 2. 匹配通用的短周期高频规律拉取 (10分钟内，极差小于15秒)
        $isGeneralFastRegular = $averageInterval <= 600 && $range <= 15;

        // 3. 匹配长周期规律拉取 (如每小时、每几小时拉一次，极差抖动小于 60 秒，高度判定为机器行为)
        $isLongPeriodRegular = $averageInterval > 600 && $range <= 60;

        if ($isTargetInterval || $isGeneralFastRegular || $isLongPeriodRegular) {
            $totalTrafficBytes = $user['u'] + $user['d'];
            $totalTrafficMb = $totalTrafficBytes / 1024 / 1024;

            // 归一化 IP 和 UA 列表
            $ips = [];
            $uas = [];
            $formattedHistory = [];
            foreach ($history as $item) {
                if (!empty($item['ip'])) $ips[] = $item['ip'];
                if (!empty($item['ua'])) $uas[] = $item['ua'];
                $formattedHistory[] = [
                    'time' => date('Y-m-d H:i:s', $item['time']),
                    'type' => $item['type'],
                    'ip' => $item['ip'] ?? ''
                ];
            }

            // 标记低流量嫌疑：若总已用流量低于设定的阈值，则亮起高危标记
            $isSuspiciousLowTraffic = $totalTrafficMb < $maxTrafficMb;

            $detected[] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'average_interval' => round($averageInterval),
                'range' => $range,
                'total_traffic_raw' => $totalTrafficBytes,
                'total_traffic_formatted' => formatBytes($totalTrafficBytes),
                'is_low_traffic' => $isSuspiciousLowTraffic,
                'ips' => array_values(array_unique($ips)),
                'uas' => array_values(array_unique($uas)),
                'history' => $formattedHistory
            ];
        }
    }

    // 按平均拉取间隔从小到大排序
    usort($detected, function($a, $b) {
        return $a['average_interval'] <=> $b['average_interval'];
    });

    echo json_encode(['data' => $detected], JSON_UNESCAPED_UNICODE);
    exit;
}

// API: 一键封禁
if ($action === 'ban') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = (int)($_POST['id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['ok' => false, 'message' => '参数非法']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE v2_user SET banned = 1 WHERE id = :id");
    $res = $stmt->execute(['id' => $userId]);

    echo json_encode(['ok' => $res]);
    exit;
}

// API: 一键重置订阅 Token
if ($action === 'reset') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = (int)($_POST['id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['ok' => false, 'message' => '参数非法']);
        exit;
    }

    $token = generateGuid();
    $uuid = generateGuid(false);

    $stmt = $pdo->prepare("UPDATE v2_user SET token = :token, uuid = :uuid WHERE id = :id");
    $res = $stmt->execute([
        'token' => $token,
        'uuid' => $uuid,
        'id' => $userId
    ]);

    echo json_encode(['ok' => $res]);
    exit;
}

// ==========================================
// 4. HTML 面板渲染 (View)
// ==========================================
?>
<!DOCTYPE html>
<html lang="zh-CN" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>内鬼探测与测活审计面板</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        darkBg: '#05070f',
                        glassBg: 'rgba(10, 15, 30, 0.7)',
                        glassBorder: 'rgba(255, 255, 255, 0.08)'
                    }
                }
            }
        }
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/vue/3.3.4/vue.global.prod.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #05070f;
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(79, 70, 229, 0.12) 0%, transparent 50%),
                radial-gradient(circle at 90% 80%, rgba(139, 92, 246, 0.1) 0%, transparent 50%);
        }
        .outfit { font-family: 'Outfit', sans-serif; }
        .glass-card {
            background: rgba(13, 20, 38, 0.75);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
    </style>
</head>
<body class="text-slate-100 min-h-screen">
    <div id="app" class="max-w-6xl mx-auto px-4 py-8">
        <!-- Toast Notification -->
        <div v-if="toast.show" class="fixed top-6 right-6 z-50 transform transition-all duration-300">
            <div :class="[
                'px-5 py-3 rounded-xl shadow-2xl glass-card flex items-center space-x-3 border',
                toast.type === 'success' ? 'border-emerald-500/30 text-emerald-400' : 
                toast.type === 'error' ? 'border-rose-500/30 text-rose-400' : 'border-indigo-500/30 text-indigo-400'
            ]">
                <span v-if="toast.type === 'success'" class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                <span v-else-if="toast.type === 'error'" class="w-2 h-2 rounded-full bg-rose-400 animate-ping"></span>
                <span v-else class="w-2 h-2 rounded-full bg-indigo-400 animate-ping"></span>
                <span class="font-medium text-sm">{{ toast.message }}</span>
            </div>
        </div>

        <!-- Header -->
        <header class="flex flex-col md:flex-row md:items-center justify-between mb-8 pb-6 border-b border-white/5">
            <div>
                <div class="flex items-center space-x-3 mb-2">
                    <span class="px-2.5 py-1 text-xs font-semibold uppercase tracking-wider text-indigo-400 bg-indigo-500/10 rounded-full border border-indigo-500/20">安全物理单页</span>
                    <span class="flex h-2 w-2 relative">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                    <span class="text-xs text-emerald-400/80 font-medium">行为引擎装载成功</span>
                </div>
                <h1 class="text-3xl font-bold outfit tracking-tight text-white flex items-center">
                    内鬼探测与防测活审计面板
                </h1>
            </div>
            
            <div class="mt-4 md:mt-0 flex items-center space-x-3">
                <button @click="fetchData" :disabled="loading" class="px-4 py-2 text-sm font-semibold rounded-xl bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white transition-all shadow-lg shadow-indigo-600/20 flex items-center space-x-2 disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg v-if="loading" class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>{{ loading ? '分析中...' : '立即扫描' }}</span>
                </button>
            </div>
        </header>

        <!-- Configuration Bar -->
        <section class="glass-card rounded-2xl p-6 mb-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                <div class="flex flex-col">
                    <label class="text-xs text-slate-400 mb-1.5 font-medium">匹配特定目标拉取间隔 (秒)</label>
                    <input type="number" v-model="interval" class="px-4 py-2 text-sm rounded-xl bg-white/5 border border-white/10 text-white focus:outline-none focus:border-indigo-500/50 w-full" placeholder="300" />
                </div>
                <div class="flex flex-col">
                    <label class="text-xs text-slate-400 mb-1.5 font-medium">抖动容差 (秒)</label>
                    <input type="number" v-model="tolerance" class="px-4 py-2 text-sm rounded-xl bg-white/5 border border-white/10 text-white focus:outline-none focus:border-indigo-500/50 w-full" placeholder="30" />
                </div>
                <div class="flex flex-col">
                    <label class="text-xs text-slate-400 mb-1.5 font-medium">内鬼低流量阈值 (MB)</label>
                    <div class="flex space-x-3">
                        <input type="number" v-model="maxTraffic" class="px-4 py-2 text-sm rounded-xl bg-white/5 border border-white/10 text-white focus:outline-none focus:border-indigo-500/50 w-full" placeholder="50" />
                        <button @click="fetchData" class="px-4 py-2 text-sm font-semibold rounded-xl bg-white/10 hover:bg-white/15 text-slate-200 transition-all border border-white/5 shrink-0">
                            应用参数
                        </button>
                    </div>
                </div>
            </div>

            <div class="text-xs text-slate-400 leading-relaxed mt-5 bg-white/5 border border-white/5 p-4 rounded-xl flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    💡 <span class="font-medium text-slate-300">升级版行为审计策略：</span>
                    当前已放宽最近拉取时效为 <b>24小时</b>（适配数小时拉取一次的长周期内鬼）。
                    判定算法自动联合分析 <b>“拉取规律极差”</b> 和 <b>“总流量消耗”</b>，揪出不吃流量、只拿节点定时探活的高可疑内鬼！
                </div>
            </div>
        </section>

        <!-- Main Display -->
        <main>
            <!-- Loading Indicator -->
            <div v-if="loading && users.length === 0" class="flex flex-col items-center justify-center py-24 space-y-4">
                <div class="relative w-12 h-12">
                    <div class="absolute inset-0 rounded-full border-4 border-indigo-500/20"></div>
                    <div class="absolute inset-0 rounded-full border-4 border-indigo-500 border-t-transparent animate-spin"></div>
                </div>
                <p class="text-sm text-slate-400">正在进行 24 小时高频与长周期数据关联分析...</p>
            </div>

            <!-- Empty State (No bots detected) -->
            <div v-if="!loading && users.length === 0" class="glass-card rounded-2xl p-12 text-center flex flex-col items-center justify-center border-emerald-500/10">
                <div class="w-16 h-16 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400 mb-4 shadow-lg shadow-emerald-500/5">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"></path>
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-white mb-2">安全防护状态正常</h3>
                <p class="text-sm text-slate-400 max-w-sm">目前未捕获到任何符合高频或长周期定时拉取的异常探针行为。</p>
            </div>

            <!-- Bot User Cards Grid -->
            <div v-if="users.length > 0" class="grid grid-cols-1 gap-6">
                <div v-for="user in users" :key="user.id" class="glass-card rounded-2xl p-6 transition-all duration-300 hover:border-white/15 flex flex-col justify-between">
                    <div>
                        <!-- Card Header Info -->
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-white/5 mb-4">
                            <div>
                                <span class="text-xs text-slate-400 font-semibold outfit uppercase">用户 ID: {{ user.id }}</span>
                                <h3 class="text-lg font-bold text-white tracking-wide mt-0.5">{{ user.email }}</h3>
                            </div>
                            
                            <!-- Badges -->
                            <div class="flex flex-wrap gap-2">
                                <span v-if="user.is_low_traffic" class="px-2.5 py-1 text-xs font-bold bg-rose-500/20 text-rose-400 border border-rose-500/30 rounded-lg animate-pulse">
                                    ⚠️ 流量极低 (仅 {{ user.total_traffic_formatted }})
                                </span>
                                <span v-else class="px-2.5 py-1 text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded-lg">
                                    流量消耗: {{ user.total_traffic_formatted }}
                                </span>
                                <span class="px-2.5 py-1 text-xs font-medium bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 rounded-lg">
                                    平均间隔: {{ user.average_interval }} 秒 (~{{ Math.round(user.average_interval / 60) }}分钟)
                                </span>
                                <span class="px-2.5 py-1 text-xs font-medium bg-amber-500/10 text-amber-400 border border-amber-500/20 rounded-lg">
                                    极差抖动: ±{{ user.range }} 秒
                                </span>
                            </div>
                        </div>

                        <!-- Card Meta Logs (IP and UA) -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4 text-sm bg-white/5 border border-white/5 p-4 rounded-xl">
                            <div>
                                <span class="text-xs text-slate-400 block mb-1">拉取服务器 IP 群 (点击可查询归属地)：</span>
                                <div class="flex flex-wrap gap-2">
                                    <template v-if="user.ips && user.ips.length > 0">
                                        <a v-for="ip in user.ips" :key="ip" :href="'https://ipinfo.io/' + ip" target="_blank" class="px-2 py-1 bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-400 text-xs rounded-md border border-indigo-500/20 transition-all font-mono">
                                            {{ ip }} ↗
                                        </a>
                                    </template>
                                    <span v-else class="text-xs text-slate-500 italic">暂无记录</span>
                                </div>
                            </div>
                            <div>
                                <span class="text-xs text-slate-400 block mb-1">使用客户端 (UA)：</span>
                                <div class="flex flex-wrap gap-2">
                                    <template v-if="user.uas && user.uas.length > 0">
                                        <span v-for="ua in user.uas" :key="ua" class="px-2 py-1 bg-slate-500/10 text-slate-300 text-xs rounded-md border border-slate-500/20 font-mono">
                                            {{ ua }}
                                        </span>
                                    </template>
                                    <span v-else class="text-xs text-slate-500 italic">暂无记录</span>
                                </div>
                            </div>
                        </div>

                        <!-- Sub-history Details (Collapse) -->
                        <details class="group mb-4">
                            <summary class="text-xs font-semibold text-indigo-400/90 cursor-pointer hover:text-indigo-400 transition-all flex items-center space-x-1 outline-none select-none">
                                <span>查看最近 5 次订阅拉取历史明细</span>
                                <svg class="w-3 h-3 transform group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"></path>
                                </svg>
                            </summary>
                            
                            <div class="mt-3 overflow-hidden border border-white/5 rounded-xl bg-black/20">
                                <table class="w-full text-left text-xs">
                                    <thead>
                                        <tr class="bg-white/5 text-slate-400 border-b border-white/5">
                                            <th class="px-4 py-2 font-medium">拉取时间</th>
                                            <th class="px-4 py-2 font-medium">识别UA类型</th>
                                            <th class="px-4 py-2 font-medium">请求来源 IP</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/5">
                                        <tr v-for="(h, idx) in user.history" :key="idx" class="hover:bg-white/5">
                                            <td class="px-4 py-2 text-slate-300 font-mono">{{ h.time }}</td>
                                            <td class="px-4 py-2"><span class="px-1.5 py-0.5 rounded bg-white/5 border border-white/5">{{ h.type }}</span></td>
                                            <td class="px-4 py-2 text-indigo-300 font-mono">{{ h.ip || '无' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </div>

                    <!-- Actions Footer -->
                    <div class="flex items-center space-x-3 pt-4 border-t border-white/5 mt-2">
                        <button @click="handleReset(user)" class="flex-1 px-4 py-2 text-xs font-semibold text-amber-400 bg-amber-500/10 hover:bg-amber-500/15 active:bg-amber-500/20 border border-amber-500/20 rounded-xl transition-all">
                            🔄 重置订阅 Token (拉取失效)
                        </button>
                        <button @click="handleBan(user)" class="flex-1 px-4 py-2 text-xs font-semibold text-white bg-rose-600 hover:bg-rose-500 active:bg-rose-700 rounded-xl transition-all shadow-lg shadow-rose-600/10">
                            🚨 一键封禁 (Banned)
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Script Logic -->
    <script>
        const { createApp, ref, onMounted } = Vue;

        createApp({
            setup() {
                const loading = ref(false);
                const users = ref([]);
                const interval = ref(300);
                const tolerance = ref(30);
                const maxTraffic = ref(50); // 流量判定限额，默认50MB
                
                // 获取地址栏的安全 token
                const urlParams = new URLSearchParams(window.location.search);
                const token = urlParams.get('token') || '';

                const toast = ref({ show: false, message: '', type: 'info' });

                const showToast = (message, type = 'info') => {
                    toast.value.message = message;
                    toast.value.type = type;
                    toast.value.show = true;
                    setTimeout(() => {
                        toast.value.show = false;
                    }, 3000);
                };

                const fetchData = async () => {
                    loading.value = true;
                    try {
                        const response = await fetch(`tianque_detect.php?action=fetch&token=${token}&interval=${interval.value}&tolerance=${tolerance.value}&max_traffic=${maxTraffic.value}`);
                        const res = await response.json();
                        users.value = res.data;
                        showToast(`扫描完毕，共分析出 ${users.value.length} 个潜在探针/测活账号`, 'success');
                    } catch (err) {
                        showToast('获取数据失败，请确认密钥无误', 'error');
                    } finally {
                        loading.value = false;
                    }
                };

                const handleBan = async (user) => {
                    if (!confirm(`确定要封禁用户 ${user.email} 吗？\n该操作会立即封锁其全部服务。`)) return;
                    try {
                        const formData = new FormData();
                        formData.append('id', user.id);
                        const response = await fetch(`tianque_detect.php?action=ban&token=${token}`, {
                            method: 'POST',
                            body: formData
                        });
                        const res = await response.json();
                        if (res.ok) {
                            showToast(`用户 ${user.email} 已封禁`, 'success');
                            fetchData();
                        } else {
                            showToast('封禁失败', 'error');
                        }
                    } catch (err) {
                        showToast('网络请求失败', 'error');
                    }
                };

                const handleReset = async (user) => {
                    if (!confirm(`确定要重置用户 ${user.email} 的订阅 Token 吗？\n这会使他所有设备上的订阅链接立即失效。`)) return;
                    try {
                        const formData = new FormData();
                        formData.append('id', user.id);
                        const response = await fetch(`tianque_detect.php?action=reset&token=${token}`, {
                            method: 'POST',
                            body: formData
                        });
                        const res = await response.json();
                        if (res.ok) {
                            showToast(`重置成功！原订阅链接已失效`, 'success');
                            fetchData();
                        } else {
                            showToast('重置失败', 'error');
                        }
                    } catch (err) {
                        showToast('网络请求失败', 'error');
                    }
                };

                onMounted(() => {
                    fetchData();
                });

                return {
                    loading,
                    users,
                    interval,
                    tolerance,
                    maxTraffic,
                    toast,
                    fetchData,
                    handleBan,
                    handleReset,
                    showToast
                }
            }
        }).mount('#app');
    </script>
</body>
</html>
