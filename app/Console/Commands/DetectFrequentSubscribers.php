<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;

class DetectFrequentSubscribers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:detect-subscribers {--ban : 是否直接封禁检测到的异常用户} {--interval=300 : 检测的目标间隔秒数，默认 300 (即 5 分钟)} {--tolerance=30 : 时间波动的容差秒数，默认 30 秒}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '检测并揪出异常高频、定时拉取订阅（如 5 分钟定时测活）的用户';

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
        $targetInterval = (int) $this->option('interval');
        $tolerance = (int) $this->option('tolerance');

        $this->info("开始扫描高频异常订阅拉取用户... (目标间隔: {$targetInterval} 秒，波动容差: ±{$tolerance} 秒)");

        // 拉取未封禁且拉取过订阅的用户
        $users = User::where('banned', 0)
            ->whereNotNull('client_type')
            ->get(['id', 'email', 'client_type', 'client_login_at']);

        $detectedCount = 0;

        foreach ($users as $user) {
            $history = json_decode($user->client_type, true);
            
            // 必须具有至少 5 次以上的拉取记录（确保样本充足，防止偶然拉取造成的误判）
            if (!is_array($history) || count($history) < 5) {
                continue;
            }

            // 计算相邻两次拉取的时间差
            $diffs = [];
            for ($i = 0; $i < count($history) - 1; $i++) {
                // $history 默认按时间倒序排列，$history[0] 是最新一次
                $diff = $history[$i]['time'] - $history[$i + 1]['time'];
                $diffs[] = $diff;
            }

            // 统计差值
            $averageInterval = array_sum($diffs) / count($diffs);
            $maxInterval = max($diffs);
            $minInterval = min($diffs);
            $range = $maxInterval - $minInterval; // 极差，反映拉取规律性

            // 异常判定条件：
            // 1. 平均间隔在设定范围内：[目标值 - 容差, 目标值 + 容差] 或者平均时间差小于一定数值且规律极度稳定
            // 2. 间隔的极差（波动范围）极小（例如小于给定的容差，如 30 秒内），这代表绝对是机器的 crontab / 定时自动测活脚本，普通人的手动刷新不可能如此精准
            
            $isTargetInterval = abs($averageInterval - $targetInterval) <= $tolerance;
            $isExtremelyRegular = $range <= $tolerance;

            // 另外一种情况：哪怕不是严格的 5 分钟，只要拉取平均间隔小于等于默认的 10 分钟（600秒），且差值波动极小（极差 <= 15 秒），也属于机器挂机测活
            $isGeneralFastRegular = $averageInterval <= 600 && $range <= 15;

            if (($isTargetInterval && $isExtremelyRegular) || $isGeneralFastRegular) {
                $detectedCount++;
                
                $historyDetails = collect($history)->map(function ($item) {
                    $ipStr = isset($item['ip']) ? " [IP: {$item['ip']}]" : "";
                    return date('Y-m-d H:i:s', $item['time']) . " ({$item['type']}){$ipStr}";
                })->join("\n        -> ");

                $ips = collect($history)->pluck('ip')->filter()->unique()->join(', ');
                $uas = collect($history)->pluck('ua')->filter()->unique()->join(' | ');

                $this->warn("------------------------------------------------------------------");
                $this->warn("⚠️ 捕获到定时测活用户 [ID: {$user->id}]");
                $this->line("邮箱: {$user->email}");
                $this->line("平均拉取间隔: " . round($averageInterval) . " 秒 (极差: {$range} 秒)");
                $this->line("拉取服务器IP群: {$ips}");
                if (!empty($uas)) {
                    $this->line("拉取所用UA: {$uas}");
                }
                $this->line("最近拉取历史:\n        -> {$historyDetails}");

                if ($ban) {
                    $user->banned = 1;
                    $user->save();
                    $this->error("🚨 已自动将该用户封禁 (banned=1)");
                }
            }
        }

        $this->info("------------------------------------------------------------------");
        $this->info("扫描结束。共捕获到异常用户数量: {$detectedCount}");
        
        return 0;
    }
}
