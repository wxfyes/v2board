<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // 🛡️ ExternalNode 商业独立插件：安全自动挂载 (目录存在即启用，删除即完全无感卸载)
        if (class_exists(\App\Plugins\ExternalNode\ExternalNodeServiceProvider::class)) {
            $this->app->register(\App\Plugins\ExternalNode\ExternalNodeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['view']->addNamespace('theme', public_path() . '/theme');
    }
}
