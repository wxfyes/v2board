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
        $ignoreIps = $config['ignore_ips'] ?? [];

        $flaggedIds = array_keys($flaggedUsers);
        $users = User::whereIn('id', $flaggedIds)->get(['id', 'email', 'client_type', 't', 'banned'])->keyBy('id');

        // 统计最近 24 小时内所有用户的 IP 共用情况，过滤海外 IP 联合探测行为
        $allUsersWithLog = User::whereNotNull('client_type')->get(['id', 'email', 'client_type']);
        $ipUserMap = [];
        $now = time();
        foreach ($allUsersWithLog as $u) {
            $hist = json_decode($u->client_type, true) ?: [];
            $hist = $this->filterClientHistory($hist, $ignoreIps);
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
        $userEmailMap = $allUsersWithLog->pluck('email', 'id')->toArray();
        foreach ($ipUserMap as $ip => $uids) {
            if (count($uids) >= 2) {
                if ($this->isOverseasIp($ip)) {
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
                            'others' => $otherEmails
                        ];
                    }
                }
            }
        }

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
            $history = $this->filterClientHistory($history, $ignoreIps);
            foreach ($history as &$hItem) {
                $hItem['location'] = $this->getIpInfo($hItem['ip'] ?? '')['location'];
            }
            unset($hItem);

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
            $history = $this->filterClientHistory($history, $ignoreIps);
            foreach ($history as &$hItem) {
                $hItem['location'] = $this->getIpInfo($hItem['ip'] ?? '')['location'];
            }
            unset($hItem);

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
            $history = $this->filterClientHistory($history, $ignoreIps);
            foreach ($history as &$hItem) {
                $hItem['location'] = $this->getIpInfo($hItem['ip'] ?? '')['location'];
            }
            unset($hItem);
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

        // --- 🛡️ 智能行为画像扫描引擎 ---
        // 查找可能没有敏感 UA，但符合“只拉订阅不跑流量”或者“多 IP 异地跨度拉取”的活跃用户
        $activeUsers = User::where('banned', 0)
            ->whereNotNull('client_type')
            ->whereNotIn('id', $flaggedIds);
        if (!empty($honeypotUsers)) {
            $activeUsers->whereNotIn('id', $honeypotUsers);
        }
        
        // 取最近有订阅活动的前 150 个用户来深入画像分析
        $potentialUsers = $activeUsers->orderBy('t', 'desc')->limit(150)->get(['id', 'email', 'u', 'd', 'client_type', 't', 'banned']);
        
        foreach ($potentialUsers as $user) {
            // 排除已经在 $data 中的
            $alreadyExists = false;
            foreach ($data as $dItem) {
                if ($dItem['user_id'] == $user->id) {
                    $alreadyExists = true;
                    break;
                }
            }
            if ($alreadyExists) continue;

            $isInWhitelist = false;
            foreach ($whitelistUsers as $wlItem) {
                if ($user->id == $wlItem || strtolower($user->email) === strtolower(trim($wlItem))) {
                    $isInWhitelist = true;
                    break;
                }
            }
            if ($isInWhitelist) continue;

            $history = json_decode($user->client_type, true) ?: [];
            $history = $this->filterClientHistory($history, $ignoreIps);
            if (empty($history)) continue;
            foreach ($history as &$hItem) {
                $hItem['location'] = $this->getIpInfo($hItem['ip'] ?? '')['location'];
            }
            unset($hItem);

            $reasons = [];

            // 1. 画像分析 A：只拉订阅不跑流量
            $pullCountLast24h = 0;
            $now = time();
            foreach ($history as $hItem) {
                if (($now - ($hItem['time'] ?? 0)) < 86400) {
                    $pullCountLast24h++;
                }
            }
            $totalTrafficMB = ($user->u + $user->d) / 1024 / 1024;
            if ($pullCountLast24h >= 4 && $totalTrafficMB < 100) {
                $reasons[] = "画像异常: 24h内拉取订阅 " . $pullCountLast24h . " 次，但本月总消耗流量仅为 " . round($totalTrafficMB, 2) . " MB (只拉订阅不跑流量)";
            }

            // 2. 画像分析 B：共用海外 IP 联合探测预警
            if (isset($sharedOverseasIps[$user->id])) {
                foreach ($sharedOverseasIps[$user->id] as $info) {
                    $reasons[] = "共用海外IP预警: 24h内与用户 [" . implode(', ', $info['others']) . "] 共用海外 IP " . $info['ip'] . " 拉取订阅 (疑似多账号联合探测)";
                }
            }

            if (!empty($reasons)) {
                $riskLevel = 'low';
                if (isset($sharedOverseasIps[$user->id])) {
                    $riskLevel = 'medium';
                }

                $data[] = [
                    'user_id' => (int)$user->id,
                    'email' => $user->email,
                    'flagged_at' => $user->t ?: time(),
                    'reasons' => $reasons,
                    'in_honeypot' => 0,
                    'banned' => (int)$user->banned,
                    'history' => $history,
                    'type' => 'suspected',
                    'risk_level' => $riskLevel
                ];
            }
        }

        usort($data, function ($a, $b) {
            return $b['flagged_at'] <=> $a['flagged_at'];
        });

        return [
            'data' => [
                'list' => $data,
                'whitelist' => array_values($whitelistUsers),
                'banned_ips' => array_values($config['banned_ips'] ?? []),
                'ignore_ips' => array_values($config['ignore_ips'] ?? []),
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

    public function getIpAssociationAnalysis()
    {
        $configPath = storage_path('tianque_config.json');
        $config = [];
        if (file_exists($configPath)) {
            $config = json_decode(@file_get_contents($configPath), true) ?: [];
        }
        $bannedIps = $config['banned_ips'] ?? [];
        $honeypotUsers = array_map('intval', $config['honeypot_users'] ?? []);
        $ignoreIps = $config['ignore_ips'] ?? [];

        // 获取所有有拉取记录的用户
        $users = User::whereNotNull('client_type')->get(['id', 'email', 'client_type']);

        $ipMap = [];
        foreach ($users as $user) {
            $history = json_decode($user->client_type, true);
            if (!is_array($history)) {
                continue;
            }
            $history = $this->filterClientHistory($history, $ignoreIps);

            $userInHoneypot = in_array((int)$user->id, $honeypotUsers, true);

            foreach ($history as $log) {
                $ip = trim($log['ip'] ?? '');
                if (empty($ip) || $ip === '127.0.0.1') {
                    continue;
                }

                if (!isset($ipMap[$ip])) {
                    $ipMap[$ip] = [
                        'ip' => $ip,
                        'users' => [],
                        'total_pulls' => 0,
                        'latest_time' => 0,
                    ];
                }

                $ipMap[$ip]['total_pulls']++;
                if (($log['time'] ?? 0) > $ipMap[$ip]['latest_time']) {
                    $ipMap[$ip]['latest_time'] = (int)($log['time'] ?? 0);
                }

                $ipMap[$ip]['users'][$user->email] = [
                    'id' => (int)$user->id,
                    'in_honeypot' => $userInHoneypot
                ];
            }
        }

        // 过滤出关联了 2 个及以上不同账号的 IP
        $result = [];
        foreach ($ipMap as $ip => $data) {
            $userCount = count($data['users']);
            if ($userCount < 2) {
                continue;
            }

            // 转换 users 格式供前端使用
            $associatedUsers = [];
            $honeypotCount = 0;
            foreach ($data['users'] as $email => $uInfo) {
                $associatedUsers[] = [
                    'id' => $uInfo['id'],
                    'email' => $email,
                    'in_honeypot' => $uInfo['in_honeypot'] ? 1 : 0
                ];
                if ($uInfo['in_honeypot']) {
                    $honeypotCount++;
                }
            }

            $result[] = [
                'ip' => $ip,
                'associated_accounts_count' => $userCount,
                'honeypot_accounts_count' => $honeypotCount,
                'total_pulls' => $data['total_pulls'],
                'latest_time' => $data['latest_time'],
                'associated_users' => $associatedUsers,
                'is_banned' => in_array($ip, $bannedIps, true) ? 1 : 0,
                'location' => $this->getIpInfo($ip)['location']
            ];
        }

        // 按关联账号数从多到少排序，如果一样多，按最近拉取时间降序
        usort($result, function ($a, $b) {
            if ($b['associated_accounts_count'] === $a['associated_accounts_count']) {
                return $b['latest_time'] <=> $a['latest_time'];
            }
            return $b['associated_accounts_count'] <=> $a['associated_accounts_count'];
        });

        return response([
            'data' => $result
        ]);
    }

    public function addIgnoreIp(Request $request)
    {
        $ip = trim($request->input('ip'));
        if (empty($ip)) {
            abort(500, 'IP不能为空');
        }

        $configPath = storage_path('tianque_config.json');
        if (!file_exists($configPath)) {
            @file_put_contents($configPath, json_encode([]));
        }

        $config = json_decode(@file_get_contents($configPath), true) ?: [];
        if (!isset($config['ignore_ips']) || !is_array($config['ignore_ips'])) {
            $config['ignore_ips'] = [];
        }

        if (!in_array($ip, $config['ignore_ips'], true)) {
            $config['ignore_ips'][] = $ip;
        }

        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return response([
            'data' => true
        ]);
    }

    public function removeIgnoreIp(Request $request)
    {
        $ip = trim($request->input('ip'));
        $configPath = storage_path('tianque_config.json');
        if (!file_exists($configPath)) {
            abort(500, '配置文件不存在');
        }

        $config = json_decode(@file_get_contents($configPath), true) ?: [];
        if (isset($config['ignore_ips']) && is_array($config['ignore_ips'])) {
            $key = array_search($ip, $config['ignore_ips']);
            if ($key !== false) {
                unset($config['ignore_ips'][$key]);
                $config['ignore_ips'] = array_values($config['ignore_ips']);
                @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        }

        return response([
            'data' => true
        ]);
    }

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

    private function filterClientHistory($history, $ignoreIps)
    {
        if (empty($history) || empty($ignoreIps)) {
            return $history;
        }

        return array_values(array_filter($history, function ($hItem) use ($ignoreIps) {
            $ip = trim($hItem['ip'] ?? '');
            if (empty($ip)) return false;
            foreach ($ignoreIps as $ignoreRule) {
                if ($this->ipInRange($ip, $ignoreRule)) {
                    return false;
                }
            }
            return true;
        }));
    }

    private function isOverseasIp($ip)
    {
        // 如果是 IPv6
        if (strpos($ip, ':') !== false) {
            // 国内三大家宽 IPv6 常见前缀：电信 240e, 移动 2409, 联通 2408, 教育网 2001:da8, 240a/240b
            $ipLower = strtolower($ip);
            foreach (['240e', '2409', '2408', '240a', '240b', '2001:da8'] as $prefix) {
                if (strpos($ipLower, $prefix) === 0) {
                    return false; // 国内 IP
                }
            }
            return true; // 海外 IP
        }

        // 如果是局域网 IPv4
        if (substr($ip, 0, 4) === '127.' || substr($ip, 0, 3) === '10.' || substr($ip, 0, 8) === '192.168.' || substr($ip, 0, 7) === '172.16.') {
            return false;
        }

        $info = $this->getIpInfo($ip);
        return $info['countryCode'] !== 'CN';
    }

    private function getIpInfo($ip)
    {
        if (empty($ip) || $ip === '127.0.0.1') {
            return ['countryCode' => 'CN', 'location' => '局域网/本地'];
        }

        return \Illuminate\Support\Facades\Cache::remember('ip_info_detail_' . $ip, 86400 * 30, function() use ($ip) {
            try {
                $ctx = stream_context_create(['http' => ['timeout' => 2]]);
                $res = @file_get_contents("http://ip-api.com/json/{$ip}?lang=zh-CN", false, $ctx);
                if ($res) {
                    $data = json_decode($res, true);
                    if (isset($data['status']) && $data['status'] === 'success') {
                        $country = $data['country'] ?? '';
                        $countryCode = $data['countryCode'] ?? 'CN';
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
                            $ispCn = $data['isp'] ?? '';
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

                        return [
                            'countryCode' => $countryCode,
                            'location' => $location
                        ];
                    }
                }
            } catch (\Exception $e) {}
            return ['countryCode' => 'CN', 'location' => '未知归属地'];
        });
    }
}

