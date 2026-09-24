<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Utils\IpHelper;

/**
 * 订阅风控中间件（全功能版）
 *
 * 记录方式：
 *  1. risk_logs 数据库表（含邮箱、User-Agent）
 *  2. Laravel 日志文件（storage/logs/risk-*.log）
 *  3. Telegram Bot 推送（响应后异步发送）
 *  4. Redis 累计风险计数（7 天滑动窗口）
 *  5. 高风险自动封禁（累计触发 >= BAN_THRESHOLD 次且单次评分 >= BAN_MIN_SCORE）
 */
class SubscribeRiskControl
{
    // ===================== 配置区 =====================

    // 频率限制
    const RATE_LIMIT    = 15;   // 时间窗口内最大请求次数
    const RATE_WINDOW   = 60;   // 时间窗口（秒）

    // 自动封禁：7 天内触发次数 >= BAN_THRESHOLD 且单次评分 >= BAN_MIN_SCORE 时封禁
    const BAN_THRESHOLD  = 5;   // 7 天内最多允许触发几次
    const BAN_MIN_SCORE  = 80;  // 触发封禁的最低单次评分，调高至 80（跨国 60 分只告警不自动封禁）
    const BAN_WINDOW     = 7;   // 累计统计窗口（天）

    // 静态变量缓存 Reader，防止常驻内存框架（Webman）在每个请求中重复打开大二进制文件
    private static $_reader = null;
    private static $_asnReader = null;

    // ==================================================



    

    // -------------------------------------------------------------------------
    // 动态积分状态机 (防同城多IP、防随机UA)
    // -------------------------------------------------------------------------

