<?php

namespace App\Plugins\ExternalNode;

use Illuminate\Support\ServiceProvider;
use App\Plugins\ExternalNode\Commands\CollectCommand;
use App\Plugins\ExternalNode\Commands\CheckCommand;
use App\Plugins\ExternalNode\Commands\CronCommand;
use App\Plugins\ExternalNode\Commands\SyncCommand;

class ExternalNodeServiceProvider extends ServiceProvider
{
    /**
     * 注册服务与命令
     */
    public function register()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CollectCommand::class,
                CheckCommand::class,
                CronCommand::class,
                SyncCommand::class,
            ]);
        }
    }

    /**
     * 启动引导
     */
    public function boot()
    {
        // 自动加载独立路由
        $routesPath = __DIR__ . '/routes.php';
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }
    }
}
