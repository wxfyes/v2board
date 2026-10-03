<?php

use Illuminate\Support\Facades\Route;
use App\Plugins\ExternalNode\Controllers\FreeSubscribeController;
use App\Plugins\ExternalNode\Controllers\AdminApiController;

// ==========================================
// 🛡️ ExternalNode 商业独立插件专用路由
// ==========================================

// 1. 物理隔离的免费引流专用订阅通道 (默认保底路径)
Route::get('/api/v1/free/subscribe', [FreeSubscribeController::class, 'subscribe']);

// 2. 动态注册站长自定义的独立防封订阅路径 (支持根路径与 api/v1 双向精准响应)
try {
    $data = \App\Plugins\ExternalNode\Services\StorageService::load();
    $rawPath = trim($data['settings']['free_sub_path'] ?? '');
    if (!empty($rawPath)) {
        $cleanPath = trim($rawPath, '/');
        if ($cleanPath !== 'api/v1/free/subscribe') {
            // 直接在根级别注册用户自定义的精准路径 (如 free/sub 或 api/v1/my_sub)
            Route::get('/' . $cleanPath, [FreeSubscribeController::class, 'subscribe']);

            // 若用户配置的不是以 api/ 开头，同时在 /api/v1/ 下进行防呆容错注册
            if (strpos($cleanPath, 'api/') !== 0) {
                Route::get('/api/v1/' . $cleanPath, [FreeSubscribeController::class, 'subscribe']);
            }
        }
    }
} catch (\Throwable $e) {}

Route::prefix('api/v1')->group(function () {
    // 3. 插件管理后台接口与独立可视化控制台
    Route::prefix('admin/plugin/external-node')->group(function () {
        Route::get('/dashboard', [AdminApiController::class, 'dashboard']); // 可视化控制台 Web 页面
        Route::post('/verify', [AdminApiController::class, 'verify']);       // 安全密钥在线验证
        Route::get('/overview', [AdminApiController::class, 'getOverview']);
        Route::post('/settings', [AdminApiController::class, 'updateSettings']);
        Route::post('/sources', [AdminApiController::class, 'saveSources']);
        Route::post('/collect', [AdminApiController::class, 'manualCollect']);
        Route::post('/check', [AdminApiController::class, 'manualCheck']);
        Route::post('/reset-sources', [AdminApiController::class, 'resetSources']);
    });
});

// 3. 便捷快捷路由 (访问 /admin/external-nodes 直接进入控制台)
Route::get('/admin/external-nodes', [AdminApiController::class, 'dashboard']);
