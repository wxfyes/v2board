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
            if ($isBanned) {
                // 同样记录被封禁账号的客户端拉取行为！以防内鬼残留探测漏抓
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

                return response([
                    'message' => 'Your account has been suspended'
                ], 403);
            }

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