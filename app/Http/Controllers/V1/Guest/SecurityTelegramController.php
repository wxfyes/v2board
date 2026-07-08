<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class SecurityTelegramController extends Controller
{
    public function webhook(Request $request)
    {
        $data = $request->input();

        // 写入审计日志以供调试
        Log::info('TianQue Security Webhook Payload:', [
            'ip' => $request->ip(),
            'data' => $data
        ]);

        // 读取 .env 中的安全配置
        $adminChatId = env('SECURITY_TG_CHAT');
        $botToken = env('SECURITY_TG_TOKEN');

        // 兼容配置缓存
        if (empty($adminChatId) || empty($botToken)) {
            $envPath = base_path('.env');
            if (file_exists($envPath)) {
                $envContent = @file_get_contents($envPath);
                if (!empty($envContent)) {
                    if (empty($adminChatId) && preg_match('/^SECURITY_TG_CHAT\s*=\s*(.*)$/m', $envContent, $matches)) {
                        $adminChatId = trim($matches[1], "\"' ");
                    }
                    if (empty($botToken) && preg_match('/^SECURITY_TG_TOKEN\s*=\s*(.*)$/m', $envContent, $matches)) {
                        $botToken = trim($matches[1], "\"' ");
                    }
                }
            }
        }

        // 处理普通文本命令 (如: /check 或 /scan)
        if (isset($data['message'])) {
            $message = $data['message'];
            $chatId = $message['chat']['id'];
            $fromId = $message['from']['id'];
            $text = trim($message['text'] ?? '');

            // 安全校验：只有配置好的管理员才可以使用指令
            if (!$this->isUserAuthorized($botToken, $adminChatId, $fromId, $chatId)) {
                Log::warning("TianQue Security Webhook: Unauthorized command access attempt.", [
                    'from_id' => $fromId,
                    'chat_id' => $chatId,
                    'admin_chat_id' => $adminChatId
                ]);
                return response()->json(['status' => 'unauthorized']);
            }

            if ($text === '/check' || $text === '/scan' || $text === '/start') {
                $this->sendMessage($botToken, $chatId, "🔍 正在为您启动【天阙订阅安全审计扫描】，请稍候...");
                
                try {
                    // 调用 Artisan 命令行执行扫描
                    Artisan::call('v2board:detect-subscribers');
                    $this->sendMessage($botToken, $chatId, "✅ 审计扫描执行已结束！如有新捕获的异常用户，会在上方收到预警卡片。");
                } catch (\Exception $e) {
                    $this->sendMessage($botToken, $chatId, "❌ 扫描执行失败: " . $e->getMessage());
                }
            } elseif ($text === '/status' || $text === '/stat' || $text === '/dashboard') {
                try {
                    // 1. 在线人数 (10分钟内有交互的用户)
                    $onlineUser = \App\Models\User::where('t', '>=', time() - 600)->count();

                    // 2. 收入数据与收款笔数
                    $dayIncome = \App\Models\Order::where('created_at', '>=', strtotime(date('Y-m-d')))
                        ->where('created_at', '<', time())
                        ->whereNotIn('status', [0, 2])
                        ->sum('total_amount') / 100;

                    $dayOrderCount = \App\Models\Order::where('created_at', '>=', strtotime(date('Y-m-d')))
                        ->where('created_at', '<', time())
                        ->whereNotIn('status', [0, 2])
                        ->count();

                    $monthIncome = \App\Models\Order::where('created_at', '>=', strtotime(date('Y-m-1')))
                        ->where('created_at', '<', time())
                        ->whereNotIn('status', [0, 2])
                        ->sum('total_amount') / 100;

                    $lastMonthIncome = \App\Models\Order::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                        ->where('created_at', '<', strtotime(date('Y-m-1')))
                        ->whereNotIn('status', [0, 2])
                        ->sum('total_amount') / 100;

                    // 3. 注册人数
                    $dayRegister = \App\Models\User::where('created_at', '>=', strtotime(date('Y-m-d')))
                        ->where('created_at', '<', time())
                        ->count();

                    $monthRegister = \App\Models\User::where('created_at', '>=', strtotime(date('Y-m-1')))
                        ->where('created_at', '<', time())
                        ->count();

                    // 4. 有效订阅
                    $activeSubs = \App\Models\User::where('plan_id', '!=', NULL)
                        ->where(function ($query) {
                            $query->where('expired_at', '>', time())
                                ->orWhere('expired_at', NULL);
                        })
                        ->count();

                    // 5. 今日流量 (u + d)
                    $dayTrafficBytes = \App\Models\StatServer::where('record_at', '>=', strtotime(date('Y-m-d')))
                        ->where('record_type', 'd')
                        ->sum(\DB::raw('u + d'));
                    $dayTrafficGB = $dayTrafficBytes / (1024 * 1024 * 1024);

                    // 6. 佣金支出
                    $lastMonthCommission = \App\Models\CommissionLog::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                        ->where('created_at', '<', strtotime(date('Y-m-1')))
                        ->sum('get_amount') / 100;

                    // 格式化 Telegram 信息
                    $msg = "📊 *天阙管理员面板实时统计数据*\n";
                    $msg .= "━━━━━━━━━━━━━━━━━━\n";
                    $msg .= "👥 *在线人数：* `{$onlineUser}` 人 (10m)\n";
                    $msg .= "👥 *有效订阅：* `{$activeSubs}` 人\n";
                    $msg .= "━━━━━━━━━━━━━━━━━━\n";
                    $msg .= "💰 *今日收入：* `" . number_format($dayIncome, 2) . "` CNY (`{$dayOrderCount}` 笔)\n";
                    $msg .= "💰 *本月收入：* `" . number_format($monthIncome, 2) . "` CNY\n";
                    $msg .= "💰 *上月收入：* `" . number_format($lastMonthIncome, 2) . "` CNY\n";
                    $msg .= "━━━━━━━━━━━━━━━━━━\n";
                    $msg .= "👤 *今日注册：* `{$dayRegister}` 人\n";
                    $msg .= "👤 *本月新增：* `{$monthRegister}` 人\n";
                    $msg .= "━━━━━━━━━━━━━━━━━━\n";
                    $msg .= "📡 *今日流量：* `" . number_format($dayTrafficGB, 2) . "` GB\n";
                    $msg .= "💸 *上月佣金支出：* `" . number_format($lastMonthCommission, 2) . "` CNY\n";
                    $msg .= "━━━━━━━━━━━━━━━━━━\n";
                    $msg .= "🕒 统计时间: " . date('Y-m-d H:i:s');

                    $this->sendMessage($botToken, $chatId, $msg);
                } catch (\Exception $e) {
                    $this->sendMessage($botToken, $chatId, "❌ 获取统计数据失败: " . $e->getMessage());
                }
            } elseif ($text === '/rank' || $text === '/traffic') {
                try {
                    $startAt = strtotime(date('Y-m-d'));
                    $endAt = time();

                    // 1. 今日节点流量排行
                    $servers = [
                        'shadowsocks' => \App\Models\ServerShadowsocks::where('parent_id', null)->get()->toArray(),
                        'v2ray' => \App\Models\ServerVmess::where('parent_id', null)->get()->toArray(),
                        'trojan' => \App\Models\ServerTrojan::where('parent_id', null)->get()->toArray(),
                        'vmess' => \App\Models\ServerVmess::where('parent_id', null)->get()->toArray(),
                        'vless' => \App\Models\ServerVless::where('parent_id', null)->get()->toArray(),
                        'tuic' => \App\Models\ServerTuic::where('parent_id', null)->get()->toArray(),
                        'hysteria'=> \App\Models\ServerHysteria::where('parent_id', null)->get()->toArray(),
                        'anytls' => \App\Models\ServerAnytls::where('parent_id', null)->get()->toArray(),
                        'v2node' => \App\Models\ServerV2node::where('parent_id', null)->get()->toArray(),
                        'mieru' => \App\Models\ServerMieru::where('parent_id', null)->get()->toArray()
                    ];

                    $nodeStats = \App\Models\StatServer::select([
                        'server_id',
                        'server_type',
                        'u',
                        'd',
                        \DB::raw('(u+d) as total')
                    ])
                        ->where('record_at', '>=', $startAt)
                        ->where('record_at', '<', $endAt)
                        ->where('record_type', 'd')
                        ->limit(30)
                        ->get()
                        ->toArray();

                    $nodeRanks = [];
                    foreach ($nodeStats as $v) {
                        $name = '未知节点';
                        if (isset($servers[$v['server_type']])) {
                            foreach ($servers[$v['server_type']] as $server) {
                                if ($server['id'] === $v['server_id']) {
                                    $name = $server['name'];
                                    break;
                                }
                            }
                        }
                        $gb = $v['total'] / (1024 * 1024 * 1024);
                        if (isset($nodeRanks[$name])) {
                            $nodeRanks[$name] += $gb;
                        } else {
                            $nodeRanks[$name] = $gb;
                        }
                    }
                    arsort($nodeRanks);
                    $nodeRanks = array_slice($nodeRanks, 0, 10, true);

                    // 2. 今日用户流量排行
                    $userStats = \App\Models\StatUser::select([
                        'user_id',
                        'server_rate',
                        'u',
                        'd',
                        \DB::raw('(u+d) as total')
                    ])
                        ->where('record_at', '>=', $startAt)
                        ->where('record_at', '<', $endAt)
                        ->where('record_type', 'd')
                        ->limit(30)
                        ->get()
                        ->toArray();

                    $userRanks = [];
                    foreach ($userStats as $v) {
                        $uid = $v['user_id'];
                        $gb = ($v['total'] * $v['server_rate']) / (1024 * 1024 * 1024);
                        if (isset($userRanks[$uid])) {
                            $userRanks[$uid] += $gb;
                        } else {
                            $userRanks[$uid] = $gb;
                        }
                    }
                    arsort($userRanks);
                    $userRanks = array_slice($userRanks, 0, 10, true);

                    // 获取前10的邮箱并 keyBy
                    $topUserIds = array_keys($userRanks);
                    $users = \App\Models\User::whereIn('id', $topUserIds)->get(['id', 'email'])->keyBy('id');

                    // 3. 组装消息
                    $msg = "📈 *今日流量使用排行 (Top 10)*\n";
                    $msg .= "━━━━━━━━━━━━━━━━━━\n\n";

                    $msg .= "🌐 *今日节点流量排行：*\n";
                    if (empty($nodeRanks)) {
                        $msg .= "暂无节点流量数据\n";
                    } else {
                        $idx = 1;
                        foreach ($nodeRanks as $name => $gb) {
                            $formattedGb = number_format($gb, 2);
                            $msg .= "{$idx}. `{$name}` ➔ `{$formattedGb}` GB\n";
                            $idx++;
                        }
                    }

                    $msg .= "\n👤 *今日用户流量排行：*\n";
                    if (empty($userRanks)) {
                        $msg .= "暂无用户流量数据\n";
                    } else {
                        $idx = 1;
                        foreach ($userRanks as $uid => $gb) {
                            $email = isset($users[$uid]) ? $users[$uid]->email : "ID: {$uid}";
                            if (strpos($email, '@') !== false) {
                                $parts = explode('@', $email);
                                $namePart = $parts[0];
                                if (strlen($namePart) > 3) {
                                    $email = substr($namePart, 0, 3) . '***@' . $parts[1];
                                }
                            }
                            $formattedGb = number_format($gb, 2);
                            $msg .= "{$idx}. `{$email}` ➔ `{$formattedGb}` GB\n";
                            $idx++;
                        }
                    }
                    $msg .= "\n━━━━━━━━━━━━━━━━━━\n";
                    $msg .= "🕒 统计时间: " . date('Y-m-d H:i:s');

                    $this->sendMessage($botToken, $chatId, $msg);
                } catch (\Exception $e) {
                    $this->sendMessage($botToken, $chatId, "❌ 获取排行数据失败: " . $e->getMessage());
                }
            } elseif (strpos($text, '/whitelist ') === 0) {
                $param = trim(substr($text, 11));
                if (empty($param)) {
                    $this->sendMessage($botToken, $chatId, "⚠️ 格式错误，请使用: `/whitelist <ID或邮箱>`");
                    return response()->json(['status' => 'ok']);
                }

                $configPath = storage_path('tianque_config.json');
                $config = [];
                if (file_exists($configPath)) {
                    $config = json_decode(@file_get_contents($configPath), true) ?: [];
                }
                if (!isset($config['whitelist_users']) || !is_array($config['whitelist_users'])) {
                    $config['whitelist_users'] = [];
                }

                $val = is_numeric($param) ? (int)$param : $param;
                if (!in_array($val, $config['whitelist_users'], true)) {
                    $config['whitelist_users'][] = $val;
                    
                    // 自动从蜜罐名单 (honeypot_users) 中移除该用户
                    if (isset($config['honeypot_users']) && is_array($config['honeypot_users'])) {
                        $key = array_search($val, $config['honeypot_users'], true);
                        if ($key !== false) {
                            unset($config['honeypot_users'][$key]);
                            $config['honeypot_users'] = array_values($config['honeypot_users']);
                        }
                    }
                    if (isset($config['honeypot_times']) && is_array($config['honeypot_times'])) {
                        if (isset($config['honeypot_times'][(string)$val])) {
                            unset($config['honeypot_times'][(string)$val]);
                        }
                    }
                    // 自动从重点观察名单 (flagged_users) 中移除该用户
                    if (isset($config['flagged_users']) && is_array($config['flagged_users'])) {
                        if (isset($config['flagged_users'][(string)$val])) {
                            unset($config['flagged_users'][(string)$val]);
                        }
                    }

                    @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    $this->sendMessage($botToken, $chatId, "✅ 已成功将 `{$param}` 加入白名单，后续扫描将完全跳过该用户。其历史蜜罐及观察状态已自动同步清除。");
                } else {
                    $this->sendMessage($botToken, $chatId, "ℹ️ 用户 `{$param}` 已经在白名单中。");
                }
            } elseif (strpos($text, '/unhoneypot ') === 0) {
                $param = trim(substr($text, 12));
                if (empty($param)) {
                    $this->sendMessage($botToken, $chatId, "⚠️ 格式错误，请使用: `/unhoneypot <ID或邮箱>`");
                    return response()->json(['status' => 'ok']);
                }

                $configPath = storage_path('tianque_config.json');
                $config = [];
                if (file_exists($configPath)) {
                    $config = json_decode(@file_get_contents($configPath), true) ?: [];
                }
                
                $removed = false;
                $val = is_numeric($param) ? (int)$param : $param;
                
                if (isset($config['honeypot_users']) && is_array($config['honeypot_users'])) {
                    $key = array_search($val, $config['honeypot_users'], true);
                    if ($key !== false) {
                        unset($config['honeypot_users'][$key]);
                        $config['honeypot_users'] = array_values($config['honeypot_users']);
                        $removed = true;
                    }
                }
                
                if (isset($config['honeypot_times']) && is_array($config['honeypot_times'])) {
                    if (isset($config['honeypot_times'][(string)$val])) {
                        unset($config['honeypot_times'][(string)$val]);
                        $removed = true;
                    }
                }

                if ($removed) {
                    @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    $this->sendMessage($botToken, $chatId, "✅ 已成功将 `{$param}` 从天阙蜜罐中移出，恢复为普通用户状态。");
                } else {
                    $this->sendMessage($botToken, $chatId, "ℹ️ 蜜罐名单中未找到用户 `{$param}`。");
                }
            } elseif (strpos($text, '/unwhitelist ') === 0) {
                $param = trim(substr($text, 13));
                if (empty($param)) {
                    $this->sendMessage($botToken, $chatId, "⚠️ 格式错误，请使用: `/unwhitelist <ID或邮箱>`");
                    return response()->json(['status' => 'ok']);
                }

                $configPath = storage_path('tianque_config.json');
                $config = [];
                if (file_exists($configPath)) {
                    $config = json_decode(@file_get_contents($configPath), true) ?: [];
                }
                if (isset($config['whitelist_users']) && is_array($config['whitelist_users'])) {
                    $val = is_numeric($param) ? (int)$param : $param;
                    $key = array_search($val, $config['whitelist_users'], true);
                    if ($key !== false) {
                        unset($config['whitelist_users'][$key]);
                        $config['whitelist_users'] = array_values($config['whitelist_users']);
                        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                        $this->sendMessage($botToken, $chatId, "✅ 已成功将 `{$param}` 从白名单中移除。");
                    } else {
                        $this->sendMessage($botToken, $chatId, "ℹ️ 白名单中未找到用户 `{$param}`。");
                    }
                } else {
                    $this->sendMessage($botToken, $chatId, "ℹ️ 白名单中未找到用户 `{$param}`。");
                }
            } elseif ($text === '/honeypots' || $text === '/honeypotlist') {
                $configPath = storage_path('tianque_config.json');
                $honeypotUsers = [];
                $tianqueConfig = [];
                if (file_exists($configPath)) {
                    $tianqueConfig = json_decode(@file_get_contents($configPath), true) ?: [];
                    if (is_array($tianqueConfig) && isset($tianqueConfig['honeypot_users'])) {
                        $honeypotUsers = array_map('intval', $tianqueConfig['honeypot_users']);
                    }
                }

                if (empty($honeypotUsers)) {
                    $this->sendMessage($botToken, $chatId, "🍯 当前天阙蜜罐名单为空。");
                    return response()->json(['status' => 'ok']);
                }

                // 从数据库查询对应的用户信息
                $users = User::whereIn('id', $honeypotUsers)->get(['id', 'email']);
                $listStr = '';
                foreach ($honeypotUsers as $uid) {
                    $matchedUser = $users->firstWhere('id', $uid);
                    $email = $matchedUser ? $matchedUser->email : '未知邮箱或已被删除';
                    
                    $addedTimeStr = '未知时间';
                    if (isset($tianqueConfig['honeypot_times']) && is_array($tianqueConfig['honeypot_times'])) {
                        if (isset($tianqueConfig['honeypot_times'][(string)$uid])) {
                            $addedTimeStr = date('Y-m-d H:i:s', $tianqueConfig['honeypot_times'][(string)$uid]);
                        }
                    }
                    $listStr .= "• ID: `{$uid}` | 邮箱: `{$email}` | 加入时间: `{$addedTimeStr}`\n";
                }

                $msg = "🍯 **「天阙」当前蜜罐名单 (共 " . count($honeypotUsers) . " 人)**:\n\n" . $listStr;
                $this->sendMessage($botToken, $chatId, $msg);
            } elseif ($text === '/whitelists' || $text === '/whitelistlist') {
                $configPath = storage_path('tianque_config.json');
                $whitelistUsers = [];
                if (file_exists($configPath)) {
                    $tianqueConfig = json_decode(@file_get_contents($configPath), true);
                    if (is_array($tianqueConfig) && isset($tianqueConfig['whitelist_users'])) {
                        $whitelistUsers = $tianqueConfig['whitelist_users'];
                    }
                }

                if (empty($whitelistUsers)) {
                    $this->sendMessage($botToken, $chatId, "🛡️ 当前审计白名单为空。");
                    return response()->json(['status' => 'ok']);
                }

                $listStr = '';
                foreach ($whitelistUsers as $item) {
                    $listStr .= "• `{$item}`\n";
                }

                $msg = "🛡️ **「天阙」当前白名单 (共 " . count($whitelistUsers) . " 人)**:\n\n" . $listStr;
                $this->sendMessage($botToken, $chatId, $msg);
            } elseif ($text === '/observed' || $text === '/flagged' || $text === '/flaggedlist') {
                $configPath = storage_path('tianque_config.json');
                $flaggedUsers = [];
                if (file_exists($configPath)) {
                    $tianqueConfig = json_decode(@file_get_contents($configPath), true);
                    if (is_array($tianqueConfig) && isset($tianqueConfig['flagged_users'])) {
                        $flaggedUsers = $tianqueConfig['flagged_users'];
                    }
                }

                if (empty($flaggedUsers)) {
                    $this->sendMessage($botToken, $chatId, "📋 当前「重点观察名单」为空，无异常用户需要审查。");
                    return response()->json(['status' => 'ok']);
                }

                $listStr = '';
                foreach ($flaggedUsers as $uid => $info) {
                    $email = $info['email'] ?? '未知邮箱';
                    $flagTime = isset($info['time']) ? date('Y-m-d H:i:s', $info['time']) : '未知时间';
                    $reasons = isset($info['reasons']) && is_array($info['reasons']) ? implode(' | ', $info['reasons']) : '未知触发规则';
                    $listStr .= "• **ID**: `{$uid}`\n"
                              . "  邮箱: `{$email}`\n"
                              . "  标记时间: `{$flagTime}`\n"
                              . "  触发规则: `{$reasons}`\n\n";
                }

                $msg = "📋 **「天阙」当前重点观察名单 (共 " . count($flaggedUsers) . " 人)**:\n\n"
                     . $listStr
                     . "💡 _您可以使用 `/whitelist <ID>` 放行他们，或使用 `/clearflag <ID>` 仅将其从观察名单移出。_";
                $this->sendMessage($botToken, $chatId, $msg);
            } elseif (strpos($text, '/clearflag ') === 0) {
                $param = trim(substr($text, 11));
                if (empty($param)) {
                    $this->sendMessage($botToken, $chatId, "⚠️ 格式错误，请使用: `/clearflag <ID或邮箱>`");
                    return response()->json(['status' => 'ok']);
                }

                $configPath = storage_path('tianque_config.json');
                $config = [];
                if (file_exists($configPath)) {
                    $config = json_decode(@file_get_contents($configPath), true) ?: [];
                }
                if (isset($config['flagged_users']) && is_array($config['flagged_users'])) {
                    $found = false;
                    if (isset($config['flagged_users'][(string)$param])) {
                        unset($config['flagged_users'][(string)$param]);
                        $found = true;
                    } else {
                        foreach ($config['flagged_users'] as $uid => $info) {
                            if (strtolower($info['email'] ?? '') === strtolower($param)) {
                                unset($config['flagged_users'][$uid]);
                                $found = true;
                                break;
                            }
                        }
                    }

                    if ($found) {
                        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                        $this->sendMessage($botToken, $chatId, "✅ 已成功将 `{$param}` 从重点观察名单中移出。");
                    } else {
                        $this->sendMessage($botToken, $chatId, "ℹ️ 观察名单中未找到用户 `{$param}`。");
                    }
                } else {
                    $this->sendMessage($botToken, $chatId, "ℹ️ 观察名单中未找到用户 `{$param}`。");
                }
            } elseif (strpos($text, '/user ') === 0 || strpos($text, '/query ') === 0) {
                $pos = strpos($text, ' ');
                $param = trim(substr($text, $pos + 1));
                if (empty($param)) {
                    $this->sendMessage($botToken, $chatId, "⚠️ 格式错误，请使用: `/user <ID或邮箱>`");
                    return response()->json(['status' => 'ok']);
                }

                // 1. 查询用户
                $user = null;
                if (is_numeric($param)) {
                    $user = User::find((int)$param);
                } else {
                    $user = User::where('email', $param)->first();
                }

                if (!$user) {
                    $this->sendMessage($botToken, $chatId, "ℹ️ 未找到用户 `{$param}`。");
                    return response()->json(['status' => 'ok']);
                }

                // 2. 读取配置判断当前的安全状态
                $configPath = storage_path('tianque_config.json');
                $config = [];
                if (file_exists($configPath)) {
                    $config = json_decode(@file_get_contents($configPath), true) ?: [];
                }

                $inHoneypot = false;
                if (isset($config['honeypot_users']) && is_array($config['honeypot_users'])) {
                    $inHoneypot = in_array((int)$user->id, array_map('intval', $config['honeypot_users']), true);
                }

                $inWhitelist = false;
                if (isset($config['whitelist_users']) && is_array($config['whitelist_users'])) {
                    $inWhitelist = in_array((int)$user->id, array_map('intval', $config['whitelist_users']), true) 
                                || in_array($user->email, $config['whitelist_users'], true);
                }

                $inObserved = false;
                if (isset($config['flagged_users']) && is_array($config['flagged_users'])) {
                    $inObserved = isset($config['flagged_users'][(string)$user->id]);
                }

                $statusStr = '';
                if ($user->banned) {
                    $statusStr .= "🔴 已封禁账号";
                } elseif ($inHoneypot) {
                    $statusStr .= "🍯 蜜罐接管中";
                } elseif ($inWhitelist) {
                    $statusStr .= "🛡️ 白名单放行";
                } elseif ($inObserved) {
                    $statusStr .= "📋 重点观察中";
                } else {
                    $statusStr .= "🟢 正常";
                }

                // 3. 构建画像基本信息
                $registerTime = $user->created_at ? date('Y-m-d H:i:s', $user->created_at) : '未知';
                $expireTime = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效';
                $balanceStr = ($user->balance / 100) . ' 元';
                $commissionStr = ($user->commission_balance / 100) . ' 元';
                $lastOnline = $user->t > 0 ? date('Y-m-d H:i:s', $user->t) : '无在线记录';

                $msg = "👤 **「天阙」用户安全画像**\n"
                     . "====================\n"
                     . "• **用户 ID**: `{$user->id}`\n"
                     . "• **用户邮箱**: `{$user->email}`\n"
                     . "• **当前状态**: **{$statusStr}**\n"
                     . "• **注册时间**: `{$registerTime}`\n"
                     . "• **过期时间**: `{$expireTime}`\n"
                     . "• **账户余额**: `{$balanceStr}`\n"
                     . "• **佣金余额**: `{$commissionStr}`\n"
                     . "• **最后在线**: `{$lastOnline}`\n\n";

                // 4. 读取解析历史拉取记录
                $clientHistory = [];
                if ($user->client_type) {
                    $decoded = json_decode($user->client_type, true);
                    if (is_array($decoded)) {
                        $clientHistory = $decoded;
                    }
                }

                $msg .= "📈 **最近 5 次订阅拉取历史**:\n";
                if (empty($clientHistory)) {
                    $msg .= "   _(当前无历史拉取数据)_\n";
                } else {
                    foreach ($clientHistory as $index => $item) {
                        $num = $index + 1;
                        $timeStr = isset($item['time']) ? date('Y-m-d H:i:s', $item['time']) : '未知';
                        $ip = $item['ip'] ?? '未知IP';
                        $type = $item['type'] ?? '未知';
                        $ua = $item['ua'] ?? '未知UA';
                        $msg .= "   {$num}. `{$timeStr}`\n"
                              . "       IP: `{$ip}`\n"
                              . "       工具: `{$type}`\n"
                              . "       UA: `{$ua}`\n";
                    }
                }

                // 5. 生成快捷管理动作按键
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            $inHoneypot 
                                ? ['text' => '↩️ 移出蜜罐', 'callback_data' => "unhoneypot:{$user->id}"]
                                : ['text' => '🛡️ 放入蜜罐', 'callback_data' => "honeypot:{$user->id}"],
                            $user->banned
                                ? ['text' => '🟢 解除封禁', 'callback_data' => "unban:{$user->id}"]
                                : ['text' => '🚫 封禁账号', 'callback_data' => "ban:{$user->id}"],
                            ['text' => '🔄 重置订阅', 'callback_data' => "reset:{$user->id}"]
                        ]
                    ]
                ];

                $this->sendMessageWithKeyboard($botToken, $chatId, $msg, $keyboard);
            }
            return response()->json(['status' => 'ok']);
        }

        // 处理按钮回调消息
        if (!isset($data['callback_query'])) {
            return response()->json(['status' => 'ok']);
        }

        $callbackQuery = $data['callback_query'];
        $callbackQueryId = $callbackQuery['id'];
        $callbackData = $callbackQuery['data'];
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $fromId = $callbackQuery['from']['id'];
        $originalText = $callbackQuery['message']['text'] ?? '';

        // 安全校验：只有配置好的管理员 ID 才可以点击操作
        if (!$this->isUserAuthorized($botToken, $adminChatId, $fromId, $chatId)) {
            Log::warning("TianQue Security Webhook: Unauthorized callback action attempt.", [
                'from_id' => $fromId,
                'chat_id' => $chatId,
                'admin_chat_id' => $adminChatId
            ]);
            $this->answerCallbackQuery($botToken, $callbackQueryId, "⚠️ 越权警告：你不是该系统的授权管理员！");
            return response()->json(['status' => 'unauthorized']);
        }

        // 解析动作和用户ID，格式为: action:userId
        $parts = explode(':', $callbackData);
        if (count($parts) !== 2) {
            $this->answerCallbackQuery($botToken, $callbackQueryId, "❌ 无效的数据格式");
            return response()->json(['status' => 'invalid_data']);
        }

        $action = $parts[0];
        $userId = (int)$parts[1];

        $user = User::find($userId);
        if (!$user) {
            $this->answerCallbackQuery($botToken, $callbackQueryId, "❌ 未找到该用户 (ID: {$userId})");
            return response()->json(['status' => 'user_not_found']);
        }

        $actionResultStr = '';
        switch ($action) {
            case 'honeypot':
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
                if (!in_array($userId, $currentHoneypots, true)) {
                    $currentHoneypots[] = $userId;
                    $config['honeypot_users'] = $currentHoneypots;
                    $config['honeypot_times'][(string)$userId] = time();
                    @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
                $actionResultStr = "【已移入天阙蜜罐】";
                break;

            case 'unhoneypot':
                $configPath = storage_path('tianque_config.json');
                $config = [];
                if (file_exists($configPath)) {
                    $config = json_decode(@file_get_contents($configPath), true) ?: [];
                }
                if (isset($config['honeypot_users']) && is_array($config['honeypot_users'])) {
                    $currentHoneypots = array_map('intval', $config['honeypot_users']);
                    $key = array_search($userId, $currentHoneypots, true);
                    if ($key !== false) {
                        unset($currentHoneypots[$key]);
                        $config['honeypot_users'] = array_values($currentHoneypots);
                        if (isset($config['honeypot_times']) && is_array($config['honeypot_times'])) {
                            unset($config['honeypot_times'][(string)$userId]);
                        }
                        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    }
                }
                $actionResultStr = "【已从蜜罐中移出】";
                break;

            case 'whitelist':
                $configPath = storage_path('tianque_config.json');
                $config = [];
                if (file_exists($configPath)) {
                    $config = json_decode(@file_get_contents($configPath), true) ?: [];
                }
                if (!isset($config['whitelist_users']) || !is_array($config['whitelist_users'])) {
                    $config['whitelist_users'] = [];
                }
                if (!in_array($userId, $config['whitelist_users'], true)) {
                    $config['whitelist_users'][] = $userId;
                    // 从蜜罐名单移出
                    if (isset($config['honeypot_users']) && is_array($config['honeypot_users'])) {
                        $currentHoneypots = array_map('intval', $config['honeypot_users']);
                        $key = array_search($userId, $currentHoneypots, true);
                        if ($key !== false) {
                            unset($currentHoneypots[$key]);
                            $config['honeypot_users'] = array_values($currentHoneypots);
                        }
                    }
                    if (isset($config['honeypot_times']) && is_array($config['honeypot_times'])) {
                        unset($config['honeypot_times'][(string)$userId]);
                    }
                    @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
                $actionResultStr = "【已加入白名单】";
                break;

            case 'ban':
                $user->banned = 1;
                $user->save();
                $actionResultStr = "【已封禁账号】";
                break;

            case 'unban':
                $user->banned = 0;
                $user->save();
                $actionResultStr = "【已解除封禁】";
                break;

            case 'reset':
                $user->token = $this->generateGuid();
                $user->uuid = $this->generateGuid(false);
                $user->save();
                $actionResultStr = "【已重置订阅 Token/UUID】";
                break;

            default:
                $this->answerCallbackQuery($botToken, $callbackQueryId, "❌ 未知操作");
                return response()->json(['status' => 'unknown_action']);
        }

        // --------------------------------------------------
        // 从重点观察名单中移除已处置用户
        // --------------------------------------------------
        $configPath = storage_path('tianque_config.json');
        if (file_exists($configPath)) {
            $config = json_decode(@file_get_contents($configPath), true) ?: [];
            if (isset($config['flagged_users']) && is_array($config['flagged_users'])) {
                if (isset($config['flagged_users'][(string)$userId])) {
                    unset($config['flagged_users'][(string)$userId]);
                    @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
            }
        }

        // 提示管理员操作成功
        $this->answerCallbackQuery($botToken, $callbackQueryId, "✅ 操作成功: {$actionResultStr}");

        // 修改原始电报消息，更新执行动作状态
        $newText = $originalText;
        if (preg_match('/⚙️ 执行动作:.*$/m', $newText)) {
            $newText = preg_replace('/⚙️ 执行动作:.*$/m', "⚙️ 执行动作: {$actionResultStr} (管理员 via TG)", $newText);
        } elseif (preg_match('/执行动作:.*$/m', $newText)) {
            $newText = preg_replace('/执行动作:.*$/m', "执行动作: {$actionResultStr} (管理员 via TG)", $newText);
        } else {
            $newText .= "\n⚙️ 执行动作: {$actionResultStr} (管理员 via TG)";
        }

        // 动态查询最新的用户状态，重新生成操作按钮以完成切换
        $newInHoneypot = false;
        $configPath = storage_path('tianque_config.json');
        if (file_exists($configPath)) {
            $config = json_decode(@file_get_contents($configPath), true) ?: [];
            $honeypots = array_map('intval', $config['honeypot_users'] ?? []);
            if (in_array($userId, $honeypots, true)) {
                $newInHoneypot = true;
            }
        }
        $newIsBanned = (bool)$user->banned;

        $newKeyboard = [
            'inline_keyboard' => [
                [
                    $newInHoneypot 
                        ? ['text' => '↩️ 移出蜜罐', 'callback_data' => "unhoneypot:{$userId}"]
                        : ['text' => '🛡️ 放入蜜罐', 'callback_data' => "honeypot:{$userId}"],
                    $newIsBanned
                        ? ['text' => '🟢 解除封禁', 'callback_data' => "unban:{$userId}"]
                        : ['text' => '🚫 封禁账号', 'callback_data' => "ban:{$userId}"],
                    ['text' => '🔄 重置订阅', 'callback_data' => "reset:{$userId}"]
                ]
            ]
        ];

        $this->editMessageText($botToken, $chatId, $messageId, $newText, $newKeyboard);

        return response()->json(['status' => 'ok']);
    }

    /**
     * 判断发起指令或点击按钮的用户是否已被授权
     */
    private function isUserAuthorized($botToken, $adminChatId, $fromId, $chatId)
    {
        // 1. 直连匹配：如果管理员 ID 就是用户的个人 Chat ID
        if ((string)$fromId === (string)$adminChatId) {
            return true;
        }

        // 2. 群组内匹配：如果在允许的群组里直接发送命令
        if ((string)$chatId === (string)$adminChatId) {
            return true;
        }

        // 3. 跨群验证：如果在私聊里说话，但管理员配置的是群组 ID
        if (strpos((string)$adminChatId, '-') === 0) {
            $url = "https://api.telegram.org/bot{$botToken}/getChatMember";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt_array($ch, [
                CURLOPT_POSTFIELDS => http_build_query([
                    'chat_id' => $adminChatId,
                    'user_id' => $fromId,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5
            ]);
            $res = curl_exec($ch);
            curl_close($ch);

            $response = json_decode($res, true);
            if (isset($response['ok']) && $response['ok'] === true) {
                $status = $response['result']['status'] ?? '';
                // 只要是群主、管理员或群内成员，就允许在私聊中使用
                if (in_array($status, ['creator', 'administrator', 'member'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sendMessage($botToken, $chatId, $text, $parseMode = 'Markdown')
    {
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt_array($ch, [
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => $parseMode,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function sendMessageWithKeyboard($botToken, $chatId, $text, $replyMarkup, $parseMode = 'Markdown')
    {
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt_array($ch, [
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => $parseMode,
                'reply_markup' => json_encode($replyMarkup)
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function answerCallbackQuery($botToken, $callbackQueryId, $text)
    {
        $url = "https://api.telegram.org/bot{$botToken}/answerCallbackQuery";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt_array($ch, [
            CURLOPT_POSTFIELDS => http_build_query([
                'callback_query_id' => $callbackQueryId,
                'text' => $text,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function editMessageText($botToken, $chatId, $messageId, $text, $replyMarkup = null)
    {
        $url = "https://api.telegram.org/bot{$botToken}/editMessageText";
        $postData = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];
        if ($replyMarkup !== null) {
            $postData['reply_markup'] = json_encode($replyMarkup);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt_array($ch, [
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function generateGuid($trim = true)
    {
        $guid = sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
        return $trim ? str_replace('-', '', strtolower($guid)) : strtolower($guid);
    }
}
