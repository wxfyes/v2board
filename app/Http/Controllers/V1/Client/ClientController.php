<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;

        try {
            $userService = new UserService();
            $isBanned = (bool)($user['banned'] ?? 0);
            $isBannedBait = false;

            // --- 🛡️ 订阅安全拦截：动态载入天阙配置 ---
            $configPath = storage_path('tianque_config.json');
            $honeypotUsers = [];
            $bannedStrategy = 'bait';
            $bannedRedirectUrl = '';

            if (file_exists($configPath)) {
                $tianqueConfig = json_decode(@file_get_contents($configPath), true);
                if (is_array($tianqueConfig)) {
                    $honeypotUsers = $tianqueConfig['honeypot_users'] ?? [];
                    $bannedStrategy = $tianqueConfig['banned_strategy'] ?? 'bait';
                    $bannedRedirectUrl = $tianqueConfig['banned_redirect_url'] ?? '';
                }
            } 

            // 判定是否在灰名单内
            $isInHoneypot = false;
            if (isset($user['id']) && isset($user['email'])) {
                foreach ($honeypotUsers as $item) {
                    if ($user['id'] == $item || strtolower($user['email']) === strtolower(trim($item))) {
                        $isInHoneypot = true;
                        break;
                    }
                }
            }

            // 只要被封禁或者身处灰名单，一律触发拦截与蜜罐防御
            $triggerBlock = $isBanned || $isInHoneypot;

            if ($triggerBlock) {
                // 同样记录被封禁/灰名单账号的客户端拉取行为！以防内鬼残留探测漏抓
                $userAgent = $request->header('User-Agent') ?? '';
                $clientType = $this->parseClientType($userAgent);
                $existingData = \DB::table('v2_user')->where('id', $user['id'])->value('client_type');
                $clientHistory = [];
                if ($existingData) {
                    $decoded = json_decode($existingData, true);
                    if (is_array($decoded)) {
                        $clientHistory = $decoded;
                    }
                }
                $realIp = null;
                if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                    $realIp = $_SERVER['HTTP_CF_CONNECTING_IP'];
                } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                    $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                    $realIp = trim($ips[0]);
                } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
                    $realIp = $_SERVER['HTTP_X_REAL_IP'];
                } else {
                    $realIp = $request->ip();
                }
                array_unshift($clientHistory, [
                    'type' => $clientType,
                    'time' => time(),
                    'ip' => $realIp,
                    'ua' => substr($userAgent, 0, 128)
                ]);
                $clientHistory = array_slice($clientHistory, 0, 5);
                \DB::table('v2_user')->where('id', $user['id'])->update([
                    'client_login_at' => time(),
                    'client_type' => json_encode($clientHistory, JSON_UNESCAPED_UNICODE)
                ]);

                if ($bannedStrategy === 'redirect') {
                    return redirect($bannedRedirectUrl);
                } elseif ($bannedStrategy === 'bait') {
                    $baitSourceUrl = $bannedRedirectUrl ?: 'https://proxy.v2gh.com/https://raw.githubusercontent.com/Pawdroid/Free-servers/main/sub';
                    
                    // 动态研判当前请求是否为 Clash 客户端
                    $isClash = false;
                    if ($flag && strpos($flag, 'clash') !== false) {
                        $isClash = true;
                    }
                    if (stripos($userAgent, 'clash') !== false) {
                        $isClash = true;
                    }

                    // 构造拉取的最终 URL，若为 Clash 客户端则通过订阅转换器拉取 YAML 格式配置，否则直接拉取 Base64 通用配置
                    $targetFetchUrl = $baitSourceUrl;
                    if ($isClash) {
                        $targetFetchUrl = 'https://api.wcc.best/sub?target=clash&url=' . urlencode($baitSourceUrl);
                    }

                    // 缓存文件名，避免高并发频繁拉取 GitHub 慢导致卡死 PHP 进程
                    $cacheFile = storage_path('logs/tianque_bait_' . md5($targetFetchUrl) . '.cache');
                    $cachedContent = null;
                    
                    // 缓存 30 分钟 (1800 秒)
                    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 1800) {
                        $cachedContent = @file_get_contents($cacheFile);
                    }

                    if (empty($cachedContent)) {
                        // 准备备用转换接口，提高拉取成功率
                        $urlsToTry = [$targetFetchUrl];
                        if ($isClash) {
                            $urlsToTry[] = 'https://api.v1.mk/sub?target=clash&url=' . urlencode($baitSourceUrl);
                            $urlsToTry[] = 'https://sub.d1.mk/sub?target=clash&url=' . urlencode($baitSourceUrl);
                        } else {
                            $urlsToTry[] = 'https://raw.githubusercontent.com/Pawdroid/Free-servers/main/sub';
                        }

                        foreach ($urlsToTry as $fetchUrl) {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $fetchUrl);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 4); // 4 秒超时
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent ?: 'Mozilla/5.0');
                            $responseContent = curl_exec($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);

                            // 排除常见的带有报错信息的返回值，确保获取的是合法的订阅内容
                            if ($httpCode === 200 && !empty($responseContent) && stripos($responseContent, 'invalid') === false && stripos($responseContent, 'error') === false) {
                                $cachedContent = $responseContent;
                                @file_put_contents($cacheFile, $responseContent);
                                break;
                            }
                        }
                    }

                    if (!empty($cachedContent)) {
                        $contentType = $isClash ? 'application/yaml; charset=utf-8' : 'text/plain; charset=utf-8';
                        return response($cachedContent)
                            ->header('Content-Type', $contentType)
                            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
                    }

                    // 若公益拉取超时或失败，降级为默认的警告虚拟节点，防止直接白给
                    $servers = [
                        [
                            'id' => 99991,
                            'name' => '⚠️ 订阅拉取异常，请联系客服获取最新客户端',
                            'type' => 'shadowsocks',
                            'host' => '127.0.0.1',
                            'port' => 10086,
                            'server_port' => 10086,
                            'cipher' => 'aes-128-gcm',
                            'obfs' => null,
                            'obfs_settings' => null,
                            'tags' => [],
                            'show' => 1,
                        ]
                    ];
                    $isBannedBait = true;
                } else {
                    return response([
                        'message' => 'Your account has been suspended'
                    ], 403);
                }
            }

            if (!$isBannedBait) {
                $isAvailable = $userService->isAvailable($user);
                if ($isAvailable) {
                    $serverService = new ServerService();
                    $servers = $serverService->getAvailableServers($user);
                } else {
                    // 套餐过期或未购买，生成一个虚拟的“提示节点”，避免客户端转圈，并友好提示购买套餐
                    $servers = [
                        [
                            'id' => 99999,
                            'name' => '⚠️ 请购买或续费套餐后使用',
                            'type' => 'shadowsocks',
                            'host' => '127.0.0.1',
                            'port' => 10086,
                            'server_port' => 10086,
                            'cipher' => 'aes-128-gcm',
                            'obfs' => null,
                            'obfs_settings' => null,
                            'tags' => [],
                            'show' => 1,
                        ]
                    ];
                }
            }

            // 记录客户端登录时间和类型（所有客户端都记录，保留历史）
            $userAgent = $request->header('User-Agent') ?? '';
            $clientType = $this->parseClientType($userAgent);

            // 获取现有的客户端历史记录
            $existingData = \DB::table('v2_user')
                ->where('id', $user['id'])
                ->value('client_type');

            // 解析现有记录（JSON 格式）
            $clientHistory = [];
            if ($existingData) {
                $decoded = json_decode($existingData, true);
                if (is_array($decoded)) {
                    $clientHistory = $decoded;
                }
            }

            // 穿透 CDN 与反向代理获取真实用户公网 IP
            $realIp = null;
            if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $realIp = $_SERVER['HTTP_CF_CONNECTING_IP'];
            } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $realIp = trim($ips[0]);
            } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
                $realIp = $_SERVER['HTTP_X_REAL_IP'];
            } else {
                $realIp = $request->ip();
            }

            // 添加新记录到数组开头
            array_unshift($clientHistory, [
                'type' => $clientType,
                'time' => time(),
                'ip' => $realIp,
                'ua' => substr($userAgent, 0, 128)
            ]);

            // 只保留最近 5 条记录
            $clientHistory = array_slice($clientHistory, 0, 5);

            // 保存到数据库
            \DB::table('v2_user')
                ->where('id', $user['id'])
                ->update([
                    'client_login_at' => time(),
                    'client_type' => json_encode($clientHistory, JSON_UNESCAPED_UNICODE)
                ]);

            // --- 🔐 安全加固：核心逻辑 ---
            // 1. 只要带了 security=1，无论什么 UA，一律下发加密流，防止探测器重放 URL 获取明文
            if ($request->input('security') == '1') {
                $class = new \App\Protocols\MOMclash($user, $servers);
                $yaml = $class->handle();
                
                $key = 'MOMclashSafeKey2026SecureGCM8888'; 
                $iv = openssl_random_pseudo_bytes(12);
                $tag = "";
                $encrypted = openssl_encrypt($yaml, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
                
                return response($iv . $tag . $encrypted)
                    ->header('Content-Type', 'application/octet-stream')
                    ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
            }

            // 2. 如果没带 security=1，但 UA 是我们 App，为了安全，我们也强制使用加密逻辑
            if (stripos($userAgent, 'TianQueApp') !== false && ($request->is('**/subscribe') || $request->has('token'))) {
                $class = new \App\Protocols\MOMclash($user, $servers);
                $yaml = $class->handle();
                return response($yaml);
            }
            if ($flag) {
                if (!strpos($flag, 'sing')) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            return $class->handle();
                        }
                    }
                }
                if (strpos($flag, 'sing') !== false) {
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if (!is_null($version) && $version >= '1.12.0') {
                        $class = new Singbox($user, $servers);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    return $class->handle();
                }
            }
            $class = new General($user, $servers);
            return $class->handle();
        } catch (\Exception $e) {
            return response([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * 解析 User-Agent 获取客户端类型
     */
    private function parseClientType($userAgent)
    {
        $userAgent = strtolower($userAgent);

        // 常见客户端识别
        $clients = [
            'mclash' => 'Mclash',
            'tianqueapp' => '天阙(TianQue)',
            'clash' => 'Clash',
            'clash-verge' => 'Clash Verge',
            'clashx' => 'ClashX',
            'clash for windows' => 'Clash for Windows',
            'shadowrocket' => 'Shadowrocket',
            'quantumult' => 'Quantumult',
            'quantumult%20x' => 'Quantumult X',
            'surge' => 'Surge',
            'v2rayn' => 'V2RayN',
            'v2rayng' => 'V2RayNG',
            'stash' => 'Stash',
            'sing-box' => 'sing-box',
            'hiddify' => 'Hiddify',
            'nekobox' => 'NekoBox',
            'nekoray' => 'NekoRay',
            'passwall' => 'PassWall',
            'ssrplus' => 'SSR+',
            'openclash' => 'OpenClash',
        ];

        foreach ($clients as $keyword => $name) {
            if (strpos($userAgent, $keyword) !== false) {
                return $name;
            }
        }

        // 如果无法识别，返回 User-Agent 的前 32 个字符
        return substr($userAgent, 0, 32) ?: '未知';
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0]))
            return;
        if (!(int) config('v2board.show_info_to_server_enable', 0))
            return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }
}