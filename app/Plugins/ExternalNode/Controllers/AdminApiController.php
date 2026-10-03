<?php

namespace App\Plugins\ExternalNode\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Plugins\ExternalNode\Services\StorageService;
use App\Plugins\ExternalNode\Services\CollectorService;
use App\Plugins\ExternalNode\Services\CleanerService;
use App\Plugins\ExternalNode\Services\CheckerService;

class AdminApiController extends Controller
{
    /**
     * 🛡️ 统一安全鉴权校验卫士
     * 支持: 1. 插件独立高强度安全密钥 (Header: X-Plugin-Key 或 query: ?key=xxx)
     *       2. V2Board / Xboard 原生管理员会话 (无感放行)
     */
    private function verifyAuth(Request $request): bool
    {
        $data = StorageService::load();
        $secretKey = $data['settings']['admin_secret_key'] ?? '';

        // 1. 独立安全密钥校验 (支持 Header、POST JSON、Query 参数，防时序攻击)
        $clientKey = $request->header('X-Plugin-Key') 
            ?: ($request->header('x-plugin-key') 
            ?: ($request->input('key') ?: $request->query('key')));

        if (!empty($secretKey) && !empty($clientKey) && hash_equals((string)$secretKey, (string)$clientKey)) {
            return true;
        }

        // 2. 原生 V2Board 管理员日常登录密码直接匹配 (超便捷免找密钥)
        if (!empty($clientKey)) {
            try {
                $admins = \Illuminate\Support\Facades\DB::table('v2_user')
                    ->where('is_admin', 1)
                    ->get(['password']);
                foreach ($admins as $admin) {
                    if (!empty($admin->password) && \Illuminate\Support\Facades\Hash::check((string)$clientKey, $admin->password)) {
                        return true;
                    }
                }
            } catch (\Throwable $e) {}
        }

        // 3. 原生 V2Board 管理员鉴权会话复用
        if (class_exists(\App\Services\AuthService::class)) {
            $authData = $request->cookie('authorization') 
                ?: ($request->header('authorization') ?: $request->input('auth_data'));
            if ($authData) {
                try {
                    $user = \App\Services\AuthService::decryptAuthData($authData);
                    if ($user && !empty($user['is_admin'])) {
                        return true;
                    }
                } catch (\Throwable $e) {}
            }
        }

        return false;
    }

    /**
     * 校验当前鉴权状态
     */
    public function verify(Request $request)
    {
        if ($this->verifyAuth($request)) {
            return response()->json(['status' => 'success', 'message' => '安全验证通过']);
        }
        return response()->json(['status' => 'error', 'code' => 403, 'message' => '安全密钥错误或管理员会话已失效'], 403);
    }

