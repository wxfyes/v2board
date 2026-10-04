<?php

namespace App\Plugins\ExternalNode\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use App\Plugins\ExternalNode\Services\StorageService;
use App\Plugins\ExternalNode\Services\CleanerService;
use Symfony\Component\Yaml\Yaml;

class FreeSubscribeController extends Controller
{
    /**
     * 独立免费引流订阅入口
     * GET /api/v1/free/subscribe?token=xxx
     */
    public function subscribe(Request $request)
    {
        $token = $request->input('token');
        if (empty($token)) {
            return response('Token is required', 400);
        }

        // 查询用户 (兼顾测试便利与真实用户)
        $user = DB::table('v2_user')->where('token', $token)->first();
        if (!$user) {
            if ($token === 'test_token_123' || $token === 'test') {
                $user = (object)[
                    'id' => 0,
                    'banned' => 0,
                    'transfer_enable' => 107374182400, // 100GB
                    'u' => 0,
                    'd' => 0,
                    'expired_at' => time() + 86400 * 30
                ];
            } else {
                return response('User not found', 403);
            }
        }

        // 用户封禁检测
        if (!empty($user->banned)) {
            return response('User has been banned', 403);
        }

        return $this->renderFreeNodesForUser($request, $user);
    }

    /**
     * 为指定用户渲染并下发免费外部节点 (原生接口与独立引流入口复用)
     */
    public function renderFreeNodesForUser(Request $request, $user)
    {
        $userObj = is_array($user) ? (object)$user : $user;
        $data = StorageService::load();
        $settings = $data['settings'] ?? [];

        // 检查开关
        if (empty($settings['enable'])) {
            return response('Free service is currently disabled', 503);
        }

        // 流量与额度判定
        $remainTraffic = ($userObj->transfer_enable ?? 0) - (($userObj->u ?? 0) + ($userObj->d ?? 0));
        if ($remainTraffic <= 0) {
            return $this->renderExhaustedResponse($request);
        }

        // 执行虚拟拉取扣费（按次扣费，真实用户生效）
        $deductMb = (int)($settings['deduct_traffic_mb'] ?? 1024);
        if ($deductMb > 0 && !empty($userObj->id)) {
            $deductBytes = $deductMb * 1024 * 1024;
            $actualDeduct = min($remainTraffic, $deductBytes);
            DB::table('v2_user')->where('id', $userObj->id)->decrement('transfer_enable', $actualDeduct);
            $userObj->transfer_enable -= $actualDeduct;
        }

        // 提取在线节点
        $allNodes = $data['nodes'] ?? [];
        $onlineNodes = array_filter($allNodes, function ($n) {
            return !empty($n['is_online']);
        });

        // 客户端协议兼容性智能适配：
        // V2RayN 等客户端的 Base64 订阅导入器仅支持专用协议 (vmess, vless, trojan, ss, hy2, anytls, tuic)，
        // 会将订阅中的 http:// 或 https:// 视为普通网页链接直接忽略，导致节点跳号且浪费宝贵下发配额。
        // 因此针对非 Clash 客户端，智能过滤掉 http/https 节点，保障 V2RayN 客户端满额连续导入。
        $ua = strtolower($request->header('User-Agent', ''));
        $flag = strtolower($request->input('flag', ''));
        $isV2ray = (strpos($ua, 'v2ray') !== false);
        $isClash = !$isV2ray && (
            strpos($ua, 'clash') !== false ||
            strpos($ua, 'meta') !== false ||
            strpos($ua, 'tianque') !== false ||
            strpos($ua, 'momclash') !== false ||
            strpos($flag, 'clash') !== false ||
            strpos($flag, 'meta') !== false ||
            $request->input('security') == '1'
        );

        if (!$isClash) {
            $v2rayCompatible = array_filter($onlineNodes, function ($n) {
                $t = strtolower($n['type'] ?? '');
                return !in_array($t, ['http', 'https']);
            });
            if (!empty($v2rayCompatible)) {
                $onlineNodes = $v2rayCompatible;
            }
        }

        // 数量限制
        $maxNodes = (int)($settings['max_nodes_per_sub'] ?? 15);
        if ($maxNodes > 0 && count($onlineNodes) > $maxNodes) {
            // 随机混淆取样，降低外部单个节点拥堵
            shuffle($onlineNodes);
            $onlineNodes = array_slice($onlineNodes, 0, $maxNodes);
        }

        // 如果节点库暂无节点，下发保底提示节点
        if (empty($onlineNodes)) {
            return $this->renderEmptyPoolResponse($request);
        }

        // 动态根据当前后台最新设置实时格式化节点商业名称 (前缀、后缀即改即生效)
        $idx = 1;
        $formattedNodes = [];
        foreach ($onlineNodes as $n) {
            $cleaned = CleanerService::cleanAndFormatName($n['raw_name'] ?? ($n['formatted_name'] ?? 'Node'), $idx++, $settings);
            $n['formatted_name'] = $cleaned['formatted_name'];
            $n['region'] = $cleaned['region'];
            $n['emoji'] = $cleaned['emoji'];
            $formattedNodes[] = $n;
        }

        // 组装并输出订阅
        return $this->renderNodes($request, $formattedNodes, $userObj);
    }

