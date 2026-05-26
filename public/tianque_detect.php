<?php
/**
 * 天阙安全审计面板 - 独立运行插件 (免路由缓存/免常驻内存重启拦截)
 * 物理路径：public/tianque_detect.php
 */

// ==========================================
// 1. 安全访问密钥 (请在下面自定义修改以防泄漏)
// ==========================================
$securityToken = 'tianque_audit_key_888';
$cookieName = 'tianque_audit_session';

// A. 敲门机制：兼容支持 set_token 和旧的 token 参数进行首次授权
$passedToken = $_GET['set_token'] ?? ($_GET['token'] ?? null);
if ($passedToken !== null) {
    if ($passedToken === $securityToken) {
        // 写入 1 年有效期的安全 Cookie (设置 HttpOnly 防止脚本窃取)
        setcookie($cookieName, hash('sha256', $securityToken), time() + 365 * 86400, '/', '', false, true);
        // 清洗 URL，防止密钥留在浏览器的历史记录或书签里
        header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
        exit;
    }
}

// B. 校验授权：检查浏览器中是否有正确的安全 Cookie
$hasAuth = false;
if (isset($_COOKIE[$cookieName]) && $_COOKIE[$cookieName] === hash('sha256', $securityToken)) {
    $hasAuth = true;
}

