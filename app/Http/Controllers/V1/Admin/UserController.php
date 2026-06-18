<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserFetch;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\Ticket;
use App\Models\Order;
use App\Models\Plan;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class UserController extends Controller
{
    public function resetSecret(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user) abort(500, '用户不存在');
        $user->token = Helper::guid();
        $user->uuid = Helper::guid(true);
        return response([
            'data' => $user->save()
        ]);
    }

    private function filter(Request $request, $builder)
    {
        $filters = $request->input('filter');
        if ($filters) {
            foreach ($filters as $k => $filter) {
                if ($filter['key'] === 'in_honeypot') {
                    $configPath = storage_path('tianque_config.json');
                    $honeypotUsers = [];
                    if (file_exists($configPath)) {
                        $tianqueConfig = json_decode(@file_get_contents($configPath), true);
                        if (is_array($tianqueConfig) && isset($tianqueConfig['honeypot_users'])) {
                            $honeypotUsers = array_map('intval', $tianqueConfig['honeypot_users']);
                        }
                    }
                    if ((int)$filter['value'] === 1) {
                        $builder->whereIn('id', $honeypotUsers);
                    } else {
                        $builder->whereNotIn('id', $honeypotUsers);
                    }
                    continue;
                }
                if ($filter['condition'] === '模糊') {
                    $filter['condition'] = 'like';
                    $filter['value'] = "%{$filter['value']}%";
                }
                if ($filter['key'] === 'd' || $filter['key'] === 'transfer_enable') {
                    $filter['value'] = $filter['value'] * 1073741824;
                }
                if ($filter['key'] === 'invite_by_email') {
                    $user = User::where('email', $filter['condition'], $filter['value'])->first();
                    $inviteUserId = isset($user->id) ? $user->id : 0;
                    $builder->where('invite_user_id', $inviteUserId);
                    unset($filters[$k]);
                    continue;
                }
                if ($filter['key'] === 'plan_id' && $filter['value'] == 'null') {
                    $builder->whereNull('plan_id');
                    continue;
                }
                $builder->where($filter['key'], $filter['condition'], $filter['value']);
            }
        }
    }

    public function fetch(UserFetch $request)
    {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $userModel = User::select(
            DB::raw('*'),
            DB::raw('(u+d) as total_used')
        )
            ->orderBy($sort, $sortType);
        $this->filter($request, $userModel);
        $total = $userModel->count();
        $res = $userModel->forPage($current, $pageSize)
            ->get();
        $plan = Plan::get();
        $configPath = storage_path('tianque_config.json');
        $honeypotUsers = [];
        if (file_exists($configPath)) {
            $tianqueConfig = json_decode(@file_get_contents($configPath), true);
            if (is_array($tianqueConfig) && isset($tianqueConfig['honeypot_users'])) {
                $honeypotUsers = array_map('intval', $tianqueConfig['honeypot_users']);
            }
        }
        for ($i = 0; $i < count($res); $i++) {
            for ($k = 0; $k < count($plan); $k++) {
                if ($plan[$k]['id'] == $res[$i]['plan_id']) {
                    $res[$i]['plan_name'] = $plan[$k]['name'];
                }
            }
            $res[$i]['in_honeypot'] = in_array((int)$res[$i]['id'], $honeypotUsers, true) ? 1 : 0;
            //统计在线设备
            $countalive = 0;
            $ips = [];
            $ips_array = Cache::get('ALIVE_IP_USER_'. $res[$i]['id']);
            if ($ips_array) {
                $countalive = $ips_array['alive_ip'];
                foreach($ips_array as $nodetypeid => $data) {
                    if (!is_int($data) && isset($data['aliveips'])) {
                        foreach($data['aliveips'] as $ip_NodeId) {
                            $ip = explode("_", $ip_NodeId)[0];
                            $ips[] = $ip . '_' . $nodetypeid;
                        }
                    }
                }
            }
            $res[$i]['alive_ip'] = $countalive;
            $res[$i]['ips'] = implode(', ', $ips);
            $res[$i]['subscribe_url'] = Helper::getSubscribeUrl($res[$i]['token']);

            // 获取最后登录数据
            $authService = new AuthService($res[$i]);
            $sessions = $authService->getSessions();
            $lastLoginIp = '无登录记录';
            $lastLoginTime = '无登录记录';
            $lastLoginLocation = '未知地区';
            if (!empty($sessions) && is_array($sessions)) {
                uasort($sessions, function($a, $b) {
                    return ($b['login_at'] ?? 0) <=> ($a['login_at'] ?? 0);
                });
                $latestSession = reset($sessions);
                $lastLoginIp = $latestSession['ip'] ?? '未知';
                $lastLoginTime = isset($latestSession['login_at']) ? date('Y-m-d H:i:s', $latestSession['login_at']) : '未知';
                if ($lastLoginIp !== '未知' && $lastLoginIp !== '无登录记录') {
                    $lastLoginLocation = $this->getIpLocation($lastLoginIp);
                }
            }
            $res[$i]['last_login_ip'] = $lastLoginIp;
            $res[$i]['last_login_time'] = $lastLoginTime;
            $res[$i]['last_login_location'] = $lastLoginLocation;
        }
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    private function getIpLocation($ip)
    {
        if (empty($ip) || $ip === '127.0.0.1' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '本地局域网';
        }

        $cacheKey = "ip_loc_" . md5($ip);
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $ctx = stream_context_create(['http' => ['timeout' => 1]]);
            $res = @file_get_contents("http://ip-api.com/json/{$ip}?lang=zh-CN", false, $ctx);
            if ($res) {
                $data = json_decode($res, true);
                if (isset($data['status']) && $data['status'] === 'success') {
                    $country = $data['country'] ?? '';
                    $region = $data['regionName'] ?? '';
                    $city = $data['city'] ?? '';
                    $isp = $data['isp'] ?? '';
                    $org = $data['org'] ?? '';

                    $ispLower = strtolower($isp . ' ' . $org);
                    $ispCn = '';
                    if (strpos($ispLower, 'chinanet') !== false || strpos($ispLower, 'telecom') !== false) {
                        $ispCn = '电信';
                    } elseif (strpos($ispLower, 'unicom') !== false) {
                        $ispCn = '联通';
                    } elseif (strpos($ispLower, 'mobile') !== false || strpos($ispLower, 'cmnet') !== false) {
                        $ispCn = '移动';
                    } elseif (strpos($ispLower, 'amazon') !== false || strpos($ispLower, 'aws') !== false) {
                        $ispCn = 'AWS';
                    } elseif (strpos($ispLower, 'alibaba') !== false || strpos($ispLower, 'aliyun') !== false) {
                        $ispCn = '阿里云';
                    } elseif (strpos($ispLower, 'tencent') !== false) {
                        $ispCn = '腾讯云';
                    } elseif (strpos($ispLower, 'cloudflare') !== false) {
                        $ispCn = 'Cloudflare';
                    } else {
                        $ispCn = $isp;
                    }

                    if ($country === '中国') {
                        $loc = $region;
                        if ($city && $city !== $region) {
                            $loc .= $city;
                        }
                        $location = trim($loc . ' ' . $ispCn);
                    } else {
                        $loc = $country;
                        if ($region && $region !== $country) {
                            $loc .= $region;
                        }
                        $location = trim($loc . ' ' . $ispCn);
                    }

                    Cache::put($cacheKey, $location, 86400 * 30);
                    return $location;
                }
            }
        } catch (\Exception $e) {}

        Cache::put($cacheKey, '未知地理位置', 86400); // 即使失败也缓存一天，防空阻断
        return '未知地理位置';
    }

    public function getUserInfoById(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        $user = User::find($request->input('id'));
        if ($user->invite_user_id) {
            $user['invite_user'] = User::find($user->invite_user_id);
        }
        return response([
            'data' => $user
        ]);
    }

    public function update(UserUpdate $request)
    {
        $params = $request->validated();
        $user = User::find($request->input('id'));
        if (!$user) {
            abort(500, '用户不存在');
        }
        if (User::where('email', $params['email'])->first() && $user->email !== $params['email']) {
            abort(500, '邮箱已被使用');
        }
        if (isset($params['password'])) {
            $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
            $params['password_algo'] = NULL;
        } else {
            unset($params['password']);
        }
        if (isset($params['plan_id'])) {
            $plan = Plan::find($params['plan_id']);
            if (!$plan) {
                abort(500, '订阅计划不存在');
            }
            $params['group_id'] = $plan->group_id;
        }
        if ($request->input('invite_user_email')) {
            $inviteUser = User::where('email', $request->input('invite_user_email'))->first();
            if ($inviteUser) {
                $params['invite_user_id'] = $inviteUser->id;
            }
        } else {
            $params['invite_user_id'] = null;
        }

        if (isset($params['banned']) && (int)$params['banned'] === 1) {
            $authService = new AuthService($user);
            $authService->removeAllSession();
        }

        try {
            $user->update($params);
        } catch (\Exception $e) {
            abort(500, '保存失败');
        }
        return response([
            'data' => true
        ]);
    }

    public function dumpCSV(Request $request)
    {
        $userModel = User::orderBy('id', 'asc');
        $this->filter($request, $userModel);
        $res = $userModel->get();
        $plan = Plan::get();
        for ($i = 0; $i < count($res); $i++) {
            for ($k = 0; $k < count($plan); $k++) {
                if ($plan[$k]['id'] == $res[$i]['plan_id']) {
                    $res[$i]['plan_name'] = $plan[$k]['name'];
                }
            }
        }

        $data = "邮箱,余额,推广佣金,总流量,设备数限制,剩余流量,套餐到期时间,订阅计划,订阅地址\r\n";
        foreach($res as $user) {
            $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
            $balance = $user['balance'] / 100;
            $commissionBalance = $user['commission_balance'] / 100;
            $transferEnable = $user['transfer_enable'] ? $user['transfer_enable'] / 1073741824 : 0;
            $deviceLimit = $user['devce_limit'] ? $user['devce_limit'] : NULL;
            $notUseFlow = (($user['transfer_enable'] - ($user['u'] + $user['d'])) / 1073741824) ?? 0;
            $planName = $user['plan_name'] ?? '无订阅';
            $subscribeUrl =  Helper::getSubscribeUrl($user['token']);
            $data .= "{$user['email']},{$balance},{$commissionBalance},{$transferEnable}, {$deviceLimit}, {$notUseFlow},{$expireDate},{$planName},{$subscribeUrl}\r\n";

        }
        echo "\xEF\xBB\xBF" . $data;
    }

    public function generate(UserGenerate $request)
    {
        if ($request->input('email_prefix')) {
            if ($request->input('plan_id')) {
                $plan = Plan::find($request->input('plan_id'));
                if (!$plan) {
                    abort(500, '订阅计划不存在');
                }
            }
            $user = [
                'email' => $request->input('email_prefix') . '@' . $request->input('email_suffix'),
                'plan_id' => isset($plan->id) ? $plan->id : NULL,
                'group_id' => isset($plan->group_id) ? $plan->group_id : NULL,
                'transfer_enable' => isset($plan->transfer_enable) ? $plan->transfer_enable * 1073741824 : 0,
                'device_limit' => isset($plan->device_limit) ? $plan->device_limit : NULL,
                'expired_at' => $request->input('expired_at') ?? NULL,
                'uuid' => Helper::guid(true),
                'token' => Helper::guid()
            ];
            if (User::where('email', $user['email'])->first()) {
                abort(500, '邮箱已存在于系统中');
            }
            $user['password'] = password_hash($request->input('password') ?? $user['email'], PASSWORD_DEFAULT);
            if (!User::create($user)) {
                abort(500, '生成失败');
            }
            return response([
                'data' => true
            ]);
        }
        if ($request->input('generate_count')) {
            $this->multiGenerate($request);
        }
    }

    private function multiGenerate(Request $request)
    {
        if ($request->input('plan_id')) {
            $plan = Plan::find($request->input('plan_id'));
            if (!$plan) {
                abort(500, '订阅计划不存在');
            }
        }
        $users = [];
        for ($i = 0;$i < $request->input('generate_count');$i++) {
            $user = [
                'email' => Helper::randomChar(6) . '@' . $request->input('email_suffix'),
                'plan_id' => isset($plan->id) ? $plan->id : NULL,
                'group_id' => isset($plan->group_id) ? $plan->group_id : NULL,
                'transfer_enable' => isset($plan->transfer_enable) ? $plan->transfer_enable * 1073741824 : 0,
                'device_limit' => isset($plan->device_limit) ? $plan->device_limit : NULL,
                'expired_at' => $request->input('expired_at') ?? NULL,
                'uuid' => Helper::guid(true),
                'token' => Helper::guid(),
                'created_at' => time(),
                'updated_at' => time()
            ];
            $user['password'] = password_hash($request->input('password') ?? $user['email'], PASSWORD_DEFAULT);
            array_push($users, $user);
        }
        DB::beginTransaction();
        if (!User::insert($users)) {
            DB::rollBack();
            abort(500, '生成失败');
        }
        DB::commit();
        $data = "账号,密码,过期时间,UUID,创建时间,订阅地址\r\n";
        foreach($users as $user) {
            $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
            $createDate = date('Y-m-d H:i:s', $user['created_at']);
            $password = $request->input('password') ?? $user['email'];
            $subscribeUrl = Helper::getSubscribeUrl($user['token']);
            $data .= "{$user['email']},{$password},{$expireDate},{$user['uuid']},{$createDate},{$subscribeUrl}\r\n";
        }
        echo $data;
    }

    public function sendMail(UserSendMail $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);
        foreach ($builder->cursor() as $user) {
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => $request->input('subject'),
                'template_name' => 'notify',
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url'),
                    'content' => $request->input('content')
                ]
            ], 'send_email_mass');
        }

        return response([
            'data' => true
        ]);
    }

    public function ban(Request $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);
        try {
            $builder->each(function ($user){
                $authService = new AuthService($user);
                $authService->removeAllSession();
            });
            $builder->update([
                'banned' => 1
            ]);
        } catch (\Exception $e) {
            abort(500, '处理失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function allDel(Request $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);

        DB::beginTransaction();
        try {
            $builder->each(function ($user){
                $authService = new AuthService($user);
                $authService->removeAllSession();
                Order::where('user_id', $user->id)->delete();
                InviteCode::where('user_id', $user->id)->delete();
                $tickets = Ticket::where('user_id', $user->id)->get();
                foreach($tickets as $ticket) {
                    TicketMessage::where('ticket_id', $ticket->id)->delete();
                }
                Ticket::where('user_id', $user->id)->delete();
                User::where('invite_user_id', $user->id)->update(['invite_user_id' => null]);
            });
            $builder->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, '批量删除用户信息失败');
        }  

        return response([
            'data' => true
        ]);
    }

    public function delUser(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user) {
            abort(500, '用户不存在');
        }
        DB::beginTransaction();
        try {
            $authService = new AuthService($user);
            $authService->removeAllSession();
            Order::where('user_id', $request->input('id'))->delete();
            User::where('invite_user_id', $request->input('id'))->update(['invite_user_id' => null]);
            InviteCode::where('user_id', $request->input('id'))->delete();
            
            $tickets = Ticket::where('user_id', $request->input('id'))->get();
            foreach($tickets as $ticket) {
                TicketMessage::where('ticket_id', $ticket->id)->delete();
            }
            Ticket::where('user_id', $request->input('id'))->delete();
    
            $user->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, '删除用户失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function toggleHoneypot(Request $request)
    {
        $userId = (int)$request->input('id');
        $user = User::find($userId);
        if (!$user) {
            abort(500, '用户不存在');
        }

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

        $currentHoneypots = array_map('intval', $config['honeypot_users']);
        $inHoneypot = in_array($userId, $currentHoneypots, true);

        if ($inHoneypot) {
            // Remove
            $key = array_search($userId, $currentHoneypots, true);
            if ($key !== false) {
                unset($currentHoneypots[$key]);
            }
            $config['honeypot_users'] = array_values($currentHoneypots);
            if (isset($config['honeypot_times'][(string)$userId])) {
                unset($config['honeypot_times'][(string)$userId]);
            }
            $status = 0;
        } else {
            // Add
            $currentHoneypots[] = $userId;
            $config['honeypot_users'] = $currentHoneypots;
            $config['honeypot_times'][(string)$userId] = time();
            
            // Auto remove from flagged_users if exists
            if (isset($config['flagged_users']) && is_array($config['flagged_users'])) {
                if (isset($config['flagged_users'][(string)$userId])) {
                    unset($config['flagged_users'][(string)$userId]);
                }
            }
            $status = 1;
        }

        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return response([
            'data' => [
                'status' => $status
            ]
        ]);
    }
}