    private function calculateDynamicScore($user, string $ip, string $userAgent, array $geo, Request $request): void
    {
        try {
            $userId = $user->id;
            $stateKey = "sub_risk_state:{$userId}";
            $now = time();
            
            // 获取之前状态
            $stateJson = \Illuminate\Support\Facades\Redis::get($stateKey);
            $state = $stateJson ? json_decode($stateJson, true) : [
                'score' => 0,
                'last_time' => 0,
                'recent_ips' => [],
                'recent_uas' => [],
                'recent_provs' => []
            ];

            // 防止旧版数据结构报错，进行兼容并限制最大分数为99，防止爆表
            if (!isset($state['recent_ips'])) $state['recent_ips'] = [];
            if (!isset($state['recent_uas'])) $state['recent_uas'] = [];
            if (!isset($state['recent_provs'])) $state['recent_provs'] = [];
            if ($state['score'] > 99) $state['score'] = 99; // 强制兜底，清洗旧的爆表数据

            // 1. 时间衰减 (每小时衰减 10 分，加速正常用户清洗)
            if ($state['last_time'] > 0) {
                $hoursPassed = ($now - $state['last_time']) / 3600;
                if ($hoursPassed >= 1) {
                    $decay = floor($hoursPassed) * 10;
                    $state['score'] = max(0, $state['score'] - $decay);
                    $state['last_time'] = $now;
                }
            } else {
                $state['last_time'] = $now;
            }

            $currentScoreAdd = 0;
            $reasons = [];

            // 2. IP C段变动检测 (保存最近 5 个 C 段，防止手机/家宽频繁切换累加)
            $ipParts = explode('.', $ip);
            $ipC = (count($ipParts) === 4) ? $ipParts[0] . '.' . $ipParts[1] . '.' . $ipParts[2] : $ip;
            
            if (!in_array($ipC, $state['recent_ips'])) {
                // 如果是一个全新的 C 段，加 3 分
                $currentScoreAdd += 3;
                $reasons[] = "新IP段";
                array_unshift($state['recent_ips'], $ipC);
                if (count($state['recent_ips']) > 5) array_pop($state['recent_ips']);
            }

            // 3. UA 核心变动检测 (保存最近 3 个 UA，防止 PC 和手机同时挂着导致频繁切换)
            $uaLower = strtolower($userAgent);
            $uaCore = 'unknown';
            if (strpos($uaLower, 'tianque') !== false) $uaCore = 'tianque';
            elseif (strpos($uaLower, 'clash') !== false) $uaCore = 'clash';
            elseif (strpos($uaLower, 'v2ray') !== false) $uaCore = 'v2ray';
            elseif (strpos($uaLower, 'sing-box') !== false) $uaCore = 'sing-box';
            elseif (strpos($uaLower, 'hiddify') !== false) $uaCore = 'hiddify';
            elseif (strpos($uaLower, 'karing') !== false) $uaCore = 'karing';
            elseif (strpos($uaLower, 'shadowrocket') !== false) $uaCore = 'shadowrocket';
            elseif (strpos($uaLower, 'surfboard') !== false) $uaCore = 'surfboard';
            elseif (strpos($uaLower, 'loon') !== false) $uaCore = 'loon';
            elseif (strpos($uaLower, 'quantumult') !== false) $uaCore = 'quantumult';
            else $uaCore = substr($uaLower, 0, 15);

            if (!in_array($uaCore, $state['recent_uas'])) {
                // 如果是一个全新的 UA 核心，加 15 分
                $currentScoreAdd += 15;
                $reasons[] = "新UA({$uaCore})";
                array_unshift($state['recent_uas'], $uaCore);
                if (count($state['recent_uas']) > 3) array_pop($state['recent_uas']);
            }

            // 4. 省份变动检测 (保存最近 2 个省份，防止跨省通勤反复横跳)
            $prov = $geo['region'] ?? '';
            if ($prov !== '' && !in_array($prov, $state['recent_provs'])) {
                $currentScoreAdd += 20;
                $reasons[] = "跨省({$prov})";
                array_unshift($state['recent_provs'], $prov);
                if (count($state['recent_provs']) > 2) array_pop($state['recent_provs']);
            }

            // 如果完全没有任何变化（同C段，同UA，同省份），这完全是正常的后台刷新，不加分！
            // 只有当存在特征变化时，才累加本次得分。
            $state['score'] += $currentScoreAdd;

            // 如果得分 >= 100，触发告警并拉入蜜罐
            if ($state['score'] >= 100) {
                $reasonStr = "【动态积分爆表: {$state['score']}分】近期异常: " . implode(', ', $reasons);
                $this->alert($user, $ip, $userAgent, $reasonStr, 100, $request);
                // 斩首后分值减半，避免连续发报，同时保持高危状态
                $state['score'] = 50; 
            }

            \Illuminate\Support\Facades\Redis::setex($stateKey, 86400 * 3, json_encode($state)); // 保存3天
            
            // 维护 Redis ZSET 用于积分榜 (Score Leaderboard)
            if ($state['score'] > 0) {
                \Illuminate\Support\Facades\Redis::zadd("sub_risk_scores", $state['score'], $userId);
            } else {
                \Illuminate\Support\Facades\Redis::zrem("sub_risk_scores", $userId);
            }

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::channel('risk')->error('[风控] 动态积分计算异常', ['error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // 主入口
    // -------------------------------------------------------------------------

    public function handle(Request $request, Closure $next)
    {
        $user = \App\Models\User::where('token', $request->query('token'))->first();

        if (!$user || !empty($user->is_admin)) {
            return $next($request);
        }

        // 🛡️ 校验天阙用户白名单（若在白名单中则直接放行）
        if ($this->isUserWhitelisted($user)) {
            return $next($request);
        }

        // 🛡️ 校验节点 IP 免审白名单（若当前请求 IP 在免审白名单中则放行）
        $ip = $this->getRealIp($request);
        if ($this->isIpIgnored($ip)) {
            return $next($request);
        }

        // 🎯 捕获同行的 302 重定向跳转痕迹
        if ($request->header('Referer')) {
            Log::channel('risk')->warning('[跳转捕获] 抓到同行重定向痕迹', [
                'email'   => $user->email ?? 'unknown',
                'ip'      => $this->getRealIp($request),
                'referer' => $request->header('Referer'),
                'url'     => $request->fullUrl(),
                'ua'      => $request->userAgent()
            ]);

            // 发送 Telegram 推送告警
            $this->sendTelegram(
                $user,
                $this->getRealIp($request),
                $request->userAgent() ?? 'unknown',
                "发现同行跳转痕迹\n🔗 来源(Referer)：" . $request->header('Referer'),
                100, // 设为 100 分，直接触发高危告警
                1
            );
        }

        $ip        = $this->getRealIp($request);
        $userAgent = $request->userAgent() ?? 'unknown';

        // ① 频率限制
        if ($this->isRateLimited($user->id)) {
            $reason = '高频订阅（' . self::RATE_WINDOW . 's 内超过 ' . self::RATE_LIMIT . ' 次）';
            $this->alert($user, $ip, $userAgent, $reason, 80, $request);
            return response()->json(['message' => 'Too Many Requests'], 429);
        }

        // ② IP 跨区域检测
        $this->checkRegion($user, $ip, $userAgent, $request);

        
        // ③ 动态积分状态机 (细颗粒度行为风控)
        $geo = $this->getGeoInfo($ip);
        if ($geo) {
            $this->calculateDynamicScore($user, $ip, $userAgent, $geo, $request);
        }

return $next($request);
    }



    // -------------------------------------------------------------------------
    // 获取真实 IP（三级回退，兼容 Cloudflare）
    // -------------------------------------------------------------------------

    private function getRealIp(Request $request): string
    {
        return IpHelper::getRealIp($request);
    }

    // -------------------------------------------------------------------------
    // 频率限制
    // -------------------------------------------------------------------------

    private function isRateLimited(int $userId): bool
    {
        try {
            $key   = "sub_rate:{$userId}";
            $count = Redis::incr($key);
            if ((int) $count === 1) Redis::expire($key, self::RATE_WINDOW);
            return $count > self::RATE_LIMIT;
        } catch (\Throwable $e) {
            Log::channel('risk')->warning('[风控] Redis 不可用，跳过频率检测', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // IP 跨区域检测
    // -------------------------------------------------------------------------

    private function checkRegion($user, string $ip, string $userAgent, Request $request): void
    {
        try {
            $geo = $this->getGeoInfo($ip);
            if (!$geo || empty($geo['countryCode'])) return;

            $country = $geo['countryCode'];
            $region  = $geo['region'] ?? ''; // 省份代码，如 GD, SH, SN
            $hosting = $geo['hosting'] ?? false;
            $org     = $geo['org'] ?? '';
            $as      = $geo['as'] ?? '';

            // ① 核心企业级云服务器厂商拦截 (排除甲骨文、微软Azure、谷歌等大量正常用户也会使用的海外机房，仅保留国内三大云及AWS、HiNet核心源)
            $idcKeywords = [
                'Alibaba', 'Tencent', 'Huawei', 'Amazon', 'AWS', '中华电信', 'HiNet'
            ];
            
            $isTopIdc = false;
            $orgLower = strtolower($org);
            $asLower  = strtolower($as);
            foreach ($idcKeywords as $kw) {
                $kwLower = strtolower($kw);
                if (strpos($orgLower, $kwLower) !== false || strpos($asLower, $kwLower) !== false) {
                    $isTopIdc = true;
                    break;
                }
            }

            if ($isTopIdc) {
                $reason = "🚨 内鬼节点测活拦截：检测到拉取源属于顶级IDC [{$org} | {$as}]";
                // 不计入蜜罐（扣 0 分只作 TG 告警和日志），但强行阻断本次拉取！
                $this->alert($user, $ip, $userAgent, $reason, 0, $request);
                abort(403, 'Subscription access denied due to IDC IP.');
            }

            // ② 国内跨省检测（24小时内拉取 IP 覆盖省份数量 >= 3 直接拉闸秒封）
            // ⚠️ 核心防线：此检测必须放在 `$hosting` 过滤之前！因为内鬼常使用国内“秒拨”动态住宅IP（非机房）进行测活。
            if ($country === 'CN' && $region) {
                // 1. 物理极速跨省检测 (防秒拨代理池短时间内并发拉取)
                $lastProvDataKey = "sub_last_prov_data:{$user->id}";
                try {
                    $lastProvData = Redis::get($lastProvDataKey);
                    if ($lastProvData) {
                        $lastProvInfo = json_decode($lastProvData, true);
                        if (is_array($lastProvInfo) && isset($lastProvInfo['region']) && $lastProvInfo['region'] !== $region) {
                            $timeDiff = time() - (int)$lastProvInfo['time'];
                            // 规则：如果两次跨省拉取时间小于 2 小时 (7200秒)，且两次都不是机房IP（排除用户开关VPN导致），物理上绝无可能，必定是代理池
                            if ($timeDiff < 7200 && !$hosting && !$lastProvInfo['hosting']) {
                                $reason = "物理性极速跨省(双端住宅/秒拨)：{$lastProvInfo['region']} -> {$region}，耗时仅 {$timeDiff} 秒！";
                                // 根据要求：对于两省之间互跳给予一次机会，仅扣 80 分（触发 TG 报警但不会自动封禁）
                                // 如果出现第 3 个省份，将会由下面的 24 小时数量检测捕捉并扣 100 分秒封
                                $this->alert($user, $ip, $userAgent, $reason, 80, $request);
                            }
                        }
                    }
                    Redis::setex($lastProvDataKey, 86400, json_encode(['region' => $region, 'time' => time(), 'hosting' => $hosting]));
                } catch (\Throwable $e) {}

                // 2. 24小时累计跨省数量检测
                $provCacheKey = "sub_provinces:{$user->id}";
                try {
                    $isNew = Redis::sadd($provCacheKey, $region);
                    if ($isNew) {
                        $card = Redis::scard($provCacheKey);
                        if ((int)$card === 1) {
                            Redis::expire($provCacheKey, 86400); // 首次写入，生存期 24 小时
                        }

                        if ((int)$card >= 3) {
                            $provinces = Redis::smembers($provCacheKey);
                            $provList  = implode(', ', $provinces);
                            $ipType    = $hosting ? '机房IP' : '住宅/秒拨IP';
                            $reason    = "24小时内国内跨省异常({$ipType})：已覆盖 {$card} 个省份 ({$provList})";
                            // 扣 100 分直接秒封
                            $this->alert($user, $ip, $userAgent, $reason, 100, $request);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::channel('risk')->warning('[风控] 国内省份数量检测异常', ['error' => $e->getMessage()]);
                }
            }

            // ③ 核心体验优化：如果不是机房托管IP（即普通住宅/移动网络），则不再进行后续的【跨国】检测，根治误报
            if (!$hosting) {
                return;
            }

            // ④ 跨国检测（配合 UA 检测进行白名单放行，彻底防止同设备换代理误封）
            $countryCacheKey = "sub_region:{$user->id}";
            $lastCountry     = null;
            try {
                $lastCountry = Redis::get($countryCacheKey);
                Redis::setex($countryCacheKey, 86400, $country);
            } catch (\Throwable $e) {}

            if ($lastCountry && $lastCountry !== $country) {
                // 读取上一次拉取的 UA
                $uaCacheKey = "sub_ua:{$user->id}";
                $lastUa     = null;
                try {
                    $lastUa = Redis::get($uaCacheKey);
                    Redis::setex($uaCacheKey, 86400, $userAgent);
                } catch (\Throwable $e) {}

                // 只有当国家发生了变动，且客户端的 User-Agent 也变动了，才计入跨国异常（防止自己切代理误封）
                if ($lastUa && $lastUa !== $userAgent) {
                    $reason = "IP 跨国界异设备(机房源)：{$lastCountry}({$lastUa}) → {$country}({$userAgent}) [机房: {$org}]";
                    $this->alert($user, $ip, $userAgent, $reason, 60, $request);
                }
            }

        } catch (\Throwable $e) {
            Log::channel('risk')->warning('[风控] 地区检测异常', ['error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // IP 归属地理查询（带缓存，缓存国家及省份代码）
    // -------------------------------------------------------------------------

    private function getGeoInfo(string $ip): ?array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        // 1. 优先读取 Redis 缓存
        try {
            $cached = Redis::get("ip_geo:{$ip}");
            if ($cached) return json_decode($cached, true);
        } catch (\Throwable $e) {}

        // 2. 使用本地极速 ip2region (VectorIndex模式) 查询
        $regionStr = \App\Utils\IpHelper::ipLocation($ip);
        if ($regionStr && $regionStr !== '未知') {
            $parts = explode('|', $regionStr);
            $country = $parts[0] ?? '';
            $province = $parts[2] ?? '';
            $isp = $parts[4] ?? '';
            
            // 简单转换国家代码 (满足原逻辑 CN/非CN 的判断)
            $countryCode = 'XX';
            if ($country === '中国') $countryCode = 'CN';
            elseif ($country === '香港') $countryCode = 'HK';
            elseif ($country === '台湾') $countryCode = 'TW';
            elseif ($country === '澳门') $countryCode = 'MO';
            else $countryCode = $country;
            
            $res = [
                'status' => 'success',
                'countryCode' => $countryCode,
                'region' => $province,
                'hosting' => false,
                'org' => $isp,
                'as' => ''
            ];
            
            // 判定是否为机房托管
            $orgLower = strtolower($isp);
            $idcKeywords = ['阿里', '腾讯', '华为', 'amazon', 'aws', 'hinet', 'ovh', 'choopa', 'digitalocean', 'linode', 'vultr', 'cloudflare', 'idc', 'server', 'hosting', 'datacenter', 'psychz', 'quadranet', 'leaseweb', 'zenlayer', 'sakura', 'anchnet', 'ucloud', 'ksyun', 'baidubce', '数据中心', '机房', '云'];
            foreach ($idcKeywords as $kw) {
                if (strpos($orgLower, $kw) !== false) {
                    $res['hosting'] = true;
                    break;
                }
            }
            
            // 写入 Redis 缓存，有效期延长为 1 天 (86400 秒)
            try { Redis::setex("ip_geo:{$ip}", 86400, json_encode($res)); } catch (\Throwable $e) {}
            return $res;
        }

        // 3. 兜底回退：若离线库未找到，则请求 ip-api.com 接口
        try {
            $res = Http::timeout(2)
                ->get("http://ip-api.com/json/{$ip}", ['fields' => 'status,countryCode,region,hosting,org,as'])
                ->json();

            if (($res['status'] ?? '') === 'success') {
                // 写入 Redis 缓存，有效期延长为 1 天 (86400 秒)
                try { Redis::setex("ip_geo:{$ip}", 86400, json_encode($res)); } catch (\Throwable $e) {}
                return $res;
            }
        } catch (\Throwable $e) {
            Log::channel('risk')->info('[风控] IP 地理 API 查询失败（跳过）', ['ip' => $ip]);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // 统一告警入口
    // -------------------------------------------------------------------------

    private function alert($user, string $ip, string $userAgent, string $reason, int $score, Request $request): void
    {
        // 1. 写数据库
        $this->writeLog($user, $ip, $userAgent, $reason, $score);

        // 2. 写日志文件
        $this->writeFile($user, $ip, $userAgent, $reason, $score);

        // 3. 累计风险计数并自动判定是否加入蜜罐
        $triggerCount = $this->incrementRiskCount($user->id);
        
        // 🚀 核心优化：任何海外IP（非 CN 国家代码）均不执行自动封禁，只推送 TG 供您手动确认封禁！
        // 只有当确认为国内 IP（CN）且分值达到 100 分（如国内多省机房盗刷）时，才直接秒封。
        $ipCountry = null;
        try {
            $geo = $this->getGeoInfo($ip);
            $ipCountry = $geo['countryCode'] ?? null;
        } catch (\Throwable $e) {}

        if ($score >= 100 && $ipCountry === 'CN') {
            $this->autoBan($user, $reason, $triggerCount);
        }

        // 4. Telegram 推送通知：只有在分数 >= 80 时才发送（过滤掉 60 分的普通代理切换告警）
        if ($score >= 80) {
            $this->sendTelegram($user, $ip, $userAgent, $reason, $score, $triggerCount);
        }
    }

    // -------------------------------------------------------------------------
    // 1. 写 risk_logs 数据库表
    // -------------------------------------------------------------------------

    private function writeLog($user, string $ip, string $userAgent, string $reason, int $score): void
    {
        try {
            DB::table('risk_logs')->insert([
                'user_id'    => $user->id,
                'email'      => $user->email,
                'ip'         => $ip,
                'user_agent' => mb_substr($userAgent, 0, 255), // 防止超长截断
                'risk_score' => $score,
                'reason'     => $reason,
                'detected_at'=> now(),
                'created_at' => now(),
            ]);

            // 🧹 概率垃圾回收 (GC)：每次写入有 1% 的几率触发清理 15 天前的历史旧日志，防止数据库无限膨胀
            if (mt_rand(1, 100) === 1) {
                DB::table('risk_logs')
                    ->where('detected_at', '<', now()->subDays(15))
                    ->delete();
            }
        } catch (\Throwable $e) {
            Log::channel('risk')->error('[风控] 写库失败', ['error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // 2. 写日志文件
    // -------------------------------------------------------------------------

    private function writeFile($user, string $ip, string $userAgent, string $reason, int $score): void
    {
        Log::channel('risk')->warning('[风控] 异常订阅', [
            'user_id'    => $user->id,
            'email'      => $user->email,
            'ip'         => $ip,
            'user_agent' => $userAgent,
            'reason'     => $reason,
            'risk_score' => $score,
            'referer'    => request()->header('Referer') ?? '',
            'url'        => request()->fullUrl(),
        ]);
    }

    // -------------------------------------------------------------------------
    // 自动导入蜜罐
    // -------------------------------------------------------------------------

    private function autoBan($user, string $reason, int $triggerCount): void
    {
        try {
            $configPath = storage_path('tianque_config.json');
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

            $userId = (int)$user->id;
            $currentHoneypots = array_map('intval', $config['honeypot_users']);

            if (!in_array($userId, $currentHoneypots, true)) {
                // 将用户加入蜜罐名单
                $currentHoneypots[] = $userId;
                $config['honeypot_users'] = $currentHoneypots;
                $config['honeypot_times'][(string)$userId] = time();

                // 从疑似标记名单中移出（如果存在）
                if (isset($config['flagged_users']) && is_array($config['flagged_users'])) {
                    if (isset($config['flagged_users'][(string)$userId])) {
                        unset($config['flagged_users'][(string)$userId]);
                    }
                }

                @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                // 强制清除该用户所有活跃登录 Session，迫使其客户端重连拉取最新的诱饵节点
                try {
                    $authService = new \App\Services\AuthService($user);
                    $authService->removeAllSession();
                } catch (\Throwable $ex) {}

                Log::channel('risk')->warning('[风控] 用户已自动导入蜜罐（假封禁）', [
                    'user_id'       => $userId,
                    'email'         => $user->email,
                    'trigger_count' => $triggerCount,
                    'reason'        => $reason,
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('risk')->error('[风控] 自动导入蜜罐失败', ['error' => $e->getMessage()]);
        }
    }

    // -------------------------------------------------------------------------
    // 3. Redis 累计风险计数（7 天滑动窗口）
    // -------------------------------------------------------------------------

    private function incrementRiskCount(int $userId): int
    {
        try {
            $key   = "sub_risk_count:{$userId}";
            $count = Redis::incr($key);
            if ((int) $count === 1) {
                Redis::expire($key, self::BAN_WINDOW * 86400);
            }
            return (int) $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // 4. Telegram 推送（同步直接发送）
    // -------------------------------------------------------------------------

    private function sendTelegram($user, string $ip, string $userAgent, string $reason, int $score, int $triggerCount): void
    {
        // 四重环境变量备用读取，彻底解决 Laravel 缓存、Webman、Swoole 常驻进程无法读取 env() 的痛点
        $botToken = config('services.telegram.bot_token')
            ?? $_ENV['TELEGRAM_BOT_TOKEN']
            ?? getenv('TELEGRAM_BOT_TOKEN')
            ?? env('TELEGRAM_BOT_TOKEN');

        $chatId = config('services.telegram.chat_id')
            ?? $_ENV['TELEGRAM_CHAT_ID']
            ?? getenv('TELEGRAM_CHAT_ID')
            ?? env('TELEGRAM_CHAT_ID');

        if (!$botToken || !$chatId) {
            Log::channel('risk')->info('[风控] 未配置 TELEGRAM_BOT_TOKEN 或 TELEGRAM_CHAT_ID，跳过 TG 推送');
            return;
        }

        $inHoneypot = $this->isUserHoneypotted((int)$user->id);
        $emoji      = $score >= 80 ? '🔴' : ($score >= 60 ? '🟠' : '🟡');
        $banWarn    = $inHoneypot ? "\n🍯 当前已在蜜罐中" : "\n⚠️ 建议手动放入蜜罐";

        $text = implode("\n", [
            "{$emoji} 订阅风控告警{$banWarn}",
            "━━━━━━━━━━━━",
            "👤 {$user->email}（ID: {$user->id}）",
            "🌐 IP：{$ip}",
            "📱 客户端：{$userAgent}",
            "⚠️ 原因：{$reason}",
            "📊 评分：{$score}/100  |  7天累计：{$triggerCount}次",
            "🕐 " . now()->toDateTimeString(),
        ]);

        // 构建行内键盘按钮（根据当前是否已经在蜜罐中动态切换文字与回调）
        $keyboard = [
            'inline_keyboard' => [
                [
                    $inHoneypot 
                        ? ['text' => '↩️ 移出蜜罐', 'callback_data' => "unhoneypot:{$user->id}"]
                        : ['text' => '🍯 放入蜜罐', 'callback_data' => "honeypot:{$user->id}"],
                    ['text' => '🛡️ 设为白名单', 'callback_data' => "whitelist:{$user->id}"],
                    ['text' => '🔄 重置订阅', 'callback_data' => "reset:{$user->id}"]
                ],
                [
                    ['text' => "🌐 忽略此 IP ({$ip})", 'callback_data' => "ignore_ip:{$ip}:{$user->id}"]
                ]
            ]
        ];

        try {
            Http::timeout(5)->post(
                'https://api.telegram.org/bot' . trim($botToken) . '/sendMessage',
                [
                    'chat_id'      => trim($chatId),
                    'text'         => $text,
                    'reply_markup' => json_encode($keyboard)
                ]
            );
        } catch (\Throwable $e) {
            Log::channel('risk')->warning('[风控] Telegram 推送失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 判断用户是否在天阙安全白名单中
     */
    private function isUserWhitelisted($user): bool
    {
        try {
            $configPath = storage_path('tianque_config.json');
            if (file_exists($configPath)) {
                $config = json_decode(@file_get_contents($configPath), true) ?: [];
                if (isset($config['whitelist_users']) && is_array($config['whitelist_users'])) {
                    $userId = (int)$user->id;
                    $userEmail = strtolower($user->email);
                    
                    foreach ($config['whitelist_users'] as $item) {
                        // 匹配用户 ID
                        if (is_numeric($item) && (int)$item === $userId) {
                            return true;
                        }
                        // 匹配邮箱
                        if (is_string($item) && strtolower($item) === $userEmail) {
                            return true;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::channel('risk')->warning('[风控] 校验白名单异常', ['error' => $e->getMessage()]);
        }
        return false;
    }

    /**
     * 判断用户是否当前已被拉入蜜罐 (封禁)
     */
    private function isUserHoneypotted(int $userId): bool
    {
        try {
            $configPath = storage_path('tianque_config.json');
            if (file_exists($configPath)) {
                $config = json_decode(@file_get_contents($configPath), true) ?: [];
                if (isset($config['honeypot_users']) && is_array($config['honeypot_users'])) {
                    return in_array($userId, array_map('intval', $config['honeypot_users']), true);
                }
            }
        } catch (\Throwable $e) {
            Log::channel('risk')->warning('[风控] 校验蜜罐状态异常', ['error' => $e->getMessage()]);
        }
        return false;
    }

    /**
     * 判断当前请求 IP 是否在免审 IP/网段白名单中
     */
    private function isIpIgnored(string $ip): bool
    {
        try {
            $configPath = storage_path('tianque_config.json');
            if (file_exists($configPath)) {
                $config = json_decode(@file_get_contents($configPath), true) ?: [];
                if (isset($config['ignore_ips']) && is_array($config['ignore_ips'])) {
                    foreach ($config['ignore_ips'] as $pattern) {
                        $pattern = trim($pattern);
                        if (empty($pattern)) continue;

                        // 1. 直接相等匹配 (支持域名或 IP 字符串直接匹配)
                        if ($ip === $pattern) {
                            return true;
                        }

                        // 2. CIDR 网段匹配 (如 13.214.212.0/24)
                        if (strpos($pattern, '/') !== false) {
                            if ($this->ipInCidr($ip, $pattern)) {
                                return true;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::channel('risk')->warning('[风控] 校验免审 IP 异常', ['error' => $e->getMessage()]);
        }
        return false;
    }

    /**
     * 判断 IP 是否属于指定的 CIDR 网段
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        list($subnet, $bits) = explode('/', $cidr);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask;
        return ($ip & $mask) == $subnet;
    }
}
