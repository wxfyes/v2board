<?php

namespace App\Plugins\ExternalNode\Commands;

use Illuminate\Console\Command;
use App\Plugins\ExternalNode\Services\StorageService;
use App\Plugins\ExternalNode\Services\CollectorService;
use App\Plugins\ExternalNode\Services\CleanerService;

class CollectCommand extends Command
{
    protected $signature = 'external:collect';
    protected $description = '采集并清洗外部免费节点源';

    public function handle()
    {
        $this->info('[' . date('Y-m-d H:i:s') . '] 开始采集外部订阅源...');

        $data = StorageService::load();
        $sources = $data['sources'] ?? [];
        $settings = $data['settings'] ?? [];

        $allRawNodes = [];
        $uniqueMap = [];

        foreach ($sources as &$src) {
            if (empty($src['enabled'])) continue;

            $this->line("正在抓取: {$src['name']} ({$src['url']})");
            $nodes = CollectorService::fetchSource($src['url']);
            $src['last_sync_at'] = time();
            $src['node_count'] = count($nodes);

            foreach ($nodes as $n) {
                $sig = ($n['host'] ?? '') . ':' . ($n['port'] ?? '');
                if (!isset($uniqueMap[$sig]) && !empty($n['host'])) {
                    $uniqueMap[$sig] = true;
                    $allRawNodes[] = $n;
                }
            }
            $this->info(" -> 获取 {$src['node_count']} 个原始节点");
        }
        unset($src);

        $formattedNodes = [];
        $index = 1;
        foreach ($allRawNodes as $node) {
            $cleaned = CleanerService::cleanAndFormatName($node['raw_name'] ?? '', $index++, $settings);
            $node['formatted_name'] = $cleaned['formatted_name'];
            $node['region'] = $cleaned['region'];
            $node['emoji'] = $cleaned['emoji'];
            $node['is_online'] = true;
            $node['latency'] = 0;
            $node['offline_count'] = 0;
            $formattedNodes[] = $node;
        }

        $data['nodes'] = $formattedNodes;
        StorageService::save($data);

        $this->info("✅ 采集清洗入库完成，去重后共 " . count($formattedNodes) . " 个节点");
        return 0;
    }
}
