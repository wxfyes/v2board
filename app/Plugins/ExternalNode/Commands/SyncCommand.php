<?php

namespace App\Plugins\ExternalNode\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SyncCommand extends Command
{
    protected $signature = 'external-node:sync';
    protected $description = '一键同步并测活外部免费节点（采集 + 测活）';

    public function handle()
    {
        $this->info('[' . date('Y-m-d H:i:s') . '] 开始一键同步外部节点池...');

        $this->call('external:collect');
        $this->call('external:check');

        $this->info('[' . date('Y-m-d H:i:s') . '] 外部节点一键同步与测活已完成！');
    }
}
