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

        // 标记高风险的检测画像
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

            // 判断风险等级
            $riskLevel = 'high';
            foreach ($reasons as $reason) {
                if (strpos($reason, '共用海外IP') !== false) {
                    $riskLevel = 'medium';
                }
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
                'risk_level' => $riskLevel
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
        $abnormalKeywords = [
            'curl', 'wget', 'python', 'python-requests', 'go-http', 'go-http-client', 'urllib', 'httpclient', 'postman', 'aria2',
            'ClashMetaForAndroid/733', 'clash-verge/v2.3.1', 'clash'
        ];
        if (isset($config['audit_ua_keywords']) && is_array($config['audit_ua_keywords'])) {
            $abnormalKeywords = $config['audit_ua_keywords'];
        }
        $abnormalKeywordsLower = array_map('strtolower', $abnormalKeywords);

        // Suspected Users
        $lastId = 0;
        $loopCount = 0;
        while (count($data) < 30 && $loopCount < 5) {
            $loopCount++;
            $query = User::where('banned', 0)
                ->whereNotNull('client_type')
                ->whereNotIn('id', $flaggedIds);
            if (!empty($honeypotUsers)) {
                $query->whereNotIn('id', $honeypotUsers);
            }
            if ($lastId > 0) {
                $query->where('id', '>', $lastId);
            }
            $query->where(function($q) use ($abnormalKeywordsLower) {
                foreach ($abnormalKeywordsLower as $kw) {
                    $q->orWhere('client_type', 'like', '%' . $kw . '%');
                }
            });

            $chunk = $query->orderBy('id', 'ASC')->limit(50)->get(['id', 'email', 'client_type', 't', 'banned']);
            if ($chunk->isEmpty()) {
                break;
            }

            foreach ($chunk as $user) {
                $lastId = $user->id;

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
                
                // --- 1. 进行分布式异地扩散探测检测 (24h 内 5 个及以上不同国内省份拉取，不限 UA 和 IP 属性) ---
                $isSpecSpy = false;
                $specSpyRegions = [];
                
                $now = time();
                $logs24h = array_filter($history, function($h) use ($now) {
                    return ($now - ($h['time'] ?? 0)) <= 86400;
                });

                $ips = [];
                foreach ($logs24h as $log) {
                    $ip = trim($log['ip'] ?? '');
                    if (!empty($ip) && $ip !== '127.0.0.1') {
                        $ips[] = $ip;
                    }
                }
                $uniqueIps = array_values(array_unique($ips));

                if (count($uniqueIps) >= 5) {
                    $regions = [];
                    foreach ($uniqueIps as $ip) {
                        $ipInfo = $this->getIpInfo($ip);
                        $loc = $ipInfo['location'] ?? '';
                        $countryCode = $ipInfo['countryCode'] ?? 'CN';
                        
                        // 判定是否为国内省份拉取
                        if ($countryCode === 'CN' || strpos($loc, '中国') !== false) {
                            $locClean = preg_replace('/[^\x{4e00}-\x{9fa5}a-zA-Z]/u', '', $loc);
                            $regionName = '';
                            foreach (['北京', '上海', '天津', '重庆', '河北', '山西', '辽宁', '吉林', '黑龙江', '江苏', '浙江', '安徽', '福建', '江西', '山东', '河南', '湖北', '湖南', '广东', '海南', '四川', '贵州', '云南', '陕西', '甘肃', '青海', '台湾', '内蒙古', '广西', '西藏', '宁夏', '新疆', '香港', '澳门'] as $prov) {
                                if (strpos($locClean, $prov) !== false) {
                                    $regionName = $prov;
                                    break;
                                }
                            }
                            if ($regionName) {
                                $regions[] = $regionName;
                            }
                        }
                    }
                    $uniqueRegions = array_values(array_unique($regions));
                    if (count($uniqueRegions) >= 5) {
                        $isSpecSpy = true;
                        $specSpyRegions = $uniqueRegions;
                    }
                }

                // --- 2. 进行国内机房 IP 探测检测 ---
                $isIdcSpy = false;
                $idcSpyReason = '';
                $idcKeywords = [
                    '阿里云', '腾讯云', '华为云', '百度云', '京东云', '网易云', '金山云', '天翼云', '联通云', '移动云',
                    'aliyun', 'alibaba', 'tencent', 'huawei', 'baidu', 'ucloud', 'qcloud', 'ksyun', '美团云', '青云',
                    'chinacicc', 'capitalonline', '数据中心', '机房', '世纪互联', '光环新网', '网宿', '蓝汛'
                ];
                
                foreach ($uniqueIps as $ip) {
                    $ipInfo = $this->getIpInfo($ip);
                    $loc = $ipInfo['location'] ?? '';
                    $countryCode = $ipInfo['countryCode'] ?? 'CN';
                    
                    $isChina = ($countryCode === 'CN' || strpos($loc, '中国') !== false);
                    $isHongKongOrMacauOrTaiwan = (strpos($loc, '香港') !== false || strpos($loc, '澳门') !== false || strpos($loc, '台湾') !== false);
                    
                    if ($isChina && !$isHongKongOrMacauOrTaiwan) {
                        $locLower = strtolower($loc);
                        foreach ($idcKeywords as $kw) {
                            if (strpos($locLower, $kw) !== false) {
                                $isIdcSpy = true;
                                $idcSpyReason = "国内机房服务器拉取订阅 (IP: {$ip}, 归属: {$loc})";
                                break 2;
                            }
                        }
                    }
                }

                // 3. 普通敏感 UA 匹配
                $matchedKeywords = [];
                foreach ($history as $hItem) {
                    $ua = $hItem['ua'] ?? '';
                    $uaLower = strtolower($ua);
                    $cleanUa = preg_replace('/[^a-z0-9\/_\-\.]/', ' ', $uaLower);
                    $segments = array_filter(explode(' ', $cleanUa));

                    foreach ($abnormalKeywordsLower as $kw) {
                        if (preg_match('/[^a-z0-9\/_\-\.]/', $kw)) {
                            // 1. 如果关键字中含有分词字符（如空格、括号等），走长特征模糊匹配
                            if (strpos($uaLower, $kw) !== false) {
                                $matchedKeywords[] = "拉取记录发现敏感 UA: " . ($hItem['ua'] ?? $kw);
                                break;
                            }
                        } else {
                            // 2. 如果是纯单词，走分词段精准匹配，防误伤
                            foreach ($segments as $seg) {
                                if ($seg === $kw || strpos($seg, $kw . '/') === 0) {
                                    $matchedKeywords[] = "拉取记录发现敏感 UA: " . ($hItem['ua'] ?? $kw);
                                    break 2;
                                }
                            }
                        }
                    }
                }
                $matchedKeywords = array_values(array_unique($matchedKeywords));

                // 决定是否采纳记录
                if ($isSpecSpy || $isIdcSpy) {
                    $reasons = [];
                    if ($isSpecSpy) {
                        $reasons[] = "分布式异地探测画像: 24h内使用多省份家宽IP高频拉取 (覆盖省份: " . implode(', ', $specSpyRegions) . ")";
                    }
                    if ($isIdcSpy) {
                        $reasons[] = $idcSpyReason;
                    }
                    $riskLevel = 'high';
                } elseif (!empty($matchedKeywords)) {
                    $reasons = $matchedKeywords;
                    $riskLevel = 'medium';
                } else {
                    continue;
                }

                // 延迟查询 IP 归属地，仅对最终进入展示列表的用户进行查询，极大提升接口响应性能
                foreach ($history as &$hItem) {
                    $hItem['location'] = $this->getIpInfo($hItem['ip'] ?? '')['location'];
                }
                unset($hItem);

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

                if (count($data) >= 30) {
                    break 2;
                }
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
                    'audit_ua_enabled' => isset($config['audit_ua_enabled']) ? (bool)$config['audit_ua_enabled'] : true,
                    'audit_ua_keywords' => $abnormalKeywords,
                    'banned_strategy' => $config['banned_strategy'] ?? 'bait',
                    'banned_redirect_url' => $config['banned_redirect_url'] ?? '',
                    'subconverter_enable' => isset($config['subconverter_enable']) ? (bool)$config['subconverter_enable'] : true,
                    'subconverter_url' => $config['subconverter_url'] ?? 'https://api.wcc.best/sub',
                    'banned_keywords' => $config['banned_keywords'] ?? '',
                    'replace_keyword_to' => $config['replace_keyword_to'] ?? '精品线路',
                    'banned_traffic_enable' => isset($config['banned_traffic_enable']) ? (bool)$config['banned_traffic_enable'] : false,
                    'banned_traffic_min' => isset($config['banned_traffic_min']) ? (int)$config['banned_traffic_min'] : 100,
                    'banned_traffic_max' => isset($config['banned_traffic_max']) ? (int)$config['banned_traffic_max'] : 300,
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

    public function clearAllAnomalies(Request $request)
    {
        $configPath = storage_path('tianque_config.json');
        if (!file_exists($configPath)) {
            abort(500, '配置文件不存在');
        }

        $config = json_decode(@file_get_contents($configPath), true) ?: [];
        $config['flagged_users'] = [];
        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

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
        $configPath = storage_path('tianque_config.json');
        $config = [];
        if (file_exists($configPath)) {
            $config = json_decode(@file_get_contents($configPath), true) ?: [];
        }

        $config['ip_limit'] = (int)$request->input('ip_limit', 10);
        $config['audit_ua_enabled'] = (bool)$request->input('audit_ua_enabled', true);
        if ($request->has('audit_ua_keywords')) {
            $keywords = $request->input('audit_ua_keywords');
            if (is_array($keywords)) {
                $config['audit_ua_keywords'] = array_values(array_filter(array_map('trim', $keywords)));
            }
        }
        $config['banned_strategy'] = $request->input('banned_strategy', 'bait');
        $config['banned_redirect_url'] = $request->input('banned_redirect_url', '');
        $config['subconverter_enable'] = (bool)$request->input('subconverter_enable', true);
        $config['subconverter_url'] = $request->input('subconverter_url', 'https://api.wcc.best/sub');
        $config['banned_keywords'] = $request->input('banned_keywords', '');
        $config['replace_keyword_to'] = $request->input('replace_keyword_to', '精品线路');
        $config['banned_traffic_enable'] = (bool)$request->input('banned_traffic_enable', false);
        $config['banned_traffic_min'] = (int)$request->input('banned_traffic_min', 100);
        $config['banned_traffic_max'] = (int)$request->input('banned_traffic_max', 300);

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

    public function customAuditScan(\Illuminate\Http\Request $request)
    {
        $idMin = (int)$request->input('id_min', 10000);
        $uaKeyword = trim($request->input('ua_keyword', ''));
        $provinceCount = (int)$request->input('province_count', 0);
        $onlyIdc = (bool)$request->input('only_idc', false);
        $timeRange = (int)$request->input('time_range', 86400);
        $maxTrafficMb = (int)$request->input('max_traffic', 0);

        // 读出免审 IP 配置
        $configPath = storage_path('tianque_config.json');
        $ignoreIps = [];
        if (file_exists($configPath)) {
            $config = json_decode(@file_get_contents($configPath), true) ?: [];
            $ignoreIps = $config['ignore_ips'] ?? [];
            $honeypotUsers = array_map('intval', $config['honeypot_users'] ?? []);
        } else {
            $honeypotUsers = [];
        }

        // 初筛 (按 ID 降序排列，由于是在内存解包后做高精度的 UA 和省份时间判定，我们直接对最新的 1000 个账号进行审计)
        $query = User::where('id', '>', $idMin)->whereNotNull('client_type');
        
        if (!empty($uaKeyword)) {
            // 支持用 / 或者是空格分割多个关键字，并进行 And 模糊匹配
            $keywords = preg_split('/[\/\s]+/', $uaKeyword);
            foreach ($keywords as $kw) {
                $kw = trim($kw);
                if (!empty($kw)) {
                    $query->where('client_type', 'like', '%' . $kw . '%');
                }
            }
        }
        
        $users = $query->orderBy('id', 'desc')->limit(1000)->get(['id', 'email', 'client_type', 'banned', 'u', 'd']);

        $now = time();
        $hours = round($timeRange / 3600);
        $matchedUsers = [];

        $idcKeywords = [
            '阿里云', '腾讯云', '华为云', '百度云', '京东云', '网易云', '金山云', '天翼云', '联通云', '移动云',
            'aliyun', 'alibaba', 'tencent', 'huawei', 'baidu', 'ucloud', 'qcloud', 'ksyun', '美团云', '青云',
            'chinacicc', 'capitalonline', '数据中心', '机房', '世纪互联', '光环新网', '网宿', '蓝汛',
            'aws', 'amazon', '亚马逊', 'gcp', 'google', '谷歌云', 'azure', 'microsoft', '微软云',
            'oracle', '甲骨文', 'digitalocean', 'linode', 'vultr', 'bandwagon', '搬瓦工', 'ovh',
            'choopa', 'zenlayer', 'cloudflare', 'hostwind'
        ];

        foreach ($users as $user) {
            $history = json_decode($user->client_type, true) ?: [];
            $history = $this->filterClientHistory($history, $ignoreIps);

            // 获取历史所有的不重复 UA
            $allHistoryUas = array_values(array_unique(array_map(function($h) { return $h['ua'] ?? ($h['type'] ?? ''); }, $history)));

            // 1. 最近 24h 时间范围过滤
            $logs = array_filter($history, function($h) use ($now, $timeRange) {
                return ($now - ($h['time'] ?? 0)) <= $timeRange;
            });

            if (empty($logs)) {
                $matchedUsers[] = [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'banned' => (int)$user->banned,
                    'in_honeypot' => in_array((int)$user->id, $honeypotUsers, true) ? 1 : 0,
                    'ip_count' => 0,
                    'province_count' => 0,
                    'provinces' => [],
                    'idc_count' => 0,
                    'uas' => $allHistoryUas,
                    'match_status' => 'excluded',
                    'exclude_reason' => "排除原因: 最近 {$hours} 小时内没有任何拉取记录。"
                ];
                continue;
            }

            // 获取活跃 UA 列表
            $activeUas = array_values(array_unique(array_map(function($l) { return $l['ua'] ?? ($l['type'] ?? ''); }, $logs)));

            // 2. 如果设置了 UA，验证这 24h 内是否有该 UA 拉取
            if (!empty($uaKeyword)) {
                $hasTargetUa = false;
                $keywords = preg_split('/[\/\s]+/', $uaKeyword);
                foreach ($logs as $log) {
                    $uaLower = strtolower($log['ua'] ?? ($log['type'] ?? ''));
                    $allKwMatch = true;
                    foreach ($keywords as $kw) {
                        $kw = trim($kw);
                        if (!empty($kw) && strpos($uaLower, strtolower($kw)) === false) {
                            $allKwMatch = false;
                            break;
                        }
                    }
                    if ($allKwMatch) {
                        $hasTargetUa = true;
                        break;
                    }
                }
                if (!$hasTargetUa) {
                    $matchedUsers[] = [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'banned' => (int)$user->banned,
                        'in_honeypot' => in_array((int)$user->id, $honeypotUsers, true) ? 1 : 0,
                        'ip_count' => count(array_unique(array_map(function($l) { return $l['ip'] ?? ''; }, $logs))),
                        'province_count' => 0,
                        'provinces' => [],
                        'idc_count' => 0,
                        'uas' => $allHistoryUas,
                        'match_status' => 'excluded',
                        'exclude_reason' => "排除原因: 最近 {$hours} 小时活跃 UA 不包含 '{$uaKeyword}' (该 UA 仅存在于较早历史中)。"
                    ];
                    continue;
                }
            }

            // 2.5 已用流量上限校验 (如果设置了 max_traffic)
            if ($maxTrafficMb > 0) {
                $usedTraffic = (float)($user->u + $user->d);
                $maxTrafficLimit = (float)$maxTrafficMb * 1024 * 1024;
                if ($usedTraffic > $maxTrafficLimit) {
                    $matchedUsers[] = [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'banned' => (int)$user->banned,
                        'in_honeypot' => in_array((int)$user->id, $honeypotUsers, true) ? 1 : 0,
                        'ip_count' => count(array_unique(array_map(function($l) { return $l['ip'] ?? ''; }, $logs))),
                        'province_count' => 0,
                        'provinces' => [],
                        'idc_count' => 0,
                        'uas' => $activeUas,
                        'match_status' => 'excluded',
                        'exclude_reason' => '排除原因: 已使用总流量为 ' . round($usedTraffic / (1024 * 1024), 2) . ' MB，超过了限制的 ' . $maxTrafficMb . ' MB。'
                    ];
                    continue;
                }
            }

            // 提取 24h 独立 IP
            $ips = [];
            foreach ($logs as $log) {
                $ip = trim($log['ip'] ?? '');
                if (!empty($ip) && $ip !== '127.0.0.1') {
                    $ips[] = $ip;
                }
            }
            $uniqueIps = array_values(array_unique($ips));

            // 3. 独立 IP 数量过滤
            if ($provinceCount > 0 && count($uniqueIps) < $provinceCount) {
                $matchedUsers[] = [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'banned' => (int)$user->banned,
                    'in_honeypot' => in_array((int)$user->id, $honeypotUsers, true) ? 1 : 0,
                    'ip_count' => count($uniqueIps),
                    'province_count' => 0,
                    'provinces' => [],
                    'idc_count' => 0,
                    'uas' => $activeUas,
                    'match_status' => 'excluded',
                    'exclude_reason' => "排除原因: 24h独立 IP 数为 " . count($uniqueIps) . " 个，少于设定的 {$provinceCount} 个，不满足跨省条件。"
                ];
                continue;
            }

            // 对这几个 IP 进行定位
            $regions = [];
            $idcMatchCount = 0;
            $ipDetails = [];

            foreach ($uniqueIps as $ip) {
                $ipInfo = $this->getIpInfo($ip);
                $loc = $ipInfo['location'] ?? '';
                $countryCode = $ipInfo['countryCode'] ?? 'CN';

                // 提取省份
                $isChina = ($countryCode === 'CN' || strpos($loc, '中国') !== false);
                $isHongKongOrMacauOrTaiwan = (strpos($loc, '香港') !== false || strpos($loc, '澳门') !== false || strpos($loc, '台湾') !== false);
                
                $regionName = '';
                if ($isChina) {
                    $locClean = preg_replace('/[^\x{4e00}-\x{9fa5}a-zA-Z]/u', '', $loc);
                    foreach (['北京', '上海', '天津', '重庆', '河北', '山西', '辽宁', '吉林', '黑龙江', '江苏', '浙江', '安徽', '福建', '江西', '山东', '河南', '湖北', '湖南', '广东', '海南', '四川', '贵州', '云南', '陕西', '甘肃', '青海', '台湾', '内蒙古', '广西', '西藏', '宁夏', '新疆', '香港', '澳门'] as $prov) {
                        if (strpos($locClean, $prov) !== false) {
                            $regionName = $prov;
                            break;
                        }
                    }
                }

                if ($regionName) {
                    $regions[] = $regionName;
                } elseif ($isChina) {
                    $regions[] = $loc;
                }

                // 机房检测 (全球适配，不限国内，只要匹配到云厂商关键字即判定为机房 IP)
                $isIdc = false;
                $locLower = strtolower($loc);
                foreach ($idcKeywords as $kw) {
                    if (strpos($locLower, $kw) !== false) {
                        $isIdc = true;
                        $idcMatchCount++;
                        break;
                    }
                }

                $ipDetails[] = [
                    'ip' => $ip,
                    'location' => $loc,
                    'is_idc' => $isIdc
                ];
            }

            $uniqueRegions = array_values(array_unique($regions));

            // 4. 省份数量条件限制
            if ($provinceCount > 0 && count($uniqueRegions) < $provinceCount) {
                $matchedUsers[] = [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'banned' => (int)$user->banned,
                    'in_honeypot' => in_array((int)$user->id, $honeypotUsers, true) ? 1 : 0,
                    'ip_count' => count($uniqueIps),
                    'province_count' => count($uniqueRegions),
                    'provinces' => $uniqueRegions,
                    'idc_count' => $idcMatchCount,
                    'uas' => $activeUas,
                    'match_status' => 'excluded',
                    'exclude_reason' => '排除原因: 虽独立 IP 数足够，但去重后省份数量仅为 ' . count($uniqueRegions) . ' 个，未达到设定的 ' . $provinceCount . ' 个。'
                ];
                continue;
            }

            // 5. 是否仅限 IDC 限制
            if ($onlyIdc && $idcMatchCount === 0) {
                $matchedUsers[] = [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'banned' => (int)$user->banned,
                    'in_honeypot' => in_array((int)$user->id, $honeypotUsers, true) ? 1 : 0,
                    'ip_count' => count($uniqueIps),
                    'province_count' => count($uniqueRegions),
                    'provinces' => $uniqueRegions,
                    'idc_count' => 0,
                    'uas' => $activeUas,
                    'match_status' => 'excluded',
                    'exclude_reason' => "排除原因: 最近 {$hours} 小时内没有检测到任何来自机房的拉取 IP。"
                ];
                continue;
            }

            // 6. 完全符合
            $matchedUsers[] = [
                'user_id' => $user->id,
                'email' => $user->email,
                'banned' => (int)$user->banned,
                'in_honeypot' => in_array((int)$user->id, $honeypotUsers, true) ? 1 : 0,
                'ip_count' => count($uniqueIps),
                'province_count' => count($uniqueRegions),
                'provinces' => $uniqueRegions,
                'idc_count' => $idcMatchCount,
                'uas' => $activeUas,
                'match_status' => 'matched',
                'exclude_reason' => '',
                'history' => $ipDetails
            ];
        }

        return response([
            'data' => $matchedUsers,
            'debug' => [
                'id_min' => $idMin,
                'ua_keyword' => $uaKeyword,
                'province_count' => $provinceCount,
                'only_idc' => $onlyIdc,
                'time_range' => $timeRange,
                'max_traffic' => $maxTrafficMb,
                'users_count' => count($users)
            ]
        ]);
    }

    public function customAuditHoneypot(\Illuminate\Http\Request $request)
    {
        $userIds = $request->input('user_ids', []);
        if (!is_array($userIds) || empty($userIds)) {
            return response([
                'status' => 'fail',
                'message' => '参数错误'
            ]);
        }

        $configPath = storage_path('tianque_config.json');
        $config = [];
        if (file_exists($configPath)) {
            $config = json_decode(@file_get_contents($configPath), true) ?: [];
        }
        if (!isset($config['honeypot_users']) || !is_array($config['honeypot_users'])) {
            $config['honeypot_users'] = [];
        }

        $existing = array_map('intval', $config['honeypot_users']);
        $addedCount = 0;
        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            if (!in_array($uid, $existing, true)) {
                $existing[] = $uid;
                $config['honeypot_users'] = $existing;
                if (!isset($config['honeypot_times'])) {
                    $config['honeypot_times'] = [];
                }
                $config['honeypot_times'][(string)$uid] = time();
                $addedCount++;
            }
        }

        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        @chmod($configPath, 0755);

        return response([
            'status' => 'success',
            'message' => "成功将 {$addedCount} 个账号加入蜜罐接管。"
        ]);
    }
}

