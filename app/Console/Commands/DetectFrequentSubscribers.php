<?php
 
namespace App\Console\Commands;
 
use Illuminate\Console\Command;
use App\Models\User;
use App\Services\TelegramService;
 
class DetectFrequentSubscribers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:detect-subscribers 
                            {--ban : 是否直接封禁检测到的异常用户} 
                            {--honeypot : 自动将用户加入天阙灰名单（蜜罐），触发重定向/诱导策略}
                            {--reset-token : 自动重置用户订阅 Token 和 UUID}
                            {--interval=300 : 定时测活检测的目标间隔秒数，默认 300 秒} 
                            {--tolerance=30 : 时间波动的容差秒数，默认 30 秒}
                            {--ip-limit=10 : 24小时内拉取订阅的独立 IP 数阈值，达到此数值判定为多IP滥用}
                            {--tg-chat= : 额外的 Telegram 接收 Chat ID（支持群组/频道/个人ID）}
                            {--tg-token= : 独立的 Telegram Bot Token（若不指定则自动调用系统内置 Bot）}
                            {--set-webhook : 自动向 Telegram 注册安全审计机器人的 Webhook 地址}
                            {--webhook-url= : 自定义 Webhook 的基准 URL（例如：https://tianquege.top），若不指定则默认使用 app_url}';
 
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '【天阙订阅审计中心】全方位排查高频定时测活、多IP共享扩散、异常UA爬取等内鬼和滥用行为';
 
    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }
 
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $ban = $this->option('ban');
        $honeypot = $this->option('honeypot');
        $resetToken = $this->option('reset-token');
        
        $targetInterval = (int) $this->option('interval');
        $tolerance = (int) $this->option('tolerance');
        $ipLimit = (int) $this->option('ip-limit');
 
        // 执行 Webhook 注册动作
        if ($this->option('set-webhook')) {
            $botToken = $this->option('tg-token') ?: env('SECURITY_TG_TOKEN');
            if (empty($botToken)) {
                $envPath = base_path('.env');
                if (file_exists($envPath)) {
                    $envContent = @file_get_contents($envPath);
                    if (preg_match('/^SECURITY_TG_TOKEN\s*=\s*(.*)$/m', $envContent, $matches)) {
                        $botToken = trim($matches[1], "\"' ");
                    }
                }
            }

            if (empty($botToken)) {
                $this->error("❌ 错误：请先在 .env 中配置 SECURITY_TG_TOKEN 机器人密钥，或使用 --tg-token 传参！");
                return 1;
            }

            $appUrl = $this->option('webhook-url') ?: config('v2board.app_url');
            if (empty($appUrl)) {
                $this->error("❌ 错误：未能在配置中找到 app_url，请先确保后台设置了正确的站点域名，或使用 --webhook-url 传参！");
                return 1;
            }

            $webhookUrl = rtrim($appUrl, '/') . '/api/v1/guest/security/webhook';
            $this->info("ℹ️ 正在向 Telegram 注册 Webhook: {$webhookUrl}");

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$botToken}/setWebhook");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'url' => $webhookUrl
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $res = curl_exec($ch);
            curl_close($ch);

            $response = json_decode($res, true);
            if (isset($response['ok']) && $response['ok'] === true) {
                $this->info("✅ Webhook 注册成功！响应: " . ($response['description'] ?? ''));
            } else {
                $this->error("❌ Webhook 注册失败！错误: " . ($res ?: '网络连接超时'));
            }
            return 0;
        }
 
        $this->info("==================================================================");
        $this->info("🔍 开始启动【天阙订阅安全审计扫描】...");
        $this->info("   定时时间目标间隔: {$targetInterval} 秒 (容差: ±{$tolerance} 秒)");
        $this->info("   24h独立IP阈值: {$ipLimit} 个");
        $this->info("==================================================================");
 
        // 扫描未封禁且拥有订阅记录的用户
        $users = User::where('banned', 0)
            ->whereNotNull('client_type')
            ->get();
 
        $detectedCount = 0;
        $now = time();
        $whitelistClients = ['天阙(TianQue)', 'Mclash', 'MOMclash'];
        $abnormalKeywords = [
            'curl', 'wget', 'python', 'python-requests', 'go-http', 'go-http-client', 'urllib', 'httpclient', 'postman', 'aria2',
            'ClashMetaForAndroid/733', 'clash-verge/v2.3.1', 'clash'
        ];
 
        // 读取现有的天阙配置、灰名单（蜜罐）用户与白名单用户，用于跳过已处置或白名单用户，防止重复报警
        $configPath = storage_path('tianque_config.json');
        $honeypotUsers = [];
        $whitelistUsers = [];
        $auditUaEnabled = true;
        if (file_exists($configPath)) {
            $tianqueConfig = json_decode(@file_get_contents($configPath), true);
            if (is_array($tianqueConfig)) {
                if (isset($tianqueConfig['honeypot_users'])) {
                    $honeypotUsers = array_map('intval', $tianqueConfig['honeypot_users']);
                }
                if (isset($tianqueConfig['whitelist_users'])) {
                    $whitelistUsers = $tianqueConfig['whitelist_users'];
                }
                if (isset($tianqueConfig['ip_limit'])) {
                    $ipLimit = (int)$tianqueConfig['ip_limit'];
                }
                if (isset($tianqueConfig['audit_ua_enabled'])) {
                    $auditUaEnabled = (bool)$tianqueConfig['audit_ua_enabled'];
                }
                if (isset($tianqueConfig['audit_ua_keywords']) && is_array($tianqueConfig['audit_ua_keywords'])) {
                    $abnormalKeywords = $tianqueConfig['audit_ua_keywords'];
                }
            }
        }
        $abnormalKeywordsLower = array_map('strtolower', $abnormalKeywords);
 
        // --------------------------------------------------
        // 全局前置计算：24小时内所有用户的 IP 共用映射，用以排查海外 IP 联合探测行为
        // --------------------------------------------------
        $this->info("ℹ️ 正在分析全站 IP 共用映射关系...");
        $allActiveUsers = User::where('banned', 0)
            ->whereNotNull('client_type')
            ->get(['id', 'email', 'client_type']);
            
        $ipUserMap = [];
        $now = time();
        foreach ($allActiveUsers as $u) {
            // 排除白名单
            $isInWhitelist = false;
            foreach ($whitelistUsers as $wlItem) {
                if ($u->id == $wlItem || strtolower($u->email) === strtolower(trim($wlItem))) {
                    $isInWhitelist = true;
                    break;
                }
            }
            if ($isInWhitelist) continue;

            $hist = json_decode($u->client_type, true) ?: [];
            foreach ($hist as $log) {
                if (($now - ($log['time'] ?? 0)) < 86400) {
                    $ip = trim($log['ip'] ?? '');
                    if (!empty($ip) && $ip !== '127.0.0.1') {
                        if (!isset($ipUserMap[$ip])) {
                            $ipUserMap[$ip] = [];
                        }
                        if (!in_array((int)$u->id, $ipUserMap[$ip], true)) {
                            $ipUserMap[$ip][] = (int)$u->id;
                        }
                    }
                }
            }
        }

        $sharedOverseasIps = [];
        $userEmailMap = $allActiveUsers->pluck('email', 'id')->toArray();
        foreach ($ipUserMap as $ip => $uids) {
            if (count($uids) >= $ipLimit) {
                $loc = $this->getIpLocation($ip);
                $isOverseas = true;
                foreach (['中国', '本地局域网'] as $chinaKw) {
                    if (strpos($loc, $chinaKw) !== false) {
                        $isOverseas = false;
                        break;
                    }
                }
                
                if ($isOverseas) {
                    foreach ($uids as $uid) {
                        if (!isset($sharedOverseasIps[$uid])) {
                            $sharedOverseasIps[$uid] = [];
                        }
                        $otherEmails = [];
                        foreach ($uids as $otherUid) {
                            if ($otherUid !== $uid) {
                                $otherEmails[] = $userEmailMap[$otherUid] ?? '未知用户';
                            }
                        }
                        $sharedOverseasIps[$uid][] = [
                            'ip' => $ip,
                            'location' => $loc,
                            'others' => $otherEmails
                        ];
                    }
                }
            }
        }

        foreach ($users as $user) {
            // 如果用户已经在天阙灰名单（蜜罐）中，则直接跳过，避免重复报警和重复处理
            if (in_array((int)$user->id, $honeypotUsers, true)) {
                continue;
            }

            // 如果用户在白名单中，则直接跳过，不做任何审计预警
            $isInWhitelist = false;
            foreach ($whitelistUsers as $wlItem) {
                if ($user->id == $wlItem || strtolower($user->email) === strtolower(trim($wlItem))) {
                    $isInWhitelist = true;
                    break;
                }
            }
            if ($isInWhitelist) {
                continue;
            }
 
            $history = json_decode($user->client_type, true);
            
            // 记录为空或过少跳过
            if (!is_array($history) || count($history) < 1) {
                continue;
            }
 
            // 过滤白名单：若所有客户端均为天阙等官方客户端，跳过
            $allWhitelist = true;
            foreach ($history as $item) {
                if (!in_array($item['type'], $whitelistClients)) {
                    $allWhitelist = false;
                    break;
                }
            }
            if ($allWhitelist) {
                continue;
            }
 
            $reasons = [];
            
            // --------------------------------------------------
            // 维度 1：24小时内多 IP 扩散滥用检测（账号分享）
            // --------------------------------------------------
            $ipsIn24h = [];
            foreach ($history as $item) {
                if (!empty($item['ip']) && isset($item['time'])) {
                    if ($now - $item['time'] <= 86400) {
                        $ipsIn24h[] = $item['ip'];
                    }
                }
            }
            $uniqueIps = array_values(array_unique($ipsIn24h));
            $ipCount = count($uniqueIps);
            
            if ($ipCount >= $ipLimit) {
                $reasons[] = "24h内使用 {$ipCount} 个独立 IP 拉取订阅 (IP列表: " . implode(', ', $uniqueIps) . ")";
            }
 
            // --------------------------------------------------
            // 维度 2：定时高频规律拉取检测（测活爬虫/挂机）
            // --------------------------------------------------
            $isRegular = false;
            $averageInterval = 0;
            $range = 0;
            if (count($history) >= 5) {
                // 确保历史按时间降序排列
                usort($history, function($a, $b) {
                    return $b['time'] <=> $a['time'];
                });
                
                // 检查最近一次拉取是否在 24 小时内，若太久不活跃则不判定规律性
                if ($now - $history[0]['time'] <= 86400) {
                    $diffs = [];
                    for ($i = 0; $i < count($history) - 1; $i++) {
                        $diff = $history[$i]['time'] - $history[$i + 1]['time'];
                        if ($diff > 0) {
                            $diffs[] = $diff;
                        }
                    }
                    
                    if (count($diffs) > 0) {
                        $averageInterval = array_sum($diffs) / count($diffs);
                        $maxInterval = max($diffs);
                        $minInterval = min($diffs);
                        $range = $maxInterval - $minInterval;
 
                        $isTargetInterval = abs($averageInterval - $targetInterval) <= $tolerance;
                        $isExtremelyRegular = $range <= $tolerance;
                        $isGeneralFastRegular = $averageInterval <= 600 && $range <= 15;
 
                        if (($isTargetInterval && $isExtremelyRegular) || $isGeneralFastRegular) {
                            $isRegular = true;
                            $reasons[] = "定时高频拉取测活 (平均间隔: " . round($averageInterval) . " 秒，波动极差: {$range} 秒)";
                        }
                    }
                }
            }
 
            // --------------------------------------------------
            // 维度 3：敏感开发工具 UA 检测（订阅爬虫扫描）
            // --------------------------------------------------
            $hasAbnormalUa = false;
            $abnormalUaName = '';
            if ($auditUaEnabled) {
                foreach ($history as $item) {
                    $ua = $item['ua'] ?? ($item['type'] ?? '');
                    $uaLower = strtolower($ua);
                    $cleanUa = preg_replace('/[^a-z0-9\/_\-\.]/', ' ', $uaLower);
                    $segments = array_filter(explode(' ', $cleanUa));

                    foreach ($abnormalKeywordsLower as $kw) {
                        if (preg_match('/[^a-z0-9\/_\-\.]/', $kw)) {
                            // 1. 如果关键字中含有非单词标识符（如空格、括号等），说明是长特征片段，执行模糊包含匹配
                            if (strpos($uaLower, $kw) !== false) {
                                $hasAbnormalUa = true;
                                $abnormalUaName = $item['ua'] ?? $item['type'];
                                break 2;
                            }
                        } else {
                            // 2. 如果是纯单词/单产品名，执行高精准的分词匹配，以防 clash 误伤 clash-verge/FlClash 等
                            foreach ($segments as $seg) {
                                if ($seg === $kw || strpos($seg, $kw . '/') === 0) {
                                    $hasAbnormalUa = true;
                                    $abnormalUaName = $item['ua'] ?? $item['type'];
                                    break 3;
                                }
                            }
                        }
                    }
                }
            }
            if ($hasAbnormalUa) {
                $reasons[] = "敏感的命令行或开发库 UA 请求 (检测到: {$abnormalUaName})";
            }
 
            // --------------------------------------------------
            // 维度 4：画像异常 - 只拉订阅不跑流量
            // --------------------------------------------------
            $pullCountLast24h = 0;
            foreach ($history as $hItem) {
                if (!empty($hItem['time']) && ($now - $hItem['time']) < 86400) {
                    $pullCountLast24h++;
                }
            }
            $totalTrafficMB = ($user->u + $user->d) / 1024 / 1024;
            if ($pullCountLast24h >= 4 && $totalTrafficMB < 100) {
                $reasons[] = "只拉订阅不跑流量 (24h拉取 {$pullCountLast24h} 次，本月总流量仅为 " . round($totalTrafficMB, 2) . " MB)";
            }

            // --------------------------------------------------
            // 维度 5：画像异常 - 共用海外 IP 联合探测预警
            // --------------------------------------------------
            if (isset($sharedOverseasIps[$user->id])) {
                foreach ($sharedOverseasIps[$user->id] as $info) {
                    $reasons[] = "共用海外IP预警: 24h内与用户 [" . implode(', ', $info['others']) . "] 共用海外 IP {$info['ip']} ({$info['location']}) 拉取订阅 (疑似多账号联合探测)";
                }
            }

            // --------------------------------------------------
            // 维度 6：分布式异地探测画像 - 24h内使用多省份家宽IP高频拉取 (不限 UA/IP 属性)
            // --------------------------------------------------
            $logs24h = array_filter($history, function($h) use ($now) {
                return ($now - ($h['time'] ?? 0)) <= 86400;
            });
            $ips = [];
            foreach ($logs24h as $log) {
                $ip = trim($log['ip'] ?? '');
                if (!empty($ip) && $ip !== '127.0.0.1') {
                    $ips[] = $ip;
                }
            }
            $uniqueIps = array_values(array_unique($ips));
            if (count($uniqueIps) >= 5) {
                $regions = [];
                foreach ($uniqueIps as $ip) {
                    $loc = $this->getIpLocation($ip);
                    if (strpos($loc, '中国') !== false || strpos($loc, '本地局域网') !== false) {
                        $locClean = preg_replace('/[^\x{4e00}-\x{9fa5}a-zA-Z]/u', '', $loc);
                        $regionName = '';
                        foreach (['北京', '上海', '天津', '重庆', '河北', '山西', '辽宁', '吉林', '黑龙江', '江苏', '浙江', '安徽', '福建', '江西', '山东', '河南', '湖北', '湖南', '广东', '海南', '四川', '贵州', '云南', '陕西', '甘肃', '青海', '台湾', '内蒙古', '广西', '西藏', '宁夏', '新疆', '香港', '澳门'] as $prov) {
                            if (strpos($locClean, $prov) !== false) {
                                $regionName = $prov;
                                break;
                            }
                        }
                        if ($regionName) {
                            $regions[] = $regionName;
                        }
                    }
                }
                $uniqueRegions = array_unique($regions);
                if (count($uniqueRegions) >= 5) {
                    $reasons[] = "分布式异地探测画像: 24h内使用多省份家宽IP高频拉取 (覆盖省份: " . implode(', ', $uniqueRegions) . ")";
                }
            }

            // --------------------------------------------------
            // 维度 7：国内机房服务器拉取探测 (阿里云、腾讯云、机房 IP 等)
            // --------------------------------------------------
            $isIdcSpy = false;
            $idcSpyReason = '';
            $idcKeywords = [
                '阿里云', '腾讯云', '华为云', '百度云', '京东云', '网易云', '金山云', '天翼云', '联通云', '移动云',
                'aliyun', 'alibaba', 'tencent', 'huawei', 'baidu', 'ucloud', 'qcloud', 'ksyun', '美团云', '青云',
                'chinacicc', 'capitalonline', '数据中心', '机房', '世纪互联', '光环新网', '网宿', '蓝汛'
            ];
            foreach ($uniqueIps as $ip) {
                $loc = $this->getIpLocation($ip);
                $isChina = (strpos($loc, '中国') !== false || strpos($loc, '局域网') !== false);
                $isHongKongOrMacauOrTaiwan = (strpos($loc, '香港') !== false || strpos($loc, '澳门') !== false || strpos($loc, '台湾') !== false);
                if ($isChina && !$isHongKongOrMacauOrTaiwan) {
                    $locLower = strtolower($loc);
                    foreach ($idcKeywords as $kw) {
                        if (strpos($locLower, $kw) !== false) {
                            $isIdcSpy = true;
                            $idcSpyReason = "国内机房服务器拉取订阅 (IP: {$ip}, 归属: {$loc})";
                            break 2;
                        }
                    }
                }
            }
            if ($isIdcSpy) {
                $reasons[] = $idcSpyReason;
            }

            // --------------------------------------------------
            // 处置与告警逻辑
            // --------------------------------------------------
            if (count($reasons) > 0) {
                $detectedCount++;
                
                $this->warn("------------------------------------------------------------------");
                $this->warn("⚠️ 捕获到异常订阅拉取用户 [ID: {$user->id}]");
                $this->line("   邮箱: {$user->email}");
                foreach ($reasons as $reason) {
                    $this->line("   🚨 触发原因: {$reason}");
                }
 
                // 组合执行的处置动作
                $actions = [];
                
                if ($ban) {
                    $user->banned = 1;
                    $actions[] = "【封禁账户】";
                }
                
                if ($honeypot) {
                    $config = [];
                    if (file_exists($configPath)) {
                        $config = json_decode(@file_get_contents($configPath), true) ?: [];
                    }
                    if (!isset($config['honeypot_users']) || !is_array($config['honeypot_users'])) {
                        $config['honeypot_users'] = [];
                    }
                    if (!isset($config['honeypot_times']) || !is_array($config['honeypot_times'])) {
                        $config['honeypot_times'] = [];
                    }
                    $currentHoneypots = array_map('intval', $config['honeypot_users']);
                    if (!in_array((int)$user->id, $currentHoneypots, true)) {
                        $currentHoneypots[] = (int)$user->id;
                        $config['honeypot_users'] = $currentHoneypots;
                        $config['honeypot_times'][(string)$user->id] = time();
                        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                        // 动态更新缓存，确保在后续运行中能被跳过
                        $honeypotUsers[] = (int)$user->id;
                    }
                    $actions[] = "【加入天阙蜜罐灰名单】";
                }
                
                if ($resetToken) {
                    $oldToken = $user->token;
                    $user->token = $this->generateGuid();
                    $user->uuid = $this->generateGuid(false);
                    $actions[] = "【重置订阅 Token】";
                }

                // --------------------------------------------------
                // 记录到重点观察名单 (flagged_users)
                // --------------------------------------------------
                $config = [];
                if (file_exists($configPath)) {
                    $config = json_decode(@file_get_contents($configPath), true) ?: [];
                }
                if (!isset($config['flagged_users']) || !is_array($config['flagged_users'])) {
                    $config['flagged_users'] = [];
                }
                $config['flagged_users'][(string)$user->id] = [
                    'email' => $user->email,
                    'time' => time(),
                    'reasons' => $reasons
                ];
                @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                $actionTakenStr = count($actions) > 0 ? implode(' + ', $actions) : "无（仅审计记录）";
                
                if (count($actions) > 0) {
                    $user->save();
                    $this->error("   ⚡ 自动处置结果: {$actionTakenStr}");
                }
 
                // --------------------------------------------------
                // 查询并提取用户详细信息
                // --------------------------------------------------
                $registerTime = $user->created_at ? date('Y-m-d H:i:s', $user->created_at) : '未知';
                $expireTime = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效';
                $balanceStr = ($user->balance / 100) . ' 元';
                $commissionStr = ($user->commission_balance / 100) . ' 元';
                $lastOnline = $user->t > 0 ? date('Y-m-d H:i:s', $user->t) : '无在线记录';

                // 获取最近一次续费时间
                $lastOrder = \DB::table('v2_order')
                    ->where('user_id', $user->id)
                    ->where('status', 3)
                    ->orderBy('id', 'desc')
                    ->first();
                $lastRenewTime = $lastOrder ? date('Y-m-d H:i:s', $lastOrder->created_at) : '无续费记录';

                // 获取最近一次面板登录 IP 和登录时间
                $authService = new \App\Services\AuthService($user);
                $sessions = $authService->getSessions();
                $lastLoginIp = '无登录记录';
                $lastLoginTime = '无登录记录';
                $lastLoginLocation = '';
                if (!empty($sessions) && is_array($sessions)) {
                    uasort($sessions, function($a, $b) {
                        return ($b['login_at'] ?? 0) <=> ($a['login_at'] ?? 0);
                    });
                    $latestSession = reset($sessions);
                    $lastLoginIp = $latestSession['ip'] ?? '未知';
                    $lastLoginTime = isset($latestSession['login_at']) ? date('Y-m-d H:i:s', $latestSession['login_at']) : '未知';
                    if ($lastLoginIp !== '未知' && $lastLoginIp !== '无登录记录') {
                        $lastLoginLocation = " (" . $this->getIpLocation($lastLoginIp) . ")";
                    }
                }

                // --------------------------------------------------
                // 发送 Telegram 告警推送
                // --------------------------------------------------
                $historyDetails = collect($history)->map(function ($item) {
                    $ip = $item['ip'] ?? '';
                    $ipStr = '';
                    if (!empty($ip)) {
                        $location = $this->getIpLocation($ip);
                        $ipStr = " [IP: {$ip} ({$location})]";
                    }
                    return date('m-d H:i:s', $item['time']) . " ({$item['type']}){$ipStr}";
                })->join("\n        -> ");
 
                $tgMessage = "⚠️ 【天阙安全审计警报】\n"
                           . "发现异常订阅拉取用户！\n\n"
                           . "👤 用户邮箱: `{$user->email}`\n"
                           . "🆔 用户 ID: `{$user->id}`\n"
                           . "💵 账户余额: `{$balanceStr}` | 推广佣金: `{$commissionStr}`\n"
                           . "📅 注册时间: `{$registerTime}`\n"
                           . "💳 续费时间: `{$lastRenewTime}`\n"
                           . "⌛ 到期时间: `{$expireTime}`\n"
                           . "📡 节点在线: `{$lastOnline}`\n"
                           . "💻 登录记录: `{$lastLoginTime}`\n"
                           . "🌐 登录 IP: `{$lastLoginIp}{$lastLoginLocation}`\n\n"
                           . "📊 触发规则:\n"
                           . "• " . implode("\n• ", $reasons) . "\n"
                           . "⚙️ 执行动作: {$actionTakenStr}\n"
                           . "📅 审计时间: " . date('Y-m-d H:i:s') . "\n"
                           . "📝 最近订阅拉取记录:\n"
                           . "        -> " . $historyDetails;
 
                $this->sendTelegramNotification($tgMessage, $user->id);
            }
        }
 
        $this->info("==================================================================");
        $this->info("🎉 扫描完成。本次共捕获并处置异常用户: {$detectedCount} 个");
        $this->info("==================================================================");
        
        return 0;
    }
 
    /**
     * 发送 Telegram 推送通知
     */
    private function sendTelegramNotification($message, $userId = null)
    {
        $customToken = $this->option('tg-token') ?: env('SECURITY_TG_TOKEN');
        $customChat = $this->option('tg-chat') ?: env('SECURITY_TG_CHAT');
 
        // 兼容 Laravel 配置缓存导致 env() 拿不到值的情况，直接从硬盘解析 .env 文件
        if (empty($customToken) || empty($customChat)) {
            $envPath = base_path('.env');
            if (file_exists($envPath)) {
                $envContent = @file_get_contents($envPath);
                if (!empty($envContent)) {
                    if (empty($customToken) && preg_match('/^SECURITY_TG_TOKEN\s*=\s*(.*)$/m', $envContent, $matches)) {
                        $customToken = trim($matches[1], "\"' ");
                    }
                    if (empty($customChat) && preg_match('/^SECURITY_TG_CHAT\s*=\s*(.*)$/m', $envContent, $matches)) {
                        $customChat = trim($matches[1], "\"' ");
                    }
                }
            }
        }
 
        // 优先使用命令传入的自定义 Bot/Chat
        if (!empty($customToken) && !empty($customChat)) {
            try {
                $url = "https://api.telegram.org/bot{$customToken}/sendMessage";
                $postData = [
                    'chat_id' => $customChat,
                    'text' => $message,
                    'parse_mode' => 'Markdown'
                ];

                // 如果提供了用户 ID，则下发操作按钮
                if ($userId !== null) {
                    $postData['reply_markup'] = json_encode([
                        'inline_keyboard' => [
                            [
                                ['text' => '🛡️ 放入蜜罐', 'callback_data' => "honeypot:{$userId}"],
                                ['text' => '🚫 封禁账号', 'callback_data' => "ban:{$userId}"],
                                ['text' => '🔄 重置订阅', 'callback_data' => "reset:{$userId}"]
                            ]
                        ]
                    ]);
                }

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt_array($ch, [
                    CURLOPT_POSTFIELDS => http_build_query($postData),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5
                ]);
                curl_exec($ch);
                curl_close($ch);
            } catch (\Exception $e) {
                $this->error("发送自定义 TG 推送失败: " . $e->getMessage());
            }
        } else {
            // 回退到 V2Board 系统内置的 Telegram 告警
            try {
                if (class_exists('App\Services\TelegramService')) {
                    $telegramService = new TelegramService();
                    $telegramService->sendMessageWithAdmin($message);
                }
            } catch (\Exception $e) {
                // 忽略系统推送失败
            }
        }
    }
 
    /**
     * 生成 GUID
     */
    private function generateGuid($trim = true)
    {
        $guid = sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
        return $trim ? str_replace('-', '', strtolower($guid)) : strtolower($guid);
    }

    /**
     * 获取 IP 的地理位置信息（缓存 24 小时）
     */
    private function getIpLocation($ip)
    {
        if (empty($ip) || $ip === '127.0.0.1' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '本地局域网';
        }

        $cacheKey = "ip_loc_" . md5($ip);
        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return \Illuminate\Support\Facades\Cache::get($cacheKey);
        }

        $url = "http://ip-api.com/json/{$ip}?lang=zh-CN";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        $res = curl_exec($ch);
        curl_close($ch);

        if ($res) {
            $data = json_decode($res, true);
            if (isset($data['status']) && $data['status'] === 'success') {
                $country = $data['country'] ?? '';
                $region = $data['regionName'] ?? '';
                $city = $data['city'] ?? '';
                $isp = $data['isp'] ?? '';
                $loc = "{$country} {$region} {$city}" . ($isp ? " ({$isp})" : "");
                \Illuminate\Support\Facades\Cache::put($cacheKey, $loc, 86400); // 缓存一天
                return $loc;
            }
        }

        return '未知地区';
    }
}
