<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\ServerHysteria;
use App\Models\ServerTuic;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerVmess;
use App\Models\ServerVless;
use App\Models\ServerAnytls;
use App\Models\ServerV2node;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatController extends Controller
{
    public function getOverride(Request $request)
    {
        return [
            'data' => [
                'online_user' => User::where('t','>=', time() - 600)
                    ->count(),
                'month_income' => Order::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'month_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->count(),
                'day_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->where('created_at', '<', time())
                    ->count(),
                'ticket_pending_total' => Ticket::where('status', 0)
                    ->where('reply_status', 0)
                    ->count(),
                'commission_pending_total' => Order::where('commission_status', 0)
                    ->where('invite_user_id', '!=', NULL)
                    ->whereNotIn('status', [0, 2])
                    ->where('commission_balance', '>', 0)
                    ->count(),
                'day_income' => Order::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'last_month_income' => Order::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'commission_month_payout' => CommissionLog::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->sum('get_amount'),
                'commission_last_month_payout' => CommissionLog::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->sum('get_amount'),
                'total_user' => User::where('plan_id', '!=', NULL)
                    ->where(function ($query) {
                        $query->where('expired_at', '>', time())
                            ->orWhere('expired_at', NULL);
                    })
                    ->count(),
                'day_traffic' => StatServer::where('record_at', '>=', strtotime(date('Y-m-d')))
                    ->where('record_type', 'd')
                    ->sum(DB::raw('u + d')),
            ]
        ];
    }

    public function getOrder(Request $request)
    {
        $statistics = Stat::where('record_type', 'd')
            ->limit(31)
            ->orderBy('record_at', 'DESC')
            ->get()
            ->toArray();
        $result = [];
        foreach ($statistics as $statistic) {
            $date = date('m-d', $statistic['record_at']);
            $result[] = [
                'type' => '注册人数',
                'date' => $date,
                'value' => $statistic['register_count']
            ];
            $result[] = [
                'type' => '收款金额',
                'date' => $date,
                'value' => $statistic['paid_total'] / 100
            ];
            $result[] = [
                'type' => '收款笔数',
                'date' => $date,
                'value' => $statistic['paid_count']
            ];
            $result[] = [
                'type' => '佣金金额(已发放)',
                'date' => $date,
                'value' => $statistic['commission_total'] / 100
            ];
            $result[] = [
                'type' => '佣金笔数(已发放)',
                'date' => $date,
                'value' => $statistic['commission_count']
            ];
        }
        $result = array_reverse($result);
        return [
            'data' => $result
        ];
    }

    public function getServerLastRank()
    {
        $servers = [
            'shadowsocks' => ServerShadowsocks::where('parent_id', null)->get()->toArray(),
            'v2ray' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'trojan' => ServerTrojan::where('parent_id', null)->get()->toArray(),
            'vmess' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'vless' => ServerVless::where('parent_id', null)->get()->toArray(),
            'tuic' => ServerTuic::where('parent_id', null)->get()->toArray(),
            'hysteria'=> ServerHysteria::where('parent_id', null)->get()->toArray(),
            'anytls' => ServerAnytls::where('parent_id', null)->get()->toArray(),
            'v2node' => ServerV2node::where('parent_id', null)->get()->toArray()
        ];
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        $statistics = StatServer::select([
            'server_id',
            'server_type',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(15)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        foreach ($statistics as $k => $v) {
            foreach ($servers[$v['server_type']] as $server) {
                if ($server['id'] === $v['server_id']) {
                    $statistics[$k]['server_name'] = $server['name'];
                }
            }
            $statistics[$k]['total'] = $statistics[$k]['total'] / 1073741824;
        }
        array_multisort(array_column($statistics, 'total'), SORT_DESC, $statistics);
        return [
            'data' => $statistics
        ];
    }

    public function getServerTodayRank()
    {
        $servers = [
            'shadowsocks' => ServerShadowsocks::where('parent_id', null)->get()->toArray(),
            'v2ray' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'trojan' => ServerTrojan::where('parent_id', null)->get()->toArray(),
            'vmess' => ServerVmess::where('parent_id', null)->get()->toArray(),
            'vless' => ServerVless::where('parent_id', null)->get()->toArray(),
            'tuic' => ServerTuic::where('parent_id', null)->get()->toArray(),
            'hysteria'=> ServerHysteria::where('parent_id', null)->get()->toArray(),
            'anytls' => ServerAnytls::where('parent_id', null)->get()->toArray(),
            'v2node' => ServerV2node::where('parent_id', null)->get()->toArray()
        ];
        $startAt = strtotime(date('Y-m-d'));
        $endAt = time();
        $statistics = StatServer::select([
            'server_id',
            'server_type',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(15)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        foreach ($statistics as $k => $v) {
            foreach ($servers[$v['server_type']] as $server) {
                if ($server['id'] === $v['server_id']) {
                    $statistics[$k]['server_name'] = $server['name'];
                }
            }
            $statistics[$k]['total'] = $statistics[$k]['total'] / 1073741824;
        }
        array_multisort(array_column($statistics, 'total'), SORT_DESC, $statistics);
        return [
            'data' => $statistics
        ];
    }

    public function getUserTodayRank()
    {
        $startAt = strtotime(date('Y-m-d'));
        $endAt = time();
        $statistics = StatUser::select([
            'user_id',
            'server_rate',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(30)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        $data = [];
        $idIndexMap = [];
        foreach ($statistics as $k => $v) {
            $id = $statistics[$k]['user_id'];
            $user = User::where('id', $id)->first();
            $statistics[$k]['email'] = empty($user) ? "null" : $user['email'];
            $statistics[$k]['total'] = $statistics[$k]['total'] * $statistics[$k]['server_rate'] / 1073741824;
            if (isset($idIndexMap[$id])) {
                $index = $idIndexMap[$id];
                $data[$index]['total'] += $statistics[$k]['total'];
            } else {
                unset($statistics[$k]['server_rate']);
                $data[] = $statistics[$k];
                $idIndexMap[$id] = count($data) - 1;
            }
        }
        array_multisort(array_column($data, 'total'), SORT_DESC, $data);
        return [
            'data' => array_slice($data, 0, 15)
        ];
    }

    public function getUserLastRank()
    {
        $startAt = strtotime('-1 day', strtotime(date('Y-m-d')));
        $endAt = strtotime(date('Y-m-d'));
        $statistics = StatUser::select([
            'user_id',
            'server_rate',
            'u',
            'd',
            DB::raw('(u+d) as total')
        ])
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->where('record_type', 'd')
            ->limit(30)
            ->orderBy('total', 'DESC')
            ->get()
            ->toArray();
        $data = [];
        $idIndexMap = [];
        foreach ($statistics as $k => $v) {
            $id = $statistics[$k]['user_id'];
            $user = User::where('id', $id)->first();
            $statistics[$k]['email'] = empty($user) ? "null" : $user['email'];
            $statistics[$k]['total'] = $statistics[$k]['total'] * $statistics[$k]['server_rate'] / 1073741824;
            if (isset($idIndexMap[$id])) {

                $index = $idIndexMap[$id];
                $data[$index]['total'] += $statistics[$k]['total'];
            } else {
                unset($statistics[$k]['server_rate']);
                $data[] = $statistics[$k];
                $idIndexMap[$id] = count($data) - 1;
            }
        }
        array_multisort(array_column($data, 'total'), SORT_DESC, $data);
        return [
            'data' => array_slice($data, 0, 15)
        ];
    }

    public function getStatUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer'
        ]);
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $builder = StatUser::orderBy('record_at', 'DESC')->where('user_id', $request->input('user_id'));

        $total = $builder->count();
        $records = $builder->forPage($current, $pageSize)
            ->get();
        return [
            'data' => $records,
            'total' => $total
        ];
    }

    public function getSubscriptionAnomalies()
    {
        $configPath = storage_path('tianque_config.json');
        $config = [];
        if (file_exists($configPath)) {
            $config = json_decode(@file_get_contents($configPath), true) ?: [];
        }

        $flaggedUsers = $config['flagged_users'] ?? [];
        $honeypotUsers = array_map('intval', $config['honeypot_users'] ?? []);
        $whitelistUsers = $config['whitelist_users'] ?? [];

        $flaggedIds = array_keys($flaggedUsers);
        $users = User::whereIn('id', $flaggedIds)->get(['id', 'email', 'client_type', 't', 'banned'])->keyBy('id');

        $data = [];
        foreach ($flaggedUsers as $uid => $info) {
            $user = $users->get($uid);
            $email = $info['email'] ?? ($user ? $user->email : '未知用户');
            $time = $info['time'] ?? time();
            $reasons = $info['reasons'] ?? [];

            $history = [];
            if ($user && $user->client_type) {
                $history = json_decode($user->client_type, true) ?: [];
            }

            $inHoneypot = in_array((int)$uid, $honeypotUsers, true) ? 1 : 0;
            $banned = $user ? (int)$user->banned : 0;

            // Skip if in whitelist
            $isInWhitelist = false;
            foreach ($whitelistUsers as $wlItem) {
                if ($uid == $wlItem || ($user && strtolower($user->email) === strtolower(trim($wlItem)))) {
                    $isInWhitelist = true;
                    break;
                }
            }
            if ($isInWhitelist) {
                continue;
            }

            $data[] = [
                'user_id' => (int)$uid,
                'email' => $email,
                'flagged_at' => $time,
                'reasons' => $reasons,
                'in_honeypot' => $inHoneypot,
                'banned' => $banned,
                'history' => $history,
                'type' => 'flagged',
                'risk_level' => 'high'
            ];
        }

        // Process honeypot users who are not in flagged_users
        $honeypotDbUsers = User::whereIn('id', $honeypotUsers)->get(['id', 'email', 'client_type', 't', 'banned'])->keyBy('id');
        foreach ($honeypotUsers as $uid) {
            if (isset($flaggedUsers[$uid])) {
                continue;
            }
            $user = $honeypotDbUsers->get($uid);
            if (!$user) {
                continue;
            }

            // Skip if in whitelist
            $isInWhitelist = false;
            foreach ($whitelistUsers as $wlItem) {
                if ($uid == $wlItem || strtolower($user->email) === strtolower(trim($wlItem))) {
                    $isInWhitelist = true;
                    break;
                }
            }
            if ($isInWhitelist) {
                continue;
            }

            $history = [];
            if ($user->client_type) {
                $history = json_decode($user->client_type, true) ?: [];
            }

            $data[] = [
                'user_id' => (int)$uid,
                'email' => $user->email,
                'flagged_at' => $user->t ?: time(),
                'reasons' => ['安全蜜罐账号已接管'],
                'in_honeypot' => 1,
                'banned' => (int)$user->banned,
                'history' => $history,
                'type' => 'honeypot',
                'risk_level' => 'low'
            ];
        }

        // Suspected Users
        $abnormalKeywords = ['curl', 'wget', 'python', 'requests', 'go-http', 'urllib', 'httpclient', 'postman', 'aria2'];
        $query = User::where('banned', 0)
            ->whereNotNull('client_type')
            ->whereNotIn('id', $flaggedIds);
        if (!empty($honeypotUsers)) {
            $query->whereNotIn('id', $honeypotUsers);
        }

        $query->where(function($q) use ($abnormalKeywords) {
            foreach ($abnormalKeywords as $kw) {
                $q->orWhere('client_type', 'like', '%' . $kw . '%');
            }
        });

        $suspectedList = $query->limit(30)->get(['id', 'email', 'client_type', 't', 'banned']);

        foreach ($suspectedList as $user) {
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

            $history = json_decode($user->client_type, true) ?: [];
            $matchedKeywords = [];
            foreach ($history as $hItem) {
                $uaLower = strtolower($hItem['ua'] ?? '');
                foreach ($abnormalKeywords as $kw) {
                    if (strpos($uaLower, $kw) !== false) {
                        $matchedKeywords[] = "拉取记录发现敏感 UA: " . ($hItem['ua'] ?? $kw);
                    }
                }
            }
            $matchedKeywords = array_values(array_unique($matchedKeywords));

            if (empty($matchedKeywords)) {
                continue;
            }

            $data[] = [
                'user_id' => (int)$user->id,
                'email' => $user->email,
                'flagged_at' => $user->t ?: time(),
                'reasons' => $matchedKeywords,
                'in_honeypot' => 0,
                'banned' => (int)$user->banned,
                'history' => $history,
                'type' => 'suspected',
                'risk_level' => 'low'
            ];
        }

        usort($data, function ($a, $b) {
            return $b['flagged_at'] <=> $a['flagged_at'];
        });

        return [
            'data' => [
                'list' => $data,
                'whitelist' => array_values($whitelistUsers),
                'banned_ips' => array_values($config['banned_ips'] ?? []),
                'config' => [
                    'ip_limit' => isset($config['ip_limit']) ? (int)$config['ip_limit'] : 10,
                    'audit_ua_enabled' => isset($config['audit_ua_enabled']) ? (bool)$config['audit_ua_enabled'] : true
                ]
            ]
        ];
    }

    public function ignoreAnomaly(Request $request)
    {
        $userId = (string)$request->input('id');
        $configPath = storage_path('tianque_config.json');
        if (!file_exists($configPath)) {
            abort(500, '配置文件不存在');
        }

        $config = json_decode(@file_get_contents($configPath), true) ?: [];
        if (isset($config['flagged_users'][$userId])) {
            unset($config['flagged_users'][$userId]);
            @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        return response([
            'data' => true
        ]);
    }

    public function whitelistUser(Request $request)
    {
        $userId = $request->input('id');
        $identity = trim($request->input('identity'));

        $configPath = storage_path('tianque_config.json');
        $config = [];
        if (file_exists($configPath)) {
            $config = json_decode(@file_get_contents($configPath), true) ?: [];
        }

        if (!isset($config['whitelist_users']) || !is_array($config['whitelist_users'])) {
            $config['whitelist_users'] = [];
        }

        if ($userId) {
            $user = User::find((int)$userId);
            $wlIdentity = $user ? $user->email : (string)$userId;
            
            // Add to whitelist
            if (!in_array($wlIdentity, $config['whitelist_users'])) {
                $config['whitelist_users'][] = $wlIdentity;
            }

            // Remove from flagged_users
            if (isset($config['flagged_users'][(string)$userId])) {
                unset($config['flagged_users'][(string)$userId]);
            }
        } elseif ($identity) {
            // Check if it matches any user ID to clean flagged_users
            $user = null;
            if (is_numeric($identity)) {
                $user = User::find((int)$identity);
                $userIdStr = (string)$identity;
            } else {
                $user = User::where('email', $identity)->first();
                $userIdStr = $user ? (string)$user->id : null;
            }

            $wlIdentity = $identity;
            if (!in_array($wlIdentity, $config['whitelist_users'])) {
                $config['whitelist_users'][] = $wlIdentity;
            }

            if ($userIdStr && isset($config['flagged_users'][$userIdStr])) {
                unset($config['flagged_users'][$userIdStr]);
            }
        }

        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return response([
            'data' => true
        ]);
    }

    public function removeWhitelistUser(Request $request)
    {
        $identity = trim($request->input('identity'));
        $configPath = storage_path('tianque_config.json');
        if (!file_exists($configPath)) {
            abort(500, '配置文件不存在');
        }

        $config = json_decode(@file_get_contents($configPath), true) ?: [];
        if (isset($config['whitelist_users']) && is_array($config['whitelist_users'])) {
            $key = array_search($identity, $config['whitelist_users']);
            if ($key !== false) {
                unset($config['whitelist_users'][$key]);
                $config['whitelist_users'] = array_values($config['whitelist_users']);
                @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }

        return response([
            'data' => true
        ]);
    }

    public function saveSubscriptionAuditSettings(Request $request)
    {
        $ipLimit = (int)$request->input('ip_limit', 10);
        $auditUaEnabled = (bool)$request->input('audit_ua_enabled', true);

        $configPath = storage_path('tianque_config.json');
        $config = [];
        if (file_exists($configPath)) {
            $config = json_decode(@file_get_contents($configPath), true) ?: [];
        }

        $config['ip_limit'] = $ipLimit;
        $config['audit_ua_enabled'] = $auditUaEnabled;

        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return response([
            'data' => true
        ]);
    }

    public function banIp(Request $request)
    {
        $ip = trim($request->input('ip'));
        if (empty($ip)) {
            abort(500, 'IP不能为空');
        }

        $configPath = storage_path('tianque_config.json');
        $config = [];
        if (file_exists($configPath)) {
            $config = json_decode(@file_get_contents($configPath), true) ?: [];
        }

        if (!isset($config['banned_ips']) || !is_array($config['banned_ips'])) {
            $config['banned_ips'] = [];
        }

        if (!in_array($ip, $config['banned_ips'], true)) {
            $config['banned_ips'][] = $ip;
        }

        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return response([
            'data' => true
        ]);
    }

    public function removeBanIp(Request $request)
    {
        $ip = trim($request->input('ip'));
        $configPath = storage_path('tianque_config.json');
        if (!file_exists($configPath)) {
            abort(500, '配置文件不存在');
        }

        $config = json_decode(@file_get_contents($configPath), true) ?: [];
        if (isset($config['banned_ips']) && is_array($config['banned_ips'])) {
            $key = array_search($ip, $config['banned_ips']);
            if ($key !== false) {
                unset($config['banned_ips'][$key]);
                $config['banned_ips'] = array_values($config['banned_ips']);
                @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }

        return response([
            'data' => true
        ]);
    }
}

