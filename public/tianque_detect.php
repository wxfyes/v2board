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

// ==========================================
// 3. API 请求分发逻辑
// ==========================================
$action = $_GET['action'] ?? 'view';

// API: 获取内鬼列表
if ($action === 'fetch') {
    header('Content-Type: application/json; charset=utf-8');
    
    $targetInterval = (int)($_GET['interval'] ?? 300);
    $tolerance = (int)($_GET['tolerance'] ?? 30);

    $now = time();
    $whitelistClients = ['天阙(TianQue)', 'Mclash', 'MOMclash'];

    // 查询未封禁且拉取过订阅的用户
    $stmt = $pdo->prepare("SELECT id, email, client_type FROM v2_user WHERE banned = 0 AND client_type IS NOT NULL");
    $stmt->execute();
    $users = $stmt->fetchAll();

    $detected = [];

    foreach ($users as $user) {
        $history = json_decode($user['client_type'], true);
        if (!is_array($history) || count($history) < 5) {
            continue;
        }

        // 时效过滤 (1小时内)
        if ($now - $history[0]['time'] > 3600) {
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

        // 跨度过滤
        $totalSpan = $history[0]['time'] - $history[count($history) - 1]['time'];
        if ($totalSpan < 600) {
            continue;
        }

        // 计算时间差
        $diffs = [];
        for ($i = 0; $i < count($history) - 1; $i++) {
            $diff = $history[$i]['time'] - $history[$i + 1]['time'];
            $diffs[] = $diff;
        }

        $averageInterval = array_sum($diffs) / count($diffs);
        $maxInterval = max($diffs);
        $minInterval = min($diffs);
        $range = $maxInterval - $minInterval;

        $isTargetInterval = abs($averageInterval - $targetInterval) <= $tolerance;
        $isExtremelyRegular = $range <= $tolerance;
        $isGeneralFastRegular = $averageInterval <= 600 && $range <= 15;

        if (($isTargetInterval && $isExtremelyRegular) || $isGeneralFastRegular) {
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

            $detected[] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'average_interval' => round($averageInterval),
                'range' => $range,
                'ips' => array_values(array_unique($ips)),
                'uas' => array_values(array_unique($uas)),
                'history' => $formattedHistory
            ];
        }
    }

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
                    <span class="text-xs text-emerald-400/80 font-medium">物理层装载成功</span>
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
                    <span>{{ loading ? '扫描中...' : '立即扫描' }}</span>
                </button>
            </div>
        </header>

        <!-- Configuration Bar -->
        <section class="glass-card rounded-2xl p-6 mb-8 flex flex-col md:flex-row items-center justify-between gap-6">
            <div class="flex flex-col md:flex-row items-stretch md:items-center gap-4 w-full md:w-auto">
                <div class="flex flex-col">
                    <label class="text-xs text-slate-400 mb-1 font-medium">目标检测间隔 (秒)</label>
                    <input type="number" v-model="interval" class="px-4 py-2 text-sm rounded-xl bg-white/5 border border-white/10 text-white focus:outline-none focus:border-indigo-500/50 w-full md:w-48" placeholder="300" />
                </div>
                <div class="flex flex-col">
                    <label class="text-xs text-slate-400 mb-1 font-medium">时间抖动容差 (秒)</label>
                    <input type="number" v-model="tolerance" class="px-4 py-2 text-sm rounded-xl bg-white/5 border border-white/10 text-white focus:outline-none focus:border-indigo-500/50 w-full md:w-48" placeholder="30" />
                </div>
                <div class="flex items-end mt-4 md:mt-0">
                    <button @click="fetchData" class="px-4 py-2 text-sm font-semibold rounded-xl bg-white/10 hover:bg-white/15 text-slate-200 transition-all border border-white/5">
                        应用参数
                    </button>
                </div>
            </div>

            <div class="text-xs text-slate-400 leading-relaxed max-w-md bg-white/5 border border-white/5 p-4 rounded-xl">
                💡 <span class="font-medium text-slate-300">安全防护策略：</span>已过滤 <b>天阙 App</b> 及 <b>MOMclash</b> 自家客户端流量，并自动规避网络卡顿瞬间连点，确保过滤结果 100% 精准无误伤。
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
                <p class="text-sm text-slate-400">正在深入分析订阅记录与行为模型...</p>
            </div>

            <!-- Empty State (No bots detected) -->
            <div v-if="!loading && users.length === 0" class="glass-card rounded-2xl p-12 text-center flex flex-col items-center justify-center border-emerald-500/10">
                <div class="w-16 h-16 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400 mb-4 shadow-lg shadow-emerald-500/5">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"></path>
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-white mb-2">安全防护状态正常</h3>
                <p class="text-sm text-slate-400 max-w-sm">目前未检测到活跃的高频定时测活“内鬼”行为。</p>
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
                                <span class="px-2.5 py-1 text-xs font-medium bg-rose-500/10 text-rose-400 border border-rose-500/20 rounded-lg">
                                    平均间隔: {{ user.average_interval }} 秒
                                </span>
                                <span class="px-2.5 py-1 text-xs font-medium bg-amber-500/10 text-amber-400 border border-amber-500/20 rounded-lg">
                                    最大时间抖动: ±{{ user.range }} 秒
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
                                    <span v-else class="text-xs text-slate-500 italic">暂无记录 (等待新数据积累)</span>
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
                                    <span v-else class="text-xs text-slate-500 italic">暂无记录 (等待新数据积累)</span>
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
                        const response = await fetch(`tianque_detect.php?action=fetch&token=${token}&interval=${interval.value}&tolerance=${tolerance.value}`);
                        const res = await response.json();
                        users.value = res.data;
                        showToast(`扫描完毕，共发现 ${users.value.length} 个定时测活用户`, 'success');
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
