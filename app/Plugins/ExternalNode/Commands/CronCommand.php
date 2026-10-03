<?php

namespace App\Plugins\ExternalNode\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use App\Plugins\ExternalNode\Services\StorageService;

class CronCommand extends Command
{
    protected $signature = 'external:cron';
    protected $description = '外部节点独立调度守护任务（定时采集、周期测活与离线净化）';

    public function handle()
    {
        $this->info('[' . date('Y-m-d H:i:s') . '] ExternalNode 定时调度触发...');

        $data = StorageService::load();
        $settings = $data['settings'] ?? [];
        $lastCollect = $data['updated_at'] ?? 0;

        // 1. 每 2 小时自动跑一次全网采集更新 (7200秒)
        if (time() - $lastCollect >= 7200 || empty($data['nodes'])) {
            $this->info('触发周期性节点采集更新...');
            Artisan::call('external:collect');
        }

        // 2. 每次 cron 均触发并发测活
        $this->info('触发周期性测活...');
        Artisan::call('external:check');

        // 3. 自动清理超期离线死节点 (如连续离线超过 12 小时)
        $cleanHours = (int)($settings['auto_clean_offline_hours'] ?? 12);
        if ($cleanHours > 0) {
            $data = StorageService::load();
            $nodes = $data['nodes'] ?? [];
            $initialCount = count($nodes);
            $cleanBefore = time() - ($cleanHours * 3600);

            $data['nodes'] = array_values(array_filter($nodes, function ($n) use ($cleanBefore) {
                // 如果在线，保留
                if (!empty($n['is_online'])) return true;
                // 如果从未上线或上次在线时间早于阈值，剔除
                $lastOnline = $n['last_online_at'] ?? 0;
                return $lastOnline > $cleanBefore;
            }));

            $purged = $initialCount - count($data['nodes']);
            if ($purged > 0) {
                StorageService::save($data);
                $this->info("🧹 净化剔除 {$purged} 个长期离线死节点");
            }
        }

        $this->info('[' . date('Y-m-d H:i:s') . '] 调度流程完成');
        return 0;
    }
}
