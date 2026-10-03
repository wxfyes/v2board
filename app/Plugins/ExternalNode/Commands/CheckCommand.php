<?php

namespace App\Plugins\ExternalNode\Commands;

use Illuminate\Console\Command;
use App\Plugins\ExternalNode\Services\StorageService;
use App\Plugins\ExternalNode\Services\CheckerService;

class CheckCommand extends Command
{
    protected $signature = 'external:check';
    protected $description = '对外部节点池进行非阻塞并发测活与离线剔除';

    public function handle()
    {
        $this->info('[' . date('Y-m-d H:i:s') . '] 开始执行并发节点测活...');

        $data = StorageService::load();
        $nodes = $data['nodes'] ?? [];

        if (empty($nodes)) {
            $this->warn('节点池为空，无需测活');
            return 0;
        }

        $total = count($nodes);
        $this->line("总节点数: {$total}，正在启动非阻塞并发探测...");

        $checked = CheckerService::checkAll($nodes, 40, 1.8);
        $online = 0;

        foreach ($checked as $item) {
            if (!empty($item['is_online'])) {
                $online++;
            }
        }

        $data['nodes'] = $checked;
        StorageService::save($data);

        $this->info("✅ 测活完成: 在线 {$online} / 总计 {$total}");
        return 0;
    }
}