// C. 隐藏假装：若没有通过校验，100% 伪装成标准的 Nginx 404 页面
if (!$hasAuth) {
    header('HTTP/1.1 404 Not Found');
    header("Status: 404 Not Found");
    echo '<!DOCTYPE html>
<html>
<head><title>404 Not Found</title></head>
<body>
<center><h1>404 Not Found</h1></center>
<hr><center>nginx</center>
</body>
</html>';
    exit;
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
    
    $configPath = __DIR__ . '/../storage/tianque_config.json';
    $honeypots = [];
    if (file_exists($configPath)) {
        $tianqueConfig = json_decode(@file_get_contents($configPath), true);
        if (is_array($tianqueConfig)) {
            $honeypots = $tianqueConfig['honeypot_users'] ?? [];
        }
    }
    $honeypots = array_map('intval', $honeypots);

    $targetInterval = (int)($_GET['interval'] ?? 300);
    $tolerance = (int)($_GET['tolerance'] ?? 30);
    $maxTrafficMb = (int)($_GET['max_traffic'] ?? 50);
    
    // mode: detect (仅定时内鬼) | all_low (全部低流量活跃订阅用户)
    $mode = $_GET['mode'] ?? 'detect'; 
    // bypass_whitelist: true (包含天阙/MOMclash等) | false (过滤天阙/MOMclash等)
    $bypassWhitelist = ($_GET['bypass_whitelist'] ?? 'false') === 'true';

    // 高级特定时段过滤参数
    $timeFilterEnable = ($_GET['time_filter_enable'] ?? 'false') === 'true';
    $timeTarget = $_GET['time_target'] ?? '08:00'; 
    $timeRangeMin = (int)($_GET['time_range_min'] ?? 15);

    // 新增：显示已过期账号开关 (true | false)，默认改为不显示已过期账号
    $showExpired = ($_GET['show_expired'] ?? 'false') === 'true';
    // 新增：显示已封禁账号开关 (true | false)，默认改为不显示已封禁账号
    $showBanned = ($_GET['show_banned'] ?? 'false') === 'true';
    // 新增：仅查看异常UA开关 (true | false)，默认改为默认过滤只看异常
    $abnormalUaOnly = ($_GET['abnormal_ua_only'] ?? 'true') === 'true';

    // 【新增重要过滤控制】：拉取活跃时效限制 (秒)，默认修改为 259200 (3天)
    $timeLimit = isset($_GET['time_limit']) ? (int)$_GET['time_limit'] : 259200;

    $now = time();
    $whitelistClients = ['天阙(TianQue)', 'Mclash', 'MOMclash'];
    
    // 判定异常命令行/开发库 UA 的关键字
    $abnormalKeywords = ['curl', 'wget', 'python', 'requests', 'go-http', 'urllib', 'httpclient', 'postman', 'aria2'];

    // 解析目标特定时间段
    $targetSecs = 0;
    $rangeSecs = 0;
    if ($timeFilterEnable) {
        list($tHour, $tMin) = explode(':', $timeTarget . ':00');
        $targetSecs = (int)$tHour * 3600 + (int)$tMin * 60;
        $rangeSecs = $timeRangeMin * 60;
    }

    // 突破限制：查询所有拉取过订阅的用户，包括已封禁的用户，新增 group_id 字段
    $stmt = $pdo->prepare("SELECT id, email, u, d, banned, expired_at, client_type, group_id FROM v2_user WHERE client_type IS NOT NULL");
    $stmt->execute();
    $users = $stmt->fetchAll();

    // 准备查询订阅组和订单时间的 SQL，提高批处理效率
    $groupStmt = $pdo->prepare("SELECT name FROM v2_server_group WHERE id = :id LIMIT 1");
    $firstOrderStmt = $pdo->prepare("SELECT MIN(created_at) FROM v2_order WHERE user_id = :user_id AND status = 3");
    $lastOrderStmt = $pdo->prepare("SELECT MAX(created_at) FROM v2_order WHERE user_id = :user_id AND status = 3");

    $detected = [];

    foreach ($users as $user) {
        $history = json_decode($user['client_type'], true);
        if (!is_array($history) || count($history) < 1) {
            continue;
        }

        // 强健时效判定：获取 5 条历史中最大（最新）的时间戳进行活跃比对，支持调大范围或无限制
        if ($timeLimit > 0) {
            $timestamps = array_column($history, 'time');
            $latestTime = count($timestamps) > 0 ? max($timestamps) : 0;
            if ($now - $latestTime > $timeLimit) {
                continue;
            }
        }

        // 判定是否封禁
        $isBanned = (int)$user['banned'] === 1;
        if (!$showBanned && $isBanned) {
            continue;
        }

        // 判定是否过期
        $isExpired = false;
        if ($user['expired_at'] !== null && $user['expired_at'] > 0 && $user['expired_at'] < $now) {
            $isExpired = true;
        }

        // 过期过滤控制
        if (!$showExpired && $isExpired) {
            continue;
        }

        // 流量去噪：如果该用户总流量已经超过设定的限额（如5000MB），直接排除
        $totalTrafficBytes = $user['u'] + $user['d'];
        $totalTrafficMb = $totalTrafficBytes / 1024 / 1024;
        if ($totalTrafficMb >= $maxTrafficMb) {
            continue;
        }

        // 白名单过滤：只有在“不过滤白名单”时才跳过
        if (!$bypassWhitelist) {
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
        }

        // 检索该用户的 5 次历史中是否有异常 UA 命令行爬取行为
        $hasAbnormalUa = false;
        foreach ($history as $item) {
            // 【关键兼容】：原版 V2Board 官方将客户端/UA 信息直接存入了 'type' 键；而新版记录则存入 'ua'。必须同时检测！
            $uaLower = strtolower($item['ua'] ?? '');
            $typeLower = strtolower($item['type'] ?? '');
            foreach ($abnormalKeywords as $kw) {
                if (strpos($uaLower, $kw) !== false || strpos($typeLower, $kw) !== false) {
                    $hasAbnormalUa = true;
                    break 2;
                }
            }
        }

        // 异常 UA 强力过滤
        if ($abnormalUaOnly && !$hasAbnormalUa) {
            continue;
        }

        // 高级特定时间段匹配检测
        if ($timeFilterEnable) {
            $hasTimeMatch = false;
            foreach ($history as $item) {
                $t = $item['time'];
                $h = (int)date('H', $t);
                $m = (int)date('i', $t);
                $s = (int)date('s', $t);
                $recordSecs = $h * 3600 + $m * 60 + $s;

                $diffSecs = abs($recordSecs - $targetSecs);
                if ($diffSecs > 43200) {
                    $diffSecs = 86400 - $diffSecs;
                }

                if ($diffSecs <= $rangeSecs) {
                    $hasTimeMatch = true;
                    break;
                }
            }
            if (!$hasTimeMatch) {
                continue;
            }
        }

        $averageInterval = 0;
        $range = 0;
        $isMatched = false;

        if ($mode === 'all_low') {
            // 模式二：全部低流量活跃用户
            $isMatched = true;
        } else {
            // 模式一：捕获高规律定时测活机器
            if (count($history) >= 2) { 
                $sortedTimes = array_column($history, 'time');
                rsort($sortedTimes);
                
                $diffs = [];
                for ($i = 0; $i < count($sortedTimes) - 1; $i++) {
                    $diff = $sortedTimes[$i] - $sortedTimes[$i + 1];
                    if ($diff > 0) $diffs[] = $diff;
                }

                if (count($diffs) > 0) {
                    $averageInterval = array_sum($diffs) / count($diffs);
                    $maxInterval = max($diffs);
                    $minInterval = min($diffs);
                    $range = $maxInterval - $minInterval;

                    $isTargetInterval = abs($averageInterval - $targetInterval) <= $tolerance && $range <= $tolerance;
                    $isGeneralFastRegular = $averageInterval <= 600 && $range <= 15;
                    $isLongPeriodRegular = $averageInterval > 600 && $range <= 60;

                    if ($isTargetInterval || $isGeneralFastRegular || $isLongPeriodRegular) {
                        $isMatched = true;
                    }
                }
            }
        }

        if ($isMatched) {
            // 归一化 IP 和 UA 列表，并把时间倒序排列
            $ips = [];
            $uas = [];
            $formattedHistory = [];
            foreach ($history as $item) {
                if (!empty($item['ip'])) $ips[] = $item['ip'];
                // 如果 item['ua'] 为空，则把官方原生存的 type 也收集作为 UA 显示
                $actualUa = !empty($item['ua']) ? $item['ua'] : $item['type'];
                $uas[] = $actualUa;

                $formattedHistory[] = [
                    'time' => date('Y-m-d H:i:s', $item['time']),
                    'type' => $item['type'],
                    'ip' => $item['ip'] ?? ''
                ];
            }

            // 按时间排序，确保最新的一定排最前
            usort($formattedHistory, function($a, $b) {
                return strtotime($b['time']) <=> strtotime($a['time']);
            });

            // 动态查询该内鬼用户的权限组名称
            $groupId = (int)($user['group_id'] ?? 0);
            $groupStmt->execute(['id' => $groupId]);
            $groupName = $groupStmt->fetchColumn();
            $userGroupName = $groupName ? "{$groupName} (组 ID: {$groupId})" : "组 ID: {$groupId}";

            // 动态查询该内鬼用户的首笔成功购买订单时间
            $firstOrderStmt->execute(['user_id' => $user['id']]);
            $firstOrderTime = $firstOrderStmt->fetchColumn();
            $firstBuyFormatted = $firstOrderTime ? date('Y-m-d H:i:s', $firstOrderTime) : '暂无订单纪录';

            // 动态查询该内鬼用户的最近成功续费/购买时间
            $lastOrderStmt->execute(['user_id' => $user['id']]);
            $lastOrderTime = $lastOrderStmt->fetchColumn();
            $lastBuyFormatted = $lastOrderTime ? date('Y-m-d H:i:s', $lastOrderTime) : '暂无订单纪录';

            $detected[] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'average_interval' => round($averageInterval),
                'range' => $range,
                'total_traffic_raw' => $totalTrafficBytes,
                'total_traffic_formatted' => formatBytes($totalTrafficBytes),
                'is_expired' => $isExpired,
                'expired_at_formatted' => $user['expired_at'] ? date('Y-m-d H:i:s', $user['expired_at']) : '长期有效',
                'is_banned' => $isBanned,
                'is_honeypot' => in_array((int)$user['id'], $honeypots, true),
                'has_abnormal_ua' => $hasAbnormalUa,
                'ips' => array_values(array_unique($ips)),
                'uas' => array_values(array_unique($uas)),
                'history' => $formattedHistory,
                'group_name' => $userGroupName,
                'first_buy_time' => $firstBuyFormatted,
                'last_buy_time' => $lastBuyFormatted
            ];
        }
    }

    // 模式一按平均拉取间隔升序排，模式二按 ID 排
    if ($mode === 'detect') {
        usort($detected, function($a, $b) {
            return $a['average_interval'] <=> $b['average_interval'];
        });
    } else {
        usort($detected, function($a, $b) {
            return $a['id'] <=> $b['id'];
        });
    }

    echo json_encode(['data' => $detected], JSON_UNESCAPED_UNICODE);
    exit;
}