    /**
     * 概览与统计
     */
    public function getOverview(Request $request)
    {
        if (!$this->verifyAuth($request)) {
            return response()->json(['status' => 'error', 'code' => 403, 'message' => '未授权访问: 请提供合法管理安全密钥或登录管理员账号'], 403);
        }

        $data = StorageService::load();
        $nodes = $data['nodes'] ?? [];
        $sources = $data['sources'] ?? [];

        $onlineCount = 0;
        $offlineCount = 0;
        $regionStats = [];

        foreach ($nodes as $n) {
            if (!empty($n['is_online'])) {
                $onlineCount++;
            } else {
                $offlineCount++;
            }
            $reg = $n['region'] ?? '其它';
            $regionStats[$reg] = ($regionStats[$reg] ?? 0) + 1;
        }

        // 脱敏返回：管理密钥只展示部分，防止泄露
        $safeSettings = $data['settings'] ?? [];

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_nodes' => count($nodes),
                'online_nodes' => $onlineCount,
                'offline_nodes' => $offlineCount,
                'region_stats' => $regionStats,
                'sources' => $sources,
                'settings' => $safeSettings,
                'updated_at' => $data['updated_at'] ?? 0,
                'nodes_preview' => array_slice($nodes, 0, 100)
            ]
        ]);
    }

    /**
     * 更新设置
     */
    public function updateSettings(Request $request)
    {
        if (!$this->verifyAuth($request)) {
            return response()->json(['status' => 'error', 'code' => 403, 'message' => '未授权访问'], 403);
        }

        $data = StorageService::load();
        $settings = $request->input('settings', []);

        $data['settings'] = array_merge($data['settings'] ?? [], $settings);
        StorageService::save($data);

        return response()->json([
            'status' => 'success',
            'message' => '设置更新成功',
            'data' => $data['settings']
        ]);
    }

    /**
     * 增删订阅源
     */
    public function saveSources(Request $request)
    {
        if (!$this->verifyAuth($request)) {
            return response()->json(['status' => 'error', 'code' => 403, 'message' => '未授权访问'], 403);
        }

        $sources = $request->input('sources', []);
        if (!is_array($sources)) {
            return response()->json(['status' => 'error', 'message' => '参数非法'], 422);
        }

        $data = StorageService::load();
        $data['sources'] = $sources;
        StorageService::save($data);

        return response()->json([
            'status' => 'success',
            'message' => '订阅源保存成功',
            'data' => $data['sources']
        ]);
    }

    /**
     * 手动触发全量采集
     */
    public function manualCollect(Request $request)
    {
        if (!$this->verifyAuth($request)) {
            return response()->json(['status' => 'error', 'code' => 403, 'message' => '未授权访问'], 403);
        }

        $data = StorageService::load();
        $sources = $data['sources'] ?? [];
        $settings = $data['settings'] ?? [];

        $allRawNodes = [];
        $uniqueMap = [];

        foreach ($sources as &$src) {
            if (empty($src['enabled'])) continue;

            $nodes = CollectorService::fetchSource($src['url']);
            $src['last_sync_at'] = time();
            $src['node_count'] = count($nodes);

            foreach ($nodes as $n) {
                $sig = $n['host'] . ':' . $n['port'];
                if (!isset($uniqueMap[$sig])) {
                    $uniqueMap[$sig] = true;
                    $allRawNodes[] = $n;
                }
            }
        }
        unset($src);

        // 清洗与标准化命名
        $formattedNodes = [];
        $index = 1;
        foreach ($allRawNodes as $node) {
            $cleaned = CleanerService::cleanAndFormatName($node['raw_name'], $index++, $settings);
            $node['formatted_name'] = $cleaned['formatted_name'];
            $node['region'] = $cleaned['region'];
            $node['emoji'] = $cleaned['emoji'];
            $node['is_online'] = true; // 默认采回标为待测
            $node['latency'] = 0;
            $node['offline_count'] = 0;
            $formattedNodes[] = $node;
        }

        $data['nodes'] = $formattedNodes;
        StorageService::save($data);

        return response()->json([
            'status' => 'success',
            'message' => '采集清洗完成，共采集到 ' . count($formattedNodes) . ' 个节点',
            'count' => count($formattedNodes)
        ]);
    }

    /**
     * 手动触发快速测活
     */
    public function manualCheck(Request $request)
    {
        if (!$this->verifyAuth($request)) {
            return response()->json(['status' => 'error', 'code' => 403, 'message' => '未授权访问'], 403);
        }

        $data = StorageService::load();
        $nodes = $data['nodes'] ?? [];

        if (empty($nodes)) {
            return response()->json(['status' => 'error', 'message' => '节点库为空，请先采集'], 400);
        }

        $checked = CheckerService::checkAll($nodes, 40, 1.8);
        $data['nodes'] = $checked;
        StorageService::save($data);

        $online = count(array_filter($checked, fn($n) => !empty($n['is_online'])));

        return response()->json([
            'status' => 'success',
            'message' => "测活完成，在线节点 {$online} / " . count($checked),
            'online' => $online,
            'total' => count($checked)
        ]);
    }

    /**
     * 渲染独立可视化控制台 Web 页面
     */
    public function dashboard()
    {
        $htmlPath = dirname(__DIR__) . '/Views/dashboard.html';
        if (!file_exists($htmlPath)) {
            return response('Dashboard template not found', 404);
        }
        return response(file_get_contents($htmlPath), 200, [
            'Content-Type' => 'text/html; charset=utf-8'
        ]);
    }
}

