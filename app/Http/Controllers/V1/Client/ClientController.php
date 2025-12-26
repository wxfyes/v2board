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

            // Special handling for MOMclash (TianQueApp)
            // Enforce that this logic only triggers for subscription-related requests
            if (stripos($userAgent, 'TianQueApp') !== false && ($request->is('**/subscribe') || $request->has('token'))) {
                $class = new \App\Protocols\MOMclash($user, $servers);
                return response($class->handle());
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

        // 用户不可用时，返回友好提示
        return $this->getUnavailableResponse($user);
    }

    /**
     * 生成用户不可用时的友好提示响应
     */
    private function getUnavailableResponse($user)
    {
        $messages = [];

        // 判断具体原因
        if ($user['banned']) {
            $messages[] = '账户已被封禁，请联系客服';
        }

        if (!$user['transfer_enable']) {
            $messages[] = '无可用流量套餐';
        } elseif (($user['u'] + $user['d']) >= $user['transfer_enable']) {
            $messages[] = '流量已用尽，请续费或购买流量包';
        }

        if ($user['expired_at'] && $user['expired_at'] <= time()) {
            $expiredDate = date('Y-m-d', $user['expired_at']);
            $messages[] = "套餐已于 {$expiredDate} 到期，请续费";
        }

        if (empty($messages)) {
            $messages[] = '账户状态异常，请登录网站查看';
        }

        // 生成一个提示用的"假节点"配置
        $tipContent = implode(' | ', $messages);

        // 返回 Base64 编码的提示信息（通用格式）
        $fakeNode = "vmess://" . base64_encode(json_encode([
            'v' => '2',
            'ps' => '⚠️ ' . $tipContent,
            'add' => 'example.com',
            'port' => '443',
            'id' => '00000000-0000-0000-0000-000000000000',
            'aid' => '0',
            'net' => 'tcp',
            'type' => 'none',
            'tls' => ''
        ]));

        return response($fakeNode, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'subscription-userinfo' => 'upload=0; download=0; total=0; expire=0'
        ]);
    }

    /**
     * 解析 User-Agent 获取客户端类型
     */
    private function parseClientType($userAgent)
    {
        $userAgent = strtolower($userAgent);

        // 常见客户端识别
        $clients = [
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