    /**
     * 智能格式渲染（根据客户端 UA / 参数下发）
     */
    private function renderNodes(Request $request, array $nodes, $user)
    {
        $ua = strtolower($request->header('User-Agent', ''));
        $flag = strtolower($request->input('flag', ''));

        $isV2ray = (strpos($ua, 'v2ray') !== false);
        $isClash = !$isV2ray && (
            strpos($ua, 'clash') !== false ||
            strpos($ua, 'meta') !== false ||
            strpos($ua, 'tianque') !== false ||
            strpos($ua, 'momclash') !== false ||
            strpos($flag, 'clash') !== false ||
            strpos($flag, 'meta') !== false ||
            $request->input('security') == '1'
        );

        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Subscription-Userinfo' => sprintf(
                'upload=%d; download=%d; total=%d; expire=%d',
                $user->u,
                $user->d,
                $user->transfer_enable,
                $user->expired_at ?: (time() + 86400 * 365)
            ),
            'Profile-Update-Interval' => '12' // 提示客户端12小时更新一次
        ];

        if ($isClash) {
            $yaml = $this->buildClashConfig($nodes);

            // 🔐 针对自研客户端 MOMclash (带有 security=1)，启用专属双向安全加密流
            if ($request->input('security') == '1') {
                $key = 'MOMclashSafeKey2026SecureGCM8888';
                $iv = openssl_random_pseudo_bytes(12);
                $tag = "";
                $encrypted = openssl_encrypt($yaml, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
                return response($iv . $tag . $encrypted, 200, array_merge($headers, [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Disposition' => 'attachment; filename="Free_Nodes.yaml"'
                ]));
            }

            return response($yaml, 200, array_merge($headers, [
                'Content-Type' => 'application/yaml; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="Free_Nodes.yaml"'
            ]));
        }

        return response($this->buildBase64($nodes), 200, array_merge($headers, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="Free_Nodes.txt"'
        ]));
    }

    /**
     * 构建标准自包含 Clash YAML 配置
     */
    private function buildClashConfig(array $nodes): string
    {
        $proxies = [];
        $proxyNames = [];

        foreach ($nodes as $node) {
            $p = $this->nodeToClashProxy($node);
            if ($p) {
                $proxies[] = $p;
                $proxyNames[] = $p['name'];
            }
        }

        $config = [
            'port' => 7890,
            'socks-port' => 7891,
            'allow-lan' => true,
            'mode' => 'rule',
            'log-level' => 'info',
            'proxies' => $proxies,
            'proxy-groups' => [
                [
                    'name' => '🚀 免费节点选择',
                    'type' => 'select',
                    'proxies' => array_merge(['♻️ 自动选择'], $proxyNames)
                ],
                [
                    'name' => '♻️ 自动选择',
                    'type' => 'url-test',
                    'url' => 'http://www.gstatic.com/generate_204',
                    'interval' => 300,
                    'proxies' => $proxyNames
                ]
            ],
            'rules' => [
                'GEOIP,CN,DIRECT',
                'MATCH,🚀 免费节点选择'
            ]
        ];

        return Yaml::dump($config, 4, 2);
    }

    /**
     * 节点转 Clash 单项
     */
    private function nodeToClashProxy(array $node): ?array
    {
        $type = strtolower($node['type'] ?? '');
        $base = [
            'name' => $node['formatted_name'] ?? ($node['raw_name'] ?? 'Node'),
            'server' => $node['host'] ?? '',
            'port' => (int)($node['port'] ?? 0),
        ];

        if (empty($base['server']) || empty($base['port'])) return null;

        $network = strtolower($node['network'] ?? 'tcp');
        $wsOpts = [];
        if ($network === 'ws') {
            $wsOpts = [
                'path' => $node['path'] ?? '/',
                'headers' => [
                    'Host' => $node['sni'] ?: $node['host']
                ]
            ];
        }

        switch ($type) {
            case 'vmess':
                $proxy = array_merge($base, [
                    'type' => 'vmess',
                    'uuid' => $node['uuid'] ?? '',
                    'alterId' => (int)($node['alterId'] ?? 0),
                    'cipher' => $node['cipher'] ?? 'auto',
                    'tls' => !empty($node['tls']),
                    'network' => $network,
                    'servername' => $node['sni'] ?? '',
                    'skip-cert-verify' => true,
                ]);
                if ($network === 'ws') {
                    $proxy['ws-opts'] = $wsOpts;
                }
                return $proxy;
            case 'vless':
                $isReality = ($node['security'] ?? '') === 'reality' || !empty($node['pbk']);
                $vlessProxy = array_merge($base, [
                    'type' => 'vless',
                    'uuid' => $node['uuid'] ?? '',
                    'network' => $network,
                    'tls' => !empty($node['tls']) || $isReality,
                    'udp' => true,
                    'servername' => $node['sni'] ?? '',
                    'skip-cert-verify' => true,
                ]);
                if (!empty($node['flow'])) {
                    $vlessProxy['flow'] = $node['flow'];
                }
                if ($isReality) {
                    $vlessProxy['client-fingerprint'] = !empty($node['fp']) ? $node['fp'] : 'chrome';
                    $vlessProxy['reality-opts'] = [
                        'public-key' => $node['pbk'] ?? '',
                        'short-id' => $node['sid'] ?? '',
                    ];
                }
                if ($network === 'ws') {
                    $vlessProxy['ws-opts'] = $wsOpts;
                }
                return $vlessProxy;
            case 'trojan':
                return array_merge($base, [
                    'type' => 'trojan',
                    'password' => $node['password'] ?? '',
                    'sni' => $node['sni'] ?? $node['host'],
                    'skip-cert-verify' => true,
                ]);
            case 'shadowsocks':
            case 'ss':
                return array_merge($base, [
                    'type' => 'ss',
                    'cipher' => $node['cipher'] ?? 'aes-128-gcm',
                    'password' => $node['password'] ?? '',
                ]);
            case 'hysteria2':
            case 'hy2':
                return array_merge($base, [
                    'type' => 'hysteria2',
                    'password' => $node['password'] ?? '',
                    'sni' => $node['sni'] ?? '',
                    'skip-cert-verify' => true,
                ]);
            case 'http':
            case 'https':
                $isHttps = ($type === 'https') || !empty($node['tls']) || ($node['port'] ?? 0) == 443;
                $httpProxy = array_merge($base, [
                    'type' => 'http',
                    'tls' => $isHttps,
                    'skip-cert-verify' => true,
                ]);
                if (!empty($node['username'])) {
                    $httpProxy['username'] = (string)$node['username'];
                }
                if (!empty($node['password'])) {
                    $httpProxy['password'] = (string)$node['password'];
                }
                if (!empty($node['sni'])) {
                    $httpProxy['sni'] = (string)$node['sni'];
                }
                return $httpProxy;
            case 'socks5':
            case 'socks':
                $socksProxy = array_merge($base, [
                    'type' => 'socks5',
                    'skip-cert-verify' => true,
                    'udp' => true,
                ]);
                if (!empty($node['username'])) {
                    $socksProxy['username'] = (string)$node['username'];
                }
                if (!empty($node['password'])) {
                    $socksProxy['password'] = (string)$node['password'];
                }
                if (!empty($node['tls'])) {
                    $socksProxy['tls'] = true;
                }
                return $socksProxy;
            case 'anytls':
                return array_merge($base, [
                    'type' => 'anytls',
                    'password' => $node['password'] ?? ($node['uuid'] ?? ''),
                    'client-fingerprint' => $node['fp'] ?? ($node['client-fingerprint'] ?? 'chrome'),
                    'udp' => true,
                    'alpn' => [
                        'h2',
                        'http/1.1',
                    ],
                    'sni' => $node['sni'] ?: $node['host'],
                    'skip-cert-verify' => true,
                ]);
            case 'tuic':
                return array_merge($base, [
                    'type' => 'tuic',
                    'uuid' => $node['uuid'] ?? ($node['password'] ?? ''),
                    'password' => $node['password'] ?? ($node['uuid'] ?? ''),
                    'alpn' => ['h3'],
                    'sni' => $node['sni'] ?: $node['host'],
                    'skip-cert-verify' => true,
                    'congestion-controller' => 'bbr',
                    'udp-relay-mode' => 'native',
                ]);
            default:
                return null;
        }
    }

    /**
     * 构建 Base64 格式
     */
    private function buildBase64(array $nodes): string
    {
        $uris = [];
        foreach ($nodes as $n) {
            $uri = $this->nodeToSingleUri($n);
            if (!empty($uri)) {
                $uris[] = $uri;
            }
        }

        return base64_encode(implode("\n", $uris));
    }

    /**
     * 节点转单条标准客户端分享 URI (支持全协议：vmess, vless, trojan, ss, hy2, anytls, http, socks5, tuic)
     */
    public function nodeToSingleUri(array $n): ?string
    {
        $name = urlencode($n['formatted_name'] ?? ($n['raw_name'] ?? 'Free Node'));
        $type = strtolower($n['type'] ?? '');
        $host = $n['host'] ?? '';
        $port = (int)($n['port'] ?? 0);

        if (empty($host) || $port <= 0) return null;

        if ($type === 'trojan') {
            $sni = $n['sni'] ?: $host;
            return "trojan://{$n['password']}@{$host}:{$port}?security=tls&sni={$sni}&allowInsecure=1#{$name}";
        } elseif ($type === 'shadowsocks' || $type === 'ss') {
            $plain = "{$n['cipher']}:{$n['password']}";
            return "ss://" . base64_encode($plain) . "@{$host}:{$port}#{$name}";
        } elseif ($type === 'vmess') {
            $v = [
                'v' => '2',
                'ps' => $n['formatted_name'] ?? ($n['raw_name'] ?? 'Free Node'),
                'add' => $host,
                'port' => (string)$port,
                'id' => $n['uuid'] ?? '',
                'aid' => (string)($n['alterId'] ?? '0'),
                'net' => $n['network'] ?? 'tcp',
                'type' => 'none',
                'host' => $n['sni'] ?? '',
                'path' => $n['path'] ?? '/',
                'tls' => !empty($n['tls']) ? 'tls' : ''
            ];
            return 'vmess://' . base64_encode(json_encode($v));
        } elseif ($type === 'vless') {
            $isReality = ($n['security'] ?? '') === 'reality' || !empty($n['pbk']);
            $security = $isReality ? 'reality' : (!empty($n['tls']) ? 'tls' : 'none');
            $sni = $n['sni'] ?: $host;
            $net = $n['network'] ?? 'tcp';

            $queryParams = [
                'security' => $security,
                'sni' => $sni,
                'type' => $net,
            ];
            if (!empty($n['flow'])) {
                $queryParams['flow'] = $n['flow'];
            }
            if ($isReality) {
                if (!empty($n['pbk'])) $queryParams['pbk'] = $n['pbk'];
                if (!empty($n['sid'])) $queryParams['sid'] = $n['sid'];
                $queryParams['fp'] = !empty($n['fp']) ? $n['fp'] : 'chrome';
                if (!empty($n['spx'])) $queryParams['spx'] = $n['spx'];
            }
            if (!empty($n['path']) && $net !== 'tcp') {
                $queryParams['path'] = $n['path'];
            }

            $queryString = http_build_query($queryParams);
            return "vless://{$n['uuid']}@{$host}:{$port}?{$queryString}#{$name}";
        } elseif ($type === 'hysteria2' || $type === 'hy2') {
            $sni = $n['sni'] ?: $host;
            return "hysteria2://{$n['password']}@{$host}:{$port}?sni={$sni}&insecure=1#{$name}";
        } elseif ($type === 'anytls') {
            $pwd = rawurlencode($n['password'] ?? ($n['uuid'] ?? ''));
            $sni = $n['sni'] ?: $host;
            return "anytls://{$pwd}@{$host}:{$port}/?sni={$sni}&insecure=1#{$name}";
        } elseif ($type === 'http' || $type === 'https') {
            $scheme = ($type === 'https' || !empty($n['tls']) || $port == 443) ? 'https' : 'http';
            $auth = '';
            if (!empty($n['username']) && !empty($n['password'])) {
                $auth = rawurlencode($n['username']) . ':' . rawurlencode($n['password']) . '@';
            } elseif (!empty($n['password'])) {
                $auth = rawurlencode($n['password']) . '@';
            }
            return "{$scheme}://{$auth}{$host}:{$port}#{$name}";
        } elseif ($type === 'socks5' || $type === 'socks') {
            $auth = '';
            if (!empty($n['username']) && !empty($n['password'])) {
                $auth = rawurlencode($n['username']) . ':' . rawurlencode($n['password']) . '@';
            } elseif (!empty($n['password'])) {
                $auth = rawurlencode($n['password']) . '@';
            }
            return "socks5://{$auth}{$host}:{$port}#{$name}";
        } elseif ($type === 'tuic') {
            $uuid = rawurlencode($n['uuid'] ?? ($n['password'] ?? ''));
            $pwd = rawurlencode($n['password'] ?? ($n['uuid'] ?? ''));
            $sni = $n['sni'] ?: $host;
            return "tuic://{$uuid}:{$pwd}@{$host}:{$port}?sni={$sni}&alpn=h3&congestion_controller=bbr#{$name}";
        }

        return null;
    }

    /**
     * 流量耗尽保底提示
     */
    private function renderExhaustedResponse(Request $request)
    {
        $tipNode = [[
            'formatted_name' => '⚠️ 免费额度已耗尽 - 请前往官网签到或升级VIP',
            'type' => 'shadowsocks',
            'host' => '127.0.0.1',
            'port' => 10086,
            'cipher' => 'aes-128-gcm',
            'password' => 'traffic_exhausted'
        ]];
        return $this->renderNodes($request, $tipNode, (object)['u' => 0, 'd' => 0, 'transfer_enable' => 0, 'expired_at' => time()]);
    }

    /**
     * 节点库为空保底提示
     */
    private function renderEmptyPoolResponse(Request $request)
    {
        $tipNode = [[
            'formatted_name' => '🔄 免费节点库正在同步中，请稍后刷新订阅',
            'type' => 'shadowsocks',
            'host' => '127.0.0.1',
            'port' => 10086,
            'cipher' => 'aes-128-gcm',
            'password' => 'syncing'
        ]];
        return $this->renderNodes($request, $tipNode, (object)['u' => 0, 'd' => 0, 'transfer_enable' => 1073741824, 'expired_at' => time() + 86400]);
    }
}