// 辅助日志记录器：将审计操作记录写入 storage/logs/tianque_action.log
function logTianqueAction($userId, $actionType, $detail = '') {
    $logDir = __DIR__ . '/../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logPath = $logDir . '/tianque_action.log';
    $time = date('Y-m-d H:i:s');
    $logMsg = "[{$time}] [UserId: {$userId}] [Action: {$actionType}] {$detail}\n";
    @file_put_contents($logPath, $logMsg, FILE_APPEND);
}

// API: 一键封禁 / 解除封禁
if ($action === 'ban') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = (int)($_POST['id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['ok' => false, 'message' => '参数非法']);
        exit;
    }

    // 查询当前封禁状态，实现状态双向切换
    $stmt = $pdo->prepare("SELECT banned FROM v2_user WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $userId]);
    $currentBanned = (int)($stmt->fetchColumn() ?? 0);

    if ($currentBanned === 1) {
        // 执行解封
        $stmt = $pdo->prepare("UPDATE v2_user SET banned = 0 WHERE id = :id");
        $res = $stmt->execute(['id' => $userId]);
        if ($res) {
            logTianqueAction($userId, 'UNBAN_USER', '一键解除该用户封禁');
        }
        $actionType = 'unbanned';
    } else {
        // 执行封禁
        $stmt = $pdo->prepare("UPDATE v2_user SET banned = 1 WHERE id = :id");
        $res = $stmt->execute(['id' => $userId]);
        if ($res) {
            logTianqueAction($userId, 'BAN_USER', '一键封禁该用户');
        }
        $actionType = 'banned';
    }

    echo json_encode(['ok' => $res, 'action' => $actionType]);
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
    if ($res) {
        logTianqueAction($userId, 'RESET_TOKEN', "重置订阅 token 为 {$token}, UUID 为 {$uuid}");
    }

    echo json_encode(['ok' => $res]);
    exit;
}

// API: 获取配置
if ($action === 'get_config') {
    header('Content-Type: application/json; charset=utf-8');
    $configPath = __DIR__ . '/../storage/tianque_config.json';
    $config = [
        'banned_strategy' => 'bait',
        'banned_redirect_url' => 'https://go.tianquege.top/api/v1/client/subscribe?token=bait_token',
        'honeypot_users' => []
    ];
    if (file_exists($configPath)) {
        $data = json_decode(@file_get_contents($configPath), true);
        if (is_array($data)) {
            $config = array_merge($config, $data);
        }
    } else {
        // 创建默认配置文件
        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    echo json_encode($config);
    exit;
}

// API: 保存配置
if ($action === 'save_config') {
    header('Content-Type: application/json; charset=utf-8');
    $configPath = __DIR__ . '/../storage/tianque_config.json';
    
    $strategy = $_POST['banned_strategy'] ?? 'bait';
    $redirectUrl = $_POST['banned_redirect_url'] ?? '';
    
    $config = [
        'banned_strategy' => 'bait',
        'banned_redirect_url' => 'https://go.tianquege.top/api/v1/client/subscribe?token=bait_token',
        'honeypot_users' => []
    ];
    if (file_exists($configPath)) {
        $data = json_decode(@file_get_contents($configPath), true);
        if (is_array($data)) {
            $config = array_merge($config, $data);
        }
    }
    
    $config['banned_strategy'] = $strategy;
    $config['banned_redirect_url'] = $redirectUrl;
    
    $res = @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(['ok' => $res !== false]);
    exit;
}

// API: 一键加入/移出灰名单
if ($action === 'toggle_honeypot') {
    header('Content-Type: application/json; charset=utf-8');
    $userId = (int)($_POST['id'] ?? 0);
    if ($userId <= 0) {
        echo json_encode(['ok' => false, 'message' => '参数非法']);
        exit;
    }
    
    $configPath = __DIR__ . '/../storage/tianque_config.json';
    $config = [
        'banned_strategy' => 'bait',
        'banned_redirect_url' => 'https://go.tianquege.top/api/v1/client/subscribe?token=bait_token',
        'honeypot_users' => []
    ];
    if (file_exists($configPath)) {
        $data = json_decode(@file_get_contents($configPath), true);
        if (is_array($data)) {
            $config = array_merge($config, $data);
        }
    }
    
    $honeypots = $config['honeypot_users'] ?? [];
    // 强制转换为整型以防混合类型匹配失败
    $honeypots = array_map('intval', $honeypots);
    $userId = (int)$userId;

    if (in_array($userId, $honeypots, true)) {
        // 移出
        $honeypots = array_values(array_filter($honeypots, function($id) use ($userId) {
            return $id !== $userId;
        }));
        $actionType = 'removed';
    } else {
        // 加入
        $honeypots[] = $userId;
        $actionType = 'added';
    }
    $config['honeypot_users'] = $honeypots;
    
    $res = @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(['ok' => $res !== false, 'action' => $actionType, 'honeypots' => $honeypots]);
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
    <title>天阙订阅安全与行为审计中心</title>
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
                    <span class="px-2.5 py-1 text-xs font-semibold uppercase tracking-wider text-indigo-400 bg-indigo-500/10 rounded-full border border-indigo-500/20">天阙行为审计中心</span>
                    <span class="flex h-2 w-2 relative">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-indigo-500"></span>
                    </span>
                    <span class="text-xs text-indigo-400/80 font-medium">行为沙盒就绪</span>
                </div>
                <h1 class="text-3xl font-bold outfit tracking-tight text-white flex items-center">
                    订阅轨迹与行为审计面板
                </h1>
            </div>
            
            <div class="mt-4 md:mt-0 flex items-center space-x-3">
                <button @click="fetchData" :disabled="loading" class="px-4 py-2 text-sm font-semibold rounded-xl bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white transition-all shadow-lg shadow-indigo-600/20 flex items-center space-x-2 disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg v-if="loading" class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>{{ loading ? '抓内鬼中...' : '立即扫描' }}</span>
                </button>
            </div>
        </header>

        <!-- 安全策略与蜜罐配置折叠卡片 -->
        <details class="group glass-card rounded-2xl p-6 mb-8 animate-fade-in" open>
            <summary class="text-sm font-bold text-indigo-400 cursor-pointer hover:text-indigo-300 transition-all flex items-center justify-between outline-none select-none">
                <span class="flex items-center space-x-2">
                    <span>🛡️ 天阙安全防线与无感掉包配置</span>
                    <span class="px-2 py-0.5 text-[10px] font-semibold bg-indigo-500/10 text-indigo-300 border border-indigo-500/20 rounded-md">实时动态生效 (免重启)</span>
                </span>
                <svg class="w-4 h-4 transform group-open:rotate-180 transition-transform text-indigo-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"></path>
                </svg>
            </summary>
            
            <div class="mt-5 border-t border-white/5 pt-5 grid grid-cols-1 md:grid-cols-12 gap-6">
                <div class="md:col-span-4 flex flex-col justify-start">
                    <label class="text-xs text-slate-400 mb-2 font-medium">1. 拦截防御动作选择</label>
                    <div class="flex flex-col space-y-3">
                        <label class="flex items-center space-x-3 cursor-pointer text-xs text-slate-300">
                            <input type="radio" name="banned_strategy" value="bait" v-model="config.banned_strategy" class="w-4 h-4 text-indigo-600 bg-white/5 border-white/10" />
                            <span>👻 <b>下发蜜罐节点</b> (显示内鬼真实 IP，警示作用)</span>
                        </label>
                        <label class="flex items-center space-x-3 cursor-pointer text-xs text-slate-300">
                            <input type="radio" name="banned_strategy" value="redirect" v-model="config.banned_strategy" class="w-4 h-4 text-indigo-600 bg-white/5 border-white/10" />
                            <span>🔄 <b>302 重定向</b> (自动引流至指定订阅链接)</span>
                        </label>
                        <label class="flex items-center space-x-3 cursor-pointer text-xs text-slate-300">
                            <input type="radio" name="banned_strategy" value="banned" v-model="config.banned_strategy" class="w-4 h-4 text-indigo-600 bg-white/5 border-white/10" />
                            <span>🚫 <b>原生 403 阻断</b> (直接抛出账户封禁提示)</span>
                        </label>
                    </div>
                </div>

                <div class="md:col-span-6 flex flex-col justify-start">
                    <label class="text-xs text-slate-400 mb-2 font-medium">2. 重定向目标订阅 URL (当选择 302 重定向时生效)</label>
                    <input type="text" v-model="config.banned_redirect_url" class="px-4 py-2.5 text-xs rounded-xl bg-white/5 border border-white/10 text-white focus:outline-none focus:border-indigo-500/50 w-full font-mono" placeholder="输入公益订阅地址，例如 https://free-sub.top/api/v1/client/subscribe?token=..." />
                    <span class="text-[10px] text-slate-500 mt-2">※ 凡是 [已被封禁] 或是被放入 [无感灰名单] 的用户，拉取订阅时会自动掉包/重定向。</span>
                </div>

                <div class="md:col-span-2 flex items-end">
                    <button @click="saveConfig" :disabled="isSavingConfig" class="px-5 py-2.5 text-xs font-bold rounded-xl bg-violet-600 hover:bg-violet-500 active:bg-violet-700 text-white transition-all w-full shadow-lg shadow-violet-600/20 disabled:opacity-50">
                        {{ isSavingConfig ? '正在保存...' : '💾 保存防御配置' }}
                    </button>
                </div>
            </div>
        </details>

        <!-- Configuration Bar -->
        <section class="glass-card rounded-2xl p-6 mb-8">
            <!-- Mode Switcher -->
            <div class="flex border-b border-white/10 pb-4 mb-6 gap-6">
                <button @click="mode = 'detect'; fetchData()" :class="['pb-2 text-sm font-semibold transition-all border-b-2 outline-none', mode === 'detect' ? 'text-indigo-400 border-indigo-400' : 'text-slate-400 border-transparent hover:text-slate-200']">
                    🔍 模式一：捕获定时测活内鬼
                </button>
                <button @click="mode = 'all_low'; fetchData()" :class="['pb-2 text-sm font-semibold transition-all border-b-2 outline-none', mode === 'all_low' ? 'text-indigo-400 border-indigo-400' : 'text-slate-400 border-transparent hover:text-slate-200']">
                    📊 模式二：分析全部低流量活跃用户
                </button>
            </div>

            <!-- Parameters Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 items-end mb-6">
                <div class="flex flex-col">
                    <label class="text-xs text-slate-400 mb-1.5 font-medium">流量过滤上限阈值 (MB)</label>
                    <input type="number" v-model="maxTraffic" class="px-4 py-2 text-sm rounded-xl bg-white/5 border border-white/10 text-white focus:outline-none focus:border-indigo-500/50 w-full" placeholder="50" />
                </div>
                
                <div v-if="mode === 'detect'" class="flex flex-col">
                    <label class="text-xs text-slate-400 mb-1.5 font-medium">目标检测间隔 (秒)</label>
                    <input type="number" v-model="interval" class="px-4 py-2 text-sm rounded-xl bg-white/5 border border-white/10 text-white focus:outline-none focus:border-indigo-500/50 w-full" placeholder="300" />
                </div>
                
                <div v-if="mode === 'detect'" class="flex flex-col">
                    <label class="text-xs text-slate-400 mb-1.5 font-medium">时间极差容差 (秒)</label>
                    <input type="number" v-model="tolerance" class="px-4 py-2 text-sm rounded-xl bg-white/5 border border-white/10 text-white focus:outline-none focus:border-indigo-500/50 w-full" placeholder="30" />
                </div>

                <div class="flex flex-col">
                    <label class="text-xs text-slate-400 mb-1.5 font-medium">活跃时效范围选择</label>
                    <select v-model="timeLimit" @change="fetchData" class="px-4 py-2 text-sm rounded-xl bg-white/5 border border-white/10 text-white focus:outline-none focus:border-indigo-500/50 w-full cursor-pointer">
                        <option value="86400" class="bg-darkBg">24小时内拉取</option>
                        <option value="259200" class="bg-darkBg">3天内拉取 (默认)</option>
                        <option value="604800" class="bg-darkBg">7天内拉取</option>
                        <option value="2592000" class="bg-darkBg">30天内拉取</option>
                        <option value="0" class="bg-darkBg">全部历史留底记录（无时效限制）</option>
                    </select>
                </div>
            </div>

            <!-- Toggles and Time Settings -->
            <div class="border-t border-white/10 pt-5 mt-4">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                    <!-- Checkboxes & Options -->
                    <div class="flex flex-wrap items-center gap-6">
                        <label class="flex items-center space-x-3 cursor-pointer select-none">
                            <input type="checkbox" v-model="showExpired" @change="fetchData" class="w-4 h-4 rounded text-indigo-600 bg-white/5 border-white/10 focus:ring-indigo-500" />
                            <span class="text-xs text-slate-300 font-medium">显示套餐已过期账号 (抓过期测活)</span>
                        </label>

                        <label class="flex items-center space-x-3 cursor-pointer select-none">
                            <input type="checkbox" v-model="showBanned" @change="fetchData" class="w-4 h-4 rounded text-indigo-600 bg-white/5 border-white/10 focus:ring-indigo-500" />
                            <span class="text-xs text-slate-300 font-medium">显示已封禁账号 (审计残留探测)</span>
                        </label>
                        
                        <label class="flex items-center space-x-3 cursor-pointer select-none">
                            <input type="checkbox" v-model="abnormalUaOnly" @change="fetchData" class="w-4 h-4 rounded text-rose-600 bg-white/5 border-white/10 focus:ring-rose-500" />
                            <span class="text-xs text-rose-400 font-semibold">🚨 只看异常UA (命令行/curl/python)</span>
                        </label>

                        <label class="flex items-center space-x-3 cursor-pointer select-none">
                            <input type="checkbox" v-model="bypassWhitelist" @change="fetchData" class="w-4 h-4 rounded text-indigo-600 bg-white/5 border-white/10 focus:ring-indigo-500" />
                            <span class="text-xs text-slate-300 font-medium">审计天阙/MOMclash (不跳过白名单)</span>
                        </label>

                        <label class="flex items-center space-x-3 cursor-pointer select-none border-l border-white/10 pl-6">
                            <input type="checkbox" v-model="timeFilterEnable" @change="fetchData" class="w-4 h-4 rounded text-indigo-600 bg-white/5 border-white/10 focus:ring-indigo-500" />
                            <span class="text-xs font-semibold text-indigo-400">特定拉取时间段过滤</span>
                        </label>
                        
                        <div v-if="timeFilterEnable" class="flex items-center space-x-2 bg-white/5 px-3 py-1 rounded-lg border border-white/5">
                            <span class="text-xs text-slate-400">目标时刻:</span>
                            <input type="text" v-model="timeTarget" class="px-2 py-0.5 text-xs rounded bg-white/5 border border-white/10 text-white focus:outline-none w-16 text-center font-mono" placeholder="08:00" />
                            
                            <span class="text-xs text-slate-400">浮动:</span>
                            <input type="number" v-model="timeRangeMin" class="px-2 py-0.5 text-xs rounded bg-white/5 border border-white/10 text-white focus:outline-none w-12 text-center" placeholder="15" />
                            <span class="text-xs text-slate-400">分钟</span>
                        </div>
                    </div>

                    <div class="flex justify-end shrink-0">
                        <button @click="fetchData" class="px-5 py-2 text-sm font-semibold rounded-xl bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-300 hover:text-white transition-all border border-indigo-500/30">
                            应用参数并重新扫描
                        </button>
                    </div>
                </div>
            </div>

            <!-- Helpful Strategy Explainer -->
            <div class="text-xs text-slate-400 leading-relaxed mt-5 bg-white/5 border border-white/5 p-4 rounded-xl">
                📌 <span class="font-medium text-slate-300">过滤器状态：</span>
                当前模式下，总流量高于 <b>{{ maxTraffic }} MB</b> 的用户已被隐藏过滤。
                <span v-if="showExpired" class="text-amber-400">已启用过期账户探测；</span>
                <span v-else>已隐藏过期账户；</span>
                <span v-if="showBanned" class="text-rose-400">已包含被封禁账号；</span>
                <span v-else>已隐藏封禁账号；</span>
                <span v-if="abnormalUaOnly" class="text-rose-400 font-bold">已启用硬降维打击（仅检索含有 curl、python、requests 等命令行 UA 的连接记录）；</span>
                <span v-if="timeFilterEnable" class="text-indigo-400">
                    (已启用高级时段过滤，限 <b>{{ getFormattedRange() }}</b> 之间。)
                </span>
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
                <p class="text-sm text-slate-400">正在进行时空轨迹与客户端UA类型关联关联分析...</p>
            </div>

            <!-- Empty State -->
            <div v-if="!loading && users.length === 0" class="glass-card rounded-2xl p-12 text-center flex flex-col items-center justify-center border-emerald-500/10">
                <div class="w-16 h-16 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400 mb-4 shadow-lg shadow-emerald-500/5">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"></path>
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-white mb-2">未发现匹配条件的订阅用户</h3>
                <p class="text-sm text-slate-400 max-w-sm">调大流量过滤限制、关闭过滤开关或切换检测条件以查看更多轨迹。</p>
            </div>

            <!-- Bot User Cards Grid -->
            <div v-if="users.length > 0" class="grid grid-cols-1 gap-6">
                <div v-for="user in users" :key="user.id" class="glass-card rounded-2xl p-6 transition-all duration-300 hover:border-white/15 flex flex-col justify-between">
                    <div>
                        <!-- Card Header Info -->
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-white/5 mb-4">
                            <div>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs text-slate-400 font-semibold outfit uppercase">用户 ID: {{ user.id }}</span>
                                    <span v-if="user.is_banned" class="px-2 py-0.5 text-[10px] font-bold bg-rose-600/30 text-rose-400 border border-rose-500/50 rounded animate-pulse">
                                        已封禁 (Banned)
                                    </span>
                                    <span v-if="user.is_expired" class="px-2 py-0.5 text-[10px] font-bold bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded">
                                        已过期
                                    </span>
                                </div>
                                <h3 class="text-lg font-bold text-white tracking-wide mt-0.5">{{ user.email }}</h3>
                            </div>
                            
                            <!-- Badges -->
                            <div class="flex flex-wrap gap-2">
                                <span v-if="user.is_honeypot" class="px-2.5 py-1 text-xs font-bold bg-violet-600/30 text-violet-400 border border-violet-500/50 rounded-lg">
                                    👻 灰名单蜜罐中
                                </span>
                                <span v-if="user.has_abnormal_ua" class="px-2.5 py-1 text-xs font-black bg-rose-600/30 text-rose-400 border border-rose-500/50 rounded-lg">
                                    ⚙️ 命令行探测 (curl等)
                                </span>
                                <span v-if="user.is_expired" class="px-2.5 py-1 text-xs font-bold bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg">
                                    ⚠️ 订阅已过期 ({{ user.expired_at_formatted }})
                                </span>
                                <span v-else-if="!user.is_banned" class="px-2.5 py-1 text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded-lg">
                                    正常计费中
                                </span>
                                <span class="px-2.5 py-1 text-xs font-medium bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 rounded-lg">
                                    流量已用: {{ user.total_traffic_formatted }}
                                </span>
                                <span v-if="user.average_interval > 0" class="px-2.5 py-1 text-xs font-medium bg-slate-500/10 text-slate-300 border border-slate-500/20 rounded-lg">
                                    平均间隔: {{ user.average_interval }} 秒 (~{{ Math.round(user.average_interval / 60) }}分钟)
                                </span>
                            </div>
                        </div>

                        <!-- 订阅组及购买时间元数据 -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4 text-xs bg-indigo-950/25 border border-indigo-500/10 p-3.5 rounded-xl">
                            <div class="flex items-center space-x-2">
                                <span class="text-slate-400">🏷️ 当前订阅组:</span>
                                <span class="font-semibold text-indigo-300">{{ user.group_name }}</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="text-slate-400">📅 初次购买时间:</span>
                                <span class="font-mono text-slate-300">{{ user.first_buy_time }}</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="text-slate-400">🔄 最近续费时间:</span>
                                <span class="font-mono text-slate-300">{{ user.last_buy_time }}</span>
                            </div>
                        </div>

                        <!-- Card Meta Logs (IP and UA) -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4 text-sm bg-white/5 border border-white/5 p-4 rounded-xl">
                            <div>
                                <span class="text-xs text-slate-400 block mb-1">订阅拉取公网 IP (点击可查询归属地)：</span>
                                <div class="flex flex-wrap gap-2">
                                    <template v-if="user.ips && user.ips.length > 0">
                                        <a v-for="ip in user.ips" :key="ip" :href="'https://ipinfo.io/' + ip" target="_blank" class="px-2 py-1 bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-400 text-xs rounded-md border border-indigo-500/20 transition-all font-mono">
                                            {{ ip }} ↗
                                        </a>
                                    </template>
                                    <span v-else class="text-xs text-slate-500 italic font-mono">暂无记录</span>
                                </div>
                            </div>
                            <div>
                                <span class="text-xs text-slate-400 block mb-1">使用客户端 (User-Agent)：</span>
                                <div class="flex flex-wrap gap-2">
                                    <template v-if="user.uas && user.uas.length > 0">
                                        <span v-for="ua in user.uas" :key="ua" :class="['px-2 py-1 text-xs rounded-md font-mono border', ua.toLowerCase().includes('curl') || ua.toLowerCase().includes('python') ? 'bg-rose-500/10 text-rose-400 border-rose-500/20 font-bold' : 'bg-slate-500/10 text-slate-300 border-slate-500/20']">
                                            {{ ua }}
                                        </span>
                                    </template>
                                    <span v-else class="text-xs text-slate-500 italic font-mono">暂无记录</span>
                                </div>
                            </div>
                        </div>

                        <!-- Sub-history Details (Collapse) -->
                        <details class="group mb-4" open>
                            <summary class="text-xs font-semibold text-indigo-400/90 cursor-pointer hover:text-indigo-400 transition-all flex items-center space-x-1 outline-none select-none">
                                <span>最新订阅拉取历史日志与轨迹</span>
                                <svg class="w-3 h-3 transform group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"></path>
                                </svg>
                            </summary>
                            
                            <div class="mt-3 overflow-hidden border border-white/5 rounded-xl bg-black/20">
                                <table class="w-full text-left text-xs">
                                    <thead>
                                        <tr class="bg-white/5 text-slate-400 border-b border-white/5">
                                            <th class="px-4 py-2 font-medium">拉取时间</th>
                                            <th class="px-4 py-2 font-medium">UA 设备</th>
                                            <th class="px-4 py-2 font-medium">拉取者公网 IP</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/5">
                                        <tr v-for="(h, idx) in user.history" :key="idx" class="hover:bg-white/5">
                                            <td class="px-4 py-2 text-slate-300 font-mono">{{ h.time }}</td>
                                            <td class="px-4 py-2">
                                                <span :class="['px-1.5 py-0.5 rounded border text-[10px]', h.type.toLowerCase().includes('curl') || h.type.toLowerCase().includes('python') ? 'bg-rose-500/10 text-rose-400 border-rose-500/20 font-bold' : 'bg-white/5 border border-white/5']">
                                                    {{ h.type }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 text-indigo-300 font-mono">{{ h.ip || '无记录' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </div>

                    <!-- Actions Footer -->
                    <div class="flex items-center space-x-3 pt-4 border-t border-white/5 mt-2">
                        <button @click="handleReset(user)" class="flex-1 px-4 py-2 text-xs font-semibold text-amber-400 bg-amber-500/10 hover:bg-amber-500/15 active:bg-amber-500/20 border border-amber-500/20 rounded-xl transition-all">
                            🔄 重置 Token (失效)
                        </button>
                        <button @click="handleToggleHoneypot(user)" :class="['flex-1 px-4 py-2 text-xs font-semibold rounded-xl transition-all border', user.is_honeypot ? 'bg-violet-600/30 hover:bg-violet-600/40 text-violet-300 border-violet-500/40' : 'bg-violet-500/10 hover:bg-violet-500/15 text-violet-400 border-violet-500/20']">
                            {{ user.is_honeypot ? '😇 移出灰名单' : '👻 一键无感灰名单' }}
                        </button>
                        <button @click="handleBan(user)" :class="['flex-1 px-4 py-2 text-xs font-semibold rounded-xl transition-all border shadow-lg', user.is_banned ? 'bg-emerald-500/10 hover:bg-emerald-500/15 text-emerald-400 border-emerald-500/20' : 'bg-rose-600 hover:bg-rose-500 active:bg-rose-700 text-white border-transparent shadow-rose-600/10']">
                            {{ user.is_banned ? '✅ 一键解封' : '🚨 一键封禁 (Banned)' }}
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
                const maxTraffic = ref(5000); 
                const mode = ref('detect'); // detect | all_low
                const bypassWhitelist = ref(false); // 是否跳过白名单

                // 进阶特定时段过滤前端响应
                const timeFilterEnable = ref(false);
                const timeTarget = ref('08:00');
                const timeRangeMin = ref(15);

                // 进阶不正常UA、封禁与过期用户过滤
                const showExpired = ref(false); // 默认不显示过期
                const showBanned = ref(false); // 默认不显示已封禁
                const abnormalUaOnly = ref(true); // 默认只看命令行异常UA (改为 true)
                
                // 新增：时效限制绑定变量，默认修改为 259200 (3天)
                const timeLimit = ref(259200);

                // 获取地址栏的安全 token
                const urlParams = new URLSearchParams(window.location.search);
                const token = urlParams.get('token') || '';

                const toast = ref({ show: false, message: '', type: 'info' });
                
                // --- 🛡️ 天阙安全与蜜罐配置状态 ---
                const config = ref({ banned_strategy: 'bait', banned_redirect_url: '', honeypot_users: [] });
                const isSavingConfig = ref(false);

                const showToast = (message, type = 'info') => {
                    toast.value.message = message;
                    toast.value.type = type;
                    toast.value.show = true;
                    setTimeout(() => {
                        toast.value.show = false;
                    }, 3000);
                };

                const getFormattedRange = () => {
                    if (!timeTarget.value) return '07:45 - 08:15';
                    try {
                        const [h, m] = timeTarget.value.split(':').map(Number);
                        const range = Number(timeRangeMin.value || 15);
                        
                        let minDate = new Date();
                        minDate.setHours(h, m - range, 0);
                        
                        let maxDate = new Date();
                        maxDate.setHours(h, m + range, 0);
                        
                        const formatTime = (d) => {
                            const hr = String(d.getHours()).padStart(2, '0');
                            const mn = String(d.getMinutes()).padStart(2, '0');
                            return `${hr}:${mn}`;
                        };
                        return `${formatTime(minDate)} 至 ${formatTime(maxDate)}`;
                    } catch (e) {
                        return '输入范围有误';
                    }
                };

                const fetchConfig = async () => {
                    try {
                        const response = await fetch(`tianque_detect.php?action=get_config&token=${token}`);
                        const res = await response.json();
                        config.value = res;
                    } catch (err) {
                        showToast('获取天阙防御配置失败', 'error');
                    }
                };

                const saveConfig = async () => {
                    isSavingConfig.value = true;
                    try {
                        const formData = new FormData();
                        formData.append('banned_strategy', config.value.banned_strategy);
                        formData.append('banned_redirect_url', config.value.banned_redirect_url);
                        const response = await fetch(`tianque_detect.php?action=save_config&token=${token}`, {
                            method: 'POST',
                            body: formData
                        });
                        const res = await response.json();
                        if (res.ok) {
                            showToast('天阙防御与蜜罐配置保存成功，实时生效！', 'success');
                            fetchConfig();
                        } else {
                            showToast('保存失败', 'error');
                        }
                    } catch (err) {
                        showToast('网络请求失败', 'error');
                    } finally {
                        isSavingConfig.value = false;
                    }
                };

                const handleToggleHoneypot = async (user) => {
                    const isAdding = !user.is_honeypot;
                    const confirmMsg = isAdding
                        ? `确定要把用户 ${user.email} (ID: ${user.id}) 加入【无感灰名单】吗？\n加入后，他拉取订阅会被自动掉包为蜜罐节点或重定向，但他账号登录保持正常。`
                        : `确定要把用户 ${user.email} (ID: ${user.id}) 移出【无感灰名单】吗？`;
                    if (!confirm(confirmMsg)) return;
                    try {
                        const formData = new FormData();
                        formData.append('id', user.id);
                        const response = await fetch(`tianque_detect.php?action=toggle_honeypot&token=${token}`, {
                            method: 'POST',
                            body: formData
                        });
                        const res = await response.json();
                        if (res.ok) {
                            showToast(res.action === 'added' ? '已成功加入灰名单' : '已成功移出灰名单', 'success');
                            fetchData();
                            fetchConfig();
                        } else {
                            showToast('灰名单修改失败', 'error');
                        }
                    } catch (err) {
                        showToast('网络请求失败', 'error');
                    }
                };

                const fetchData = async () => {
                    loading.value = true;
                    try {
                        const query = `tianque_detect.php?action=fetch&token=${token}&interval=${interval.value}&tolerance=${tolerance.value}&max_traffic=${maxTraffic.value}&mode=${mode.value}&bypass_whitelist=${bypassWhitelist.value}&time_filter_enable=${timeFilterEnable.value}&time_target=${timeTarget.value}&time_range_min=${timeRangeMin.value}&show_expired=${showExpired.value}&show_banned=${showBanned.value}&abnormal_ua_only=${abnormalUaOnly.value}&time_limit=${timeLimit.value}`;
                        const response = await fetch(query);
                        const res = await response.json();
                        users.value = res.data;
                        showToast(`扫描完毕，共获取 ${users.value.length} 个分析样本`, 'success');
                    } catch (err) {
                        showToast('获取数据失败，请确认密钥无误', 'error');
                    } finally {
                        loading.value = false;
                    }
                };

                const handleBan = async (user) => {
                    const isBanning = !user.is_banned;
                    const confirmMsg = isBanning
                        ? `确定要封禁用户 ${user.email} 吗？\n该操作会立即封锁其全部服务。`
                        : `确定要为用户 ${user.email} 解除封禁吗？`;
                    if (!confirm(confirmMsg)) return;
                    try {
                        const formData = new FormData();
                        formData.append('id', user.id);
                        const response = await fetch(`tianque_detect.php?action=ban&token=${token}`, {
                            method: 'POST',
                            body: formData
                        });
                        const res = await response.json();
                        if (res.ok) {
                            showToast(res.action === 'banned' ? `用户 ${user.email} 已封禁` : `用户 ${user.email} 已解除封禁`, 'success');
                            fetchData();
                        } else {
                            showToast('操作失败', 'error');
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
                    fetchConfig();
                });

                return {
                    loading,
                    users,
                    interval,
                    tolerance,
                    maxTraffic,
                    mode,
                    bypassWhitelist,
                    timeFilterEnable,
                    timeTarget,
                    timeRangeMin,
                    showExpired,
                    showBanned,
                    abnormalUaOnly,
                    timeLimit,
                    toast,
                    config,
                    isSavingConfig,
                    getFormattedRange,
                    fetchData,
                    fetchConfig,
                    saveConfig,
                    handleToggleHoneypot,
                    handleBan,
                    handleReset,
                    showToast
                }
            }
        }).mount('#app');
    </script>
</body>
</html>
