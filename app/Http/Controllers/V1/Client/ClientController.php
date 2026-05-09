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
            // account not expired and is not banned.
            $userService = new UserService();
            if ($userService->isAvailable($user)) {
                $serverService = new ServerService();
                $servers = $serverService->getAvailableServers($user);

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

                // 添加新记录到数组开头
                array_unshift($clientHistory, [
                    'type' => $clientType,
                    'time' => time()
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

                // 2. 如果没带 security=1，但 UA 是我们 App，为了安全，我们也强制使用加密逻辑（或者这里可以保留原样作为伪装）
                // 这里我们选择：如果 UA 匹配但没带参数，依然返回明文（作为伪装），或者你可以要求改为报错。
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
            }
            return response([
                'message' => 'Account is not available'
            ], 403);
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