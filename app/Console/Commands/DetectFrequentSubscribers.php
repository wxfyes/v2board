<?php
 
namespace App\Console\Commands;
 
use Illuminate\Console\Command;
use App\Models\User;
use App\Services\TelegramService;
 
class DetectFrequentSubscribers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:detect-subscribers 
                            {--ban : 是否直接封禁检测到的异常用户} 
                            {--honeypot : 自动将用户加入天阙灰名单（蜜罐），触发重定向/诱导策略}
                            {--reset-token : 自动重置用户订阅 Token 和 UUID}
                            {--interval=300 : 定时测活检测的目标间隔秒数，默认 300 秒} 
                            {--tolerance=30 : 时间波动的容差秒数，默认 30 秒}
                            {--ip-limit=10 : 24小时内拉取订阅的独立 IP 数阈值，达到此数值判定为多IP滥用}
                            {--tg-chat= : 额外的 Telegram 接收 Chat ID（支持群组/频道/个人ID）}
                            {--tg-token= : 独立的 Telegram Bot Token（若不指定则自动调用系统内置 Bot）}';
 
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '【天阙订阅审计中心】全方位排查高频定时测活、多IP共享扩散、异常UA爬取等内鬼和滥用行为';
 
    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }
 
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $ban = $this->option('ban');
        $honeypot = $this->option('honeypot');
        $resetToken = $this->option('reset-token');
        
        $targetInterval = (int) $this->option('interval');
        $tolerance = (int) $this->option('tolerance');
        $ipLimit = (int) $this->option('ip-limit');
 
        $this->info("==================================================================");
        $this->info("🔍 开始启动【天阙订阅安全审计扫描】...");
        $this->info("   定时时间目标间隔: {$targetInterval} 秒 (容差: ±{$tolerance} 秒)");
        $this->info("   24h独立IP阈值: {$ipLimit} 个");
        $this->info("==================================================================");
 
        // 扫描未封禁且拥有订阅记录的用户
        $users = User::where('banned', 0)
            ->whereNotNull('client_type')
            ->get(['id', 'email', 'client_type', 'group_id', 'token', 'uuid']);
 
        $detectedCount = 0;
        $now = time();
        $whitelistClients = ['天阙(TianQue)', 'Mclash', 'MOMclash'];
        $abnormalKeywords = ['curl', 'wget', 'python', 'requests', 'go-http', 'urllib', 'httpclient', 'postman', 'aria2'];
 
        foreach ($users as $user) {
            $history = json_decode($user->client_type, true);
            
            // 记录为空或过少跳过
            if (!is_array($history) || count($history) < 1) {
                continue;
            }
 
            // 过滤白名单：若所有客户端均为天阙等官方客户端，跳过
            $allWhitelist = true;
            foreach ($history as $item) {
                if (!in_array($item['type'], $whitelistClients)) {
                    $allWhitelist = false;
                    break;
                }
            }
            if ($allWhitelist) {
                continue;
            }
 
            $reasons = [];
            
            // --------------------------------------------------
            // 维度 1：24小时内多 IP 扩散滥用检测（账号分享）
            // --------------------------------------------------
            $ipsIn24h = [];
            foreach ($history as $item) {
                if (!empty($item['ip']) && isset($item['time'])) {
                    if ($now - $item['time'] <= 86400) {
                        $ipsIn24h[] = $item['ip'];
                    }
                }
            }
            $uniqueIps = array_values(array_unique($ipsIn24h));
            $ipCount = count($uniqueIps);
            
            if ($ipCount >= $ipLimit) {
                $reasons[] = "24h内使用 {$ipCount} 个独立 IP 拉取订阅 (IP列表: " . implode(', ', $uniqueIps) . ")";
            }
 
            // --------------------------------------------------
            // 维度 2：定时高频规律拉取检测（测活爬虫/挂机）
            // --------------------------------------------------
            $isRegular = false;
            $averageInterval = 0;
            $range = 0;
            if (count($history) >= 5) {
                // 确保历史按时间降序排列
                usort($history, function($a, $b) {
                    return $b['time'] <=> $a['time'];
                });
                
                // 检查最近一次拉取是否在 24 小时内，若太久不活跃则不判定规律性
                if ($now - $history[0]['time'] <= 86400) {
                    $diffs = [];
                    for ($i = 0; $i < count($history) - 1; $i++) {
                        $diff = $history[$i]['time'] - $history[$i + 1]['time'];
                        if ($diff > 0) {
                            $diffs[] = $diff;
                        }
                    }
                    
                    if (count($diffs) > 0) {
                        $averageInterval = array_sum($diffs) / count($diffs);
                        $maxInterval = max($diffs);
                        $minInterval = min($diffs);
                        $range = $maxInterval - $minInterval;
 
                        $isTargetInterval = abs($averageInterval - $targetInterval) <= $tolerance;
                        $isExtremelyRegular = $range <= $tolerance;
                        $isGeneralFastRegular = $averageInterval <= 600 && $range <= 15;
 
                        if (($isTargetInterval && $isExtremelyRegular) || $isGeneralFastRegular) {
                            $isRegular = true;
                            $reasons[] = "定时高频拉取测活 (平均间隔: " . round($averageInterval) . " 秒，波动极差: {$range} 秒)";
                        }
                    }
                }
            }
 
            // --------------------------------------------------
            // 维度 3：敏感开发工具 UA 检测（订阅爬虫扫描）
            // --------------------------------------------------
            $hasAbnormalUa = false;
            $abnormalUaName = '';
            foreach ($history as $item) {
                $uaLower = strtolower($item['ua'] ?? ($item['type'] ?? ''));
                foreach ($abnormalKeywords as $kw) {
                    if (strpos($uaLower, $kw) !== false) {
                        $hasAbnormalUa = true;
                        $abnormalUaName = $item['ua'] ?? $item['type'];
                        break 2;
                    }
                }
            }
            if ($hasAbnormalUa) {
                $reasons[] = "敏感的命令行或开发库 UA 请求 (检测到: {$abnormalUaName})";
            }
 
            // --------------------------------------------------
            // 处置与告警逻辑
            // --------------------------------------------------
            if (count($reasons) > 0) {
                $detectedCount++;
                
                $this->warn("------------------------------------------------------------------");
                $this->warn("⚠️ 捕获到异常订阅拉取用户 [ID: {$user->id}]");
                $this->line("   邮箱: {$user->email}");
                foreach ($reasons as $reason) {
                    $this->line("   🚨 触发原因: {$reason}");
                }
 
                // 组合执行的处置动作
                $actions = [];
                
                if ($ban) {
                    $user->banned = 1;
                    $actions[] = "【封禁账户】";
                }
                
                if ($honeypot) {
                    $configPath = storage_path('tianque_config.json');
                    $config = [];
                    if (file_exists($configPath)) {
                        $config = json_decode(@file_get_contents($configPath), true) ?: [];
                    }
                    if (!isset($config['honeypot_users']) || !is_array($config['honeypot_users'])) {
                        $config['honeypot_users'] = [];
                    }
                    $honeypots = array_map('intval', $config['honeypot_users']);
                    if (!in_array((int)$user->id, $honeypots, true)) {
                        $honeypots[] = (int)$user->id;
                        $config['honeypot_users'] = $honeypots;
                        @file_put_contents($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    }
                    $actions[] = "【加入天阙蜜罐灰名单】";
                }
                
                if ($resetToken) {
                    $oldToken = $user->token;
                    $user->token = $this->generateGuid();
                    $user->uuid = $this->generateGuid(false);
                    $actions[] = "【重置订阅 Token】";
                }
 
                $actionTakenStr = count($actions) > 0 ? implode(' + ', $actions) : "无（仅审计记录）";
                
                if (count($actions) > 0) {
                    $user->save();
                    $this->error("   ⚡ 自动处置结果: {$actionTakenStr}");
                }
 
                // --------------------------------------------------
                // 发送 Telegram 告警推送
                // --------------------------------------------------
                $historyDetails = collect($history)->map(function ($item) {
                    $ipStr = isset($item['ip']) ? " [IP: {$item['ip']}]" : "";
                    return date('m-d H:i:s', $item['time']) . " ({$item['type']}){$ipStr}";
                })->join("\n        -> ");
 
                $tgMessage = "⚠️ 【天阙安全审计警报】\n"
                           . "发现异常订阅拉取用户！\n\n"
                           . "👤 用户邮箱: `{$user->email}`\n"
                           . "🆔 用户 ID: `{$user->id}`\n"
                           . "📊 触发规则:\n"
                           . "• " . implode("\n• ", $reasons) . "\n"
                           . "⚙️ 执行动作: {$actionTakenStr}\n"
                           . "📅 时间: " . date('Y-m-d H:i:s') . "\n"
                           . "📝 最近拉取记录:\n"
                           . "        -> " . $historyDetails;
 
                $this->sendTelegramNotification($tgMessage);
            }
        }
 
        $this->info("==================================================================");
        $this->info("🎉 扫描完成。本次共捕获并处置异常用户: {$detectedCount} 个");
        $this->info("==================================================================");
        
        return 0;
    }
 
    /**
     * 发送 Telegram 推送通知
     */
    private function sendTelegramNotification($message)
    {
        $customToken = $this->option('tg-token') ?: env('SECURITY_TG_TOKEN');
        $customChat = $this->option('tg-chat') ?: env('SECURITY_TG_CHAT');
 
        // 优先使用命令传入的自定义 Bot/Chat
        if (!empty($customToken) && !empty($customChat)) {
            try {
                $url = "https://api.telegram.org/bot{$customToken}/sendMessage";
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt_array($ch, [
                    CURLOPT_POSTFIELDS => http_build_query([
                        'chat_id' => $customChat,
                        'text' => $message,
                        'parse_mode' => 'Markdown'
                    ]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5
                ]);
                curl_exec($ch);
                curl_close($ch);
            } catch (\Exception $e) {
                $this->error("发送自定义 TG 推送失败: " . $e->getMessage());
            }
        } else {
            // 回退到 V2Board 系统内置的 Telegram 告警
            try {
                if (class_exists('App\Services\TelegramService')) {
                    $telegramService = new TelegramService();
                    $telegramService->sendMessageWithAdmin($message);
                }
            } catch (\Exception $e) {
                // 忽略系统推送失败
            }
        }
    }
 
    /**
     * 生成 GUID
     */
    private function generateGuid($trim = true)
    {
        $guid = sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
        return $trim ? str_replace('-', '', strtolower($guid)) : strtolower($guid);
    }
}
