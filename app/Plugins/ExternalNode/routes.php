<?php

use Illuminate\Support\Facades\Route;
use App\Plugins\ExternalNode\Controllers\FreeSubscribeController;
use App\Plugins\ExternalNode\Controllers\AdminApiController;

// ==========================================
// 🛡️ ExternalNode 商业独立插件专用路由
// ==========================================

Route::prefix('api/v1')->group(function () {
    // 1. 物理隔离的免费引流专用订阅通道 (默认路径)
    Route::get('/free/subscribe', [FreeSubscribeController::class, 'subscribe']);

    // 动态注册用户自定义的独立下发路径 (非写死，可任意变更防特征阻断)
    try {
        $data = \App\Plugins\ExternalNode\Services\StorageService::load();
        $customPath = ltrim(trim($data['settings']['free_sub_path'] ?? ''), '/');
        if (!empty($customPath) && $customPath !== 'api/v1/free/subscribe') {
            Route::get($customPath, [FreeSubscribeController::class, 'subscribe']);
        }
    } catch (\Throwable $e) {}

    // 2. 插件管理后台接口与独立可视化控制台
    Route::prefix('admin/plugin/external-node')->group(function () {
        Route::get('/dashboard', [AdminApiController::class, 'dashboard']); // 可视化控制台 Web 页面
        Route::post('/verify', [AdminApiController::class, 'verify']);       // 安全密钥在线验证
        Route::get('/overview', [AdminApiController::class, 'getOverview']);
        Route::post('/settings', [AdminApiController::class, 'updateSettings']);
        Route::post('/sources', [AdminApiController::class, 'saveSources']);
        Route::post('/collect', [AdminApiController::class, 'manualCollect']);
        Route::post('/check', [AdminApiController::class, 'manualCheck']);
    });
});

// 3. 便捷快捷路由 (访问 /admin/external-nodes 直接进入控制台)
Route::get('/admin/external-nodes', [AdminApiController::class, 'dashboard']);
