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
    private function ipInRange($ip, $range)
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        list($subnet, $bits) = explode('/', $range);
        $bits = (int)$bits;

        // IPv4 CIDR 比对
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($bits < 0 || $bits > 32) return false;
            $ip_dec = ip2long($ip);
            $subnet_dec = ip2long($subnet);
            $mask = -1 << (32 - $bits);
            return ($ip_dec & $mask) === ($subnet_dec & $mask);
        }

        // IPv6 CIDR 比对
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($bits < 0 || $bits > 128) return false;
            $ip_bin = inet_pton($ip);
            $subnet_bin = inet_pton($subnet);
            if ($ip_bin === false || $subnet_bin === false) {
                return false;
            }

            $ip_hex = bin2hex($ip_bin);
            $subnet_hex = bin2hex($subnet_bin);
            
            $ip_bits_str = '';
            $subnet_bits_str = '';
            for ($i = 0; $i < 32; $i++) {
                $ip_bits_str .= str_pad(base_convert($ip_hex[$i], 16, 2), 4, '0', STR_PAD_LEFT);
                $subnet_bits_str .= str_pad(base_convert($subnet_hex[$i], 16, 2), 4, '0', STR_PAD_LEFT);
            }

            return substr($ip_bits_str, 0, $bits) === substr($subnet_bits_str, 0, $bits);
        }

        return false;
    }

    public function subscribe(Request $request)
    {
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

        // --- 🛡️ 订阅 IP 黑名单拦截 (支持 CIDR 网段) ---
        $configPath = storage_path('tianque_config.json');
        if (file_exists($configPath)) {
            $tianqueConfig = json_decode(@file_get_contents($configPath), true);
            if (is_array($tianqueConfig) && isset($tianqueConfig['banned_ips']) && is_array($tianqueConfig['banned_ips'])) {
                foreach ($tianqueConfig['banned_ips'] as $bannedRule) {
                    if ($this->ipInRange($realIp, $bannedRule)) {
                        \Log::warning('Subscribe block by IP rule: ' . $realIp . ' matched ' . $bannedRule);
                        return response([
                            'message' => 'Your IP has been banned'
                        ], 403);
                    }
                }
            }
        }

        $flag = $request->input('flag')
            ?? $request->header('User-Agent')
            ?? '';
        $flag = strtolower($flag);
        $user = $request->user;

        // 检测是否是通过小火箭专属订阅域名拉取，或者是 Deno 代理中转
        $isShadowrocketRoute = false;
        
        // 1. 检查 User-Agent 是否含有 deno，或者 cdn-loop 头部是否含有 deno
        if (stripos($request->header('User-Agent') ?? '', 'deno') !== false
            || stripos($request->header('cdn-loop') ?? '', 'deno') !== false) {
            $isShadowrocketRoute = true;
        }
        
        // 2. 检查 Host 或 X-Forwarded-Host 是否匹配小火箭专属域名
        $shadowrocketUrlSetting = config('v2board.subscribe_url_shadowrocket');
        if (!empty($shadowrocketUrlSetting)) {
            $shadowrocketHosts = [];
            foreach (explode(',', $shadowrocketUrlSetting) as $urlItem) {
                $urlItem = trim($urlItem);
                if (empty($urlItem)) continue;
                if (stripos($urlItem, 'http://') !== 0 && stripos($urlItem, 'https://') !== 0) {
                    $urlItem = 'http://' . $urlItem;
                }
                $parsedUrl = parse_url($urlItem);
                if (isset($parsedUrl['host'])) {
                    $shadowrocketHosts[] = strtolower($parsedUrl['host']);
                }
            }
            
            if (!empty($shadowrocketHosts)) {
                $currentHost = strtolower($request->getHost());
                $forwardedHost = $request->header('X-Forwarded-Host') ?? '';
                if (!empty($forwardedHost)) {
                    $forwardedHost = strtolower(explode(':', $forwardedHost)[0]);
                }
                
                foreach ($shadowrocketHosts as $srHost) {
                    if ($currentHost === $srHost || $forwardedHost === $srHost) {
                        $isShadowrocketRoute = true;
                        break;
                    }
                }
            }
        }
        
        if ($isShadowrocketRoute) {
            $flag = 'shadowrocket';
        }

        \Log::info('Subscribe request resolved: ' . json_encode([
            'flag' => $flag,
            'isShadowrocketRoute' => $isShadowrocketRoute,
            'ua' => $request->header('User-Agent') ?? '',
            'headers' => $request->headers->all(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip()
        ], JSON_UNESCAPED_UNICODE));

        try {
            $userService = new UserService();
            $isBanned = (bool)($user['banned'] ?? 0);
            $isBannedBait = false;

            // --- 🛡️ 订阅安全拦截：动态载入天阙配置 ---
            $configPath = storage_path('tianque_config.json');
            $honeypotUsers = [];
            $bannedStrategy = 'bait';
            $bannedRedirectUrl = '';
            $subconverterEnable = true;
            $subconverterUrl = 'https://api.wcc.best/sub';
            $bannedTrafficEnable = true;
            $bannedTrafficMin = 100;
            $bannedTrafficMax = 300;

            if (file_exists($configPath)) {
                $tianqueConfig = json_decode(@file_get_contents($configPath), true);
                if (is_array($tianqueConfig)) {
                    $honeypotUsers = $tianqueConfig['honeypot_users'] ?? [];
                    $bannedStrategy = $tianqueConfig['banned_strategy'] ?? 'bait';
                    $bannedRedirectUrl = $tianqueConfig['banned_redirect_url'] ?? '';
                    $subconverterEnable = isset($tianqueConfig['subconverter_enable']) ? (bool)$tianqueConfig['subconverter_enable'] : true;
                    $subconverterUrl = $tianqueConfig['subconverter_url'] ?? 'https://api.wcc.best/sub';
                    $bannedTrafficEnable = isset($tianqueConfig['banned_traffic_enable']) ? (bool)$tianqueConfig['banned_traffic_enable'] : true;
                    $bannedTrafficMin = isset($tianqueConfig['banned_traffic_min']) ? (int)$tianqueConfig['banned_traffic_min'] : 100;
                    $bannedTrafficMax = isset($tianqueConfig['banned_traffic_max']) ? (int)$tianqueConfig['banned_traffic_max'] : 300;
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
                if ($isShadowrocketRoute && ($clientType === '未知' || stripos($userAgent, 'deno') !== false)) {
                    $clientType = 'Shadowrocket';
                }
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

                // 判断是否需要忽略此 IP 记录 (如本站节点 IP)
                $configPath = storage_path('tianque_config.json');
                $shouldRecord = true;
                if (file_exists($configPath)) {
                    $tianqueConfig = json_decode(@file_get_contents($configPath), true);
                    if (is_array($tianqueConfig) && isset($tianqueConfig['ignore_ips']) && is_array($tianqueConfig['ignore_ips'])) {
                        foreach ($tianqueConfig['ignore_ips'] as $ignoreRule) {
                            if ($this->ipInRange($realIp, $ignoreRule)) {
                                $shouldRecord = false;
                                break;
                            }
                        }
                    }
                }

                $updateData = [
                    'client_login_at' => time()
                ];

                if ($shouldRecord) {
                    array_unshift($clientHistory, [
                        'type' => $clientType,
                        'time' => time(),
                        'ip' => $realIp,
                        'ua' => substr($userAgent, 0, 128)
                    ]);
                    $clientHistory = array_slice($clientHistory, 0, 5);
                    $updateData['client_type'] = json_encode($clientHistory, JSON_UNESCAPED_UNICODE);
                }

                if ($bannedTrafficEnable) {
                    $minBytes = max(0, $bannedTrafficMin) * 1048576;
                    $maxBytes = max($bannedTrafficMin, $bannedTrafficMax) * 1048576;
                    $virtualTraffic = ($minBytes === $maxBytes) ? $minBytes : rand($minBytes, $maxBytes);
                    if ($virtualTraffic > 0) {
                        $updateData['d'] = \DB::raw("d + {$virtualTraffic}");
                    }
                }

                \DB::table('v2_user')->where('id', $user['id'])->update($updateData);

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

                    @file_put_contents(storage_path('logs/debug_request.txt'), "Time: " . date('Y-m-d H:i:s') . " | UA: " . $userAgent . " | Flag: " . $flag . " | isClash: " . ($isClash ? 'yes' : 'no') . " | Strategy: " . $bannedStrategy . "\n", FILE_APPEND);

                    // 研判诱导源是否已经是自适应的机场订阅链接（含 /api/v1/client/subscribe 或 token= 等特征）
                    $isAdaptiveSubscription = false;
                    if (
                        stripos($baitSourceUrl, '/api/v1/client/subscribe') !== false 
                        || stripos($baitSourceUrl, 'token=') !== false
                        || stripos($baitSourceUrl, 'flag=') !== false
                    ) {
                        $isAdaptiveSubscription = true;
                    }

                    // 构造拉取的最终 URL。
                    // 1. 如果全局开启了 subconverter 转换，且客户端为 Clash，且属于外部订阅链接（域名不包含当前主站 Host）：
                    //    我们一律强制使用第三方转换器中转拉取，以便利用转换器的 IP 绕过对方机场对主站机房 IP 的封锁。
                    // 2. 如果关闭了转换，或者是主站自身的订阅，则直接拉取原始订阅。
                    $targetFetchUrl = $baitSourceUrl;
                    $isExternalUrl = true;
                    $currentHost = $_SERVER['HTTP_HOST'] ?? '';
                    if (!empty($currentHost) && stripos($baitSourceUrl, $currentHost) !== false) {
                        $isExternalUrl = false;
                    }

                    if ($isClash && $subconverterEnable && $isExternalUrl) {
                        $apiBase = rtrim($subconverterUrl, '/');
                        // 智能纠正：若填写的是网页端边缘转换器域名，自动补全/修正为它的官方后端接口 api.bianyuan.xyz
                        if (stripos($apiBase, 'bianyuan.xyz') !== false && stripos($apiBase, 'api.bianyuan.xyz') === false) {
                            $apiBase = 'https://api.bianyuan.xyz';
                        }
                        if (strpos($apiBase, 'sub') === false && strpos($apiBase, '?') === false) {
                            $apiBase .= '/sub';
                        }
                        $targetFetchUrl = $apiBase . '?target=clash&url=' . urlencode($baitSourceUrl);
                    }

                    // 缓存文件名，避免高并发频繁拉取 GitHub 慢导致卡死 PHP 进程
                    $cacheFile = storage_path('logs/tianque_bait_' . md5($targetFetchUrl) . '.cache');
                    $cachedContent = null;
                    
                    // 缓存 10 秒，保证死机厂的动态 UUID 和动态验证 Token 实时有效
                    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 10) {
                        $cachedContent = @file_get_contents($cacheFile);
                        if (!empty($cachedContent)) {
                            $isDirty = false;
                            if (stripos($cachedContent, '<html') !== false || stripos($cachedContent, '<!doctype') !== false) {
                                $isDirty = true;
                            }
                            if ($isClash && stripos($cachedContent, 'proxies:') === false) {
                                $isDirty = true;
                            }
                            if ($isDirty) {
                                @unlink($cacheFile);
                                $cachedContent = null;
                            }
                        }
                    }

                    if (empty($cachedContent)) {
                        // 准备备用转换接口，如果关闭了 subconverter 或者是主站自身订阅，则只拉取原始订阅
                        $urlsToTry = [$targetFetchUrl];
                        if ($isClash && $subconverterEnable && $isExternalUrl) {
                            // 自定义转换 API 的后备备用转换器
                            $urlsToTry[] = 'https://api.v1.mk/sub?target=clash&url=' . urlencode($baitSourceUrl);
                            $urlsToTry[] = 'https://sub.d1.mk/sub?target=clash&url=' . urlencode($baitSourceUrl);
                        } elseif (!$isClash && $isExternalUrl) {
                            $urlsToTry[] = 'https://raw.githubusercontent.com/Pawdroid/Free-servers/main/sub';
                        }

                        foreach ($urlsToTry as $fetchUrl) {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $fetchUrl);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 12); // 超时时间放宽为 12 秒，确保链式订阅转换不会因为高负载超时
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent ?: 'Mozilla/5.0');
                            $responseContent = curl_exec($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            $curlErr = curl_error($ch);
                            \Log::info("Tianque bait fetch single. URL: {$fetchUrl}, Code: {$httpCode}, Length: " . strlen($responseContent) . ", Err: {$curlErr}, Snippet: " . substr(preg_replace('/\s+/', ' ', $responseContent), 0, 200));
                            @file_put_contents(storage_path('logs/debug_request.txt'), "Time: " . date('Y-m-d H:i:s') . " | Try URL: {$fetchUrl} | HTTP Code: {$httpCode} | Length: " . strlen($responseContent) . " | Err: {$curlErr} | HasProxies: " . (stripos($responseContent, 'proxies:') !== false ? 'yes' : 'no') . "\n", FILE_APPEND);
                            curl_close($ch);

                            // 排除常见的带有报错信息的返回值，确保获取的是合法的订阅内容。同时过滤 403 拦截与 HTML 网页标记
                            if (
                                $httpCode === 200 
                                && !empty($responseContent) 
                                && stripos($responseContent, 'invalid') === false 
                                && stripos($responseContent, 'error') === false 
                                && stripos($responseContent, 'Forbidden') === false 
                                && stripos($responseContent, 'cloudflare') === false
                                && stripos($responseContent, '<html') === false
                                && stripos($responseContent, '<!doctype') === false
                            ) {
                                // 如果是 Clash 客户端，要求必须拉到 proxies 字段才算合法的 YAML 订阅
                                if ($isClash && stripos($responseContent, 'proxies:') === false) {
                                    continue;
                                }
                                $cachedContent = $responseContent;
                                @file_put_contents($cacheFile, $responseContent);
                                break;
                            }
                        }
                    }

                    if (!empty($cachedContent)) {
                        // 过滤并净化订阅中的广告词与品牌敏感词
                        $cachedContent = $this->sanitizeBaitContent($cachedContent, $isClash);

                        $contentType = $isClash ? 'application/yaml; charset=utf-8' : 'text/plain; charset=utf-8';
                        return response($cachedContent)
                            ->header('Content-Type', $contentType)
                            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
                    }

                    // 若拉取异常或被对方 403 拦截拦截，这里执行强健的本地兜底，直接返回纯净警告 YAML 文本，保证 Clash 100% 不弹 invalid yaml 报错
                    if ($isClash) {
                        $cleanYaml = "mixed-port: 7890\nallow-lan: false\nmode: rule\nlog-level: info\nproxies:\n  - name: \"⚠️ 订阅拉取异常，请联系客服获取最新客户端\"\n    type: ss\n    server: 127.0.0.1\n    port: 10086\n    cipher: aes-128-gcm\n    password: \"tianquegemiji\"\nproxy-groups:\n  - name: \"Proxy\"\n    type: select\n    proxies:\n      - \"⚠️ 订阅拉取异常，请联系客服获取最新客户端\"\nrules:\n  - MATCH,Proxy";
                        return response($cleanYaml)
                            ->header('Content-Type', 'application/yaml; charset=utf-8')
                            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
                    }

                    // 否则（如 v2rayN 等通用客户端），降级下发通用 Base64 格式
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
            if ($isShadowrocketRoute && ($clientType === '未知' || stripos($userAgent, 'deno') !== false)) {
                $clientType = 'Shadowrocket';
            }

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

            // 判断是否需要忽略此 IP 记录 (如本站节点 IP)
            $configPath = storage_path('tianque_config.json');
            $shouldRecord = true;
            if (file_exists($configPath)) {
                $tianqueConfig = json_decode(@file_get_contents($configPath), true);
                if (is_array($tianqueConfig) && isset($tianqueConfig['ignore_ips']) && is_array($tianqueConfig['ignore_ips'])) {
                    foreach ($tianqueConfig['ignore_ips'] as $ignoreRule) {
                        if ($this->ipInRange($realIp, $ignoreRule)) {
                            $shouldRecord = false;
                            break;
                        }
                    }
                }
            }

            $updateData = [
                'client_login_at' => time()
            ];

            if ($shouldRecord) {
                array_unshift($clientHistory, [
                    'type' => $clientType,
                    'time' => time(),
                    'ip' => $realIp,
                    'ua' => substr($userAgent, 0, 128)
                ]);
                $clientHistory = array_slice($clientHistory, 0, 5);
                $updateData['client_type'] = json_encode($clientHistory, JSON_UNESCAPED_UNICODE);
            }

            // 保存到数据库
            \DB::table('v2_user')
                ->where('id', $user['id'])
                ->update($updateData);

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
                $yaml = $this->sanitizeNormalContent($yaml, $flag);
                return response($yaml);
            }
            if ($flag) {
                if (!strpos($flag, 'sing')) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            $resContent = $class->handle();
                            return $this->sanitizeNormalContent($resContent, $flag);
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
                    $resContent = $class->handle();
                    return $this->sanitizeNormalContent($resContent, $flag);
                }
            }
            $class = new General($user, $servers);
            $resContent = $class->handle();
            return $this->sanitizeNormalContent($resContent, $flag);
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

    private function sanitizeBaitContent($content, $isClash)
    {
        $configPath = storage_path('tianque_config.json');
        $keywords = ['一元机场', '1元机场', 'smallstrawberry'];
        $replaceTo = '天阙精品';

        if (file_exists($configPath)) {
            $tianqueConfig = json_decode(@file_get_contents($configPath), true);
            if (is_array($tianqueConfig)) {
                $bannedKeywords = $tianqueConfig['banned_keywords'] ?? '一元机场,1元机场,smallstrawberry';
                $replaceTo = $tianqueConfig['replace_keyword_to'] ?? '天阙精品';
                if (!empty($bannedKeywords)) {
                    $keywords = array_filter(array_map('trim', preg_split('/[,\r\n]+/', $bannedKeywords)));
                }
            }
        }

        if (empty($keywords)) {
            return $content;
        }

        if ($isClash) {
            // 对 Clash YAML，采用名字直接替换，防止直接删除导致 proxy-groups 找不到节点而报错
            foreach ($keywords as $keyword) {
                $content = str_replace($keyword, $replaceTo, $content);
            }
            return $content;
        } else {
            // 对通用 Base64 订阅，解密后过滤，如果某一行包含敏感字，则直接整行剔除，最后重新 Base64 编码
            $decoded = @base64_decode($content);
            if ($decoded === false || empty($decoded)) {
                // 如果解密失败，说明不是 base64 或者是明文，我们退而求其次进行全局替换
                foreach ($keywords as $keyword) {
                    $content = str_replace($keyword, $replaceTo, $content);
                }
                return $content;
            }

            $lines = preg_split('/\r\n|\r|\n/', $decoded);
            $keptLines = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                // 判断这行是否包含敏感词
                $hasKeyword = false;
                $decodedLine = urldecode($line);
                
                // 处理 vmess:// 内部 JSON 的 Base64
                if (stripos($decodedLine, 'vmess://') !== false) {
                    $vmessData = substr($line, 8);
                    $vmessDecoded = @base64_decode($vmessData);
                    if ($vmessDecoded) {
                        $vmessJson = json_decode($vmessDecoded, true);
                        if (is_array($vmessJson) && isset($vmessJson['ps'])) {
                            foreach ($keywords as $keyword) {
                                if (stripos($vmessJson['ps'], $keyword) !== false) {
                                    $hasKeyword = true;
                                    break;
                                }
                            }
                        }
                    }
                }

                foreach ($keywords as $keyword) {
                    if (stripos($decodedLine, $keyword) !== false) {
                        $hasKeyword = true;
                        break;
                    }
                }

                if (!$hasKeyword) {
                    $keptLines[] = $line;
                }
            }

            return base64_encode(implode("\n", $keptLines));
        }
    }

    private function sanitizeNormalContent($content, $flag)
    {
        $configPath = storage_path('tianque_config.json');
        if (!file_exists($configPath)) {
            return $content;
        }

        $tianqueConfig = json_decode(@file_get_contents($configPath), true);
        if (!is_array($tianqueConfig)) {
            return $content;
        }

        $bannedKeywords = $tianqueConfig['banned_keywords'] ?? '';
        $replaceTo = $tianqueConfig['replace_keyword_to'] ?? '精品线路';
        if (empty($bannedKeywords)) {
            return $content;
        }

        $keywords = array_filter(array_map('trim', preg_split('/[,\r\n]+/', $bannedKeywords)));
        if (empty($keywords)) {
            return $content;
        }

        $isClash = false;
        if ($flag && (
            stripos($flag, 'clash') !== false 
            || stripos($flag, 'sing') !== false 
            || stripos($flag, 'surge') !== false 
            || stripos($flag, 'stash') !== false
        )) {
            $isClash = true;
        }

        if ($isClash) {
            foreach ($keywords as $keyword) {
                $content = str_replace($keyword, $replaceTo, $content);
            }
            return $content;
        } else {
            // base64 订阅内容处理
            $decoded = @base64_decode($content);
            if ($decoded === false || empty($decoded)) {
                // 如果解密失败，直接字符串全局替换
                foreach ($keywords as $keyword) {
                    $content = str_replace($keyword, $replaceTo, $content);
                }
                return $content;
            }

            $lines = preg_split('/\r\n|\r|\n/', $decoded);
            $processedLines = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $decodedLine = urldecode($line);
                
                // 处理 vmess 特殊 Base64 格式
                if (stripos($decodedLine, 'vmess://') === 0) {
                    $vmessData = substr($line, 8);
                    $vmessDecoded = @base64_decode($vmessData);
                    if ($vmessDecoded) {
                        $vmessJson = json_decode($vmessDecoded, true);
                        if (is_array($vmessJson) && isset($vmessJson['ps'])) {
                            foreach ($keywords as $keyword) {
                                $vmessJson['ps'] = str_replace($keyword, $replaceTo, $vmessJson['ps']);
                            }
                            $processedLines[] = 'vmess://' . base64_encode(json_encode($vmessJson, JSON_UNESCAPED_UNICODE));
                            continue;
                        }
                    }
                }

                // 其他协议（如 ss, trojan, vless 等）的行直接替换敏感词并重新 URL 编码或者明文替换
                foreach ($keywords as $keyword) {
                    $line = str_replace($keyword, $replaceTo, $line);
                }
                $processedLines[] = $line;
            }

            return base64_encode(implode("\n", $processedLines));
        }
    }
}