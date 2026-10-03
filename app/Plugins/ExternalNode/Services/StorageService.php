<?php

namespace App\Plugins\ExternalNode\Services;

class StorageService
{
    /**
     * 数据文件物理存储路径
     */
    public static function getFilePath(): string
    {
        return storage_path('app/external_nodes.json');
    }

    /**
     * 加载数据（具备不死自愈特性）
     * 无论文件缺失、被误删、权限异常还是 JSON 损坏，永不抛异常，毫秒级自愈
     */
    public static function load(): array
    {
        $path = self::getFilePath();

        if (!file_exists($path)) {
            return self::healAndSaveDefault();
        }

        $content = @file_get_contents($path);
        if (empty($content)) {
            return self::healAndSaveDefault();
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['version'])) {
            return self::healAndSaveDefault();
        }

        // 🛡️ 安全通信密钥自动补全机制
        if (empty($data['settings']['admin_secret_key'])) {
            $data['settings']['admin_secret_key'] = bin2hex(random_bytes(16));
            self::save($data);
        }

        return $data;
    }

    /**
     * 原子级安全写入数据（排他锁 + 临时文件原子替换，杜绝并发截断）
     */
    public static function save(array $data): bool
    {
        $path = self::getFilePath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $data['updated_at'] = time();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        $tempPath = $path . '.' . uniqid('tmp_', true);

        // 写入临时文件并落盘
        if (@file_put_contents($tempPath, $json, LOCK_EX) === false) {
            return false;
        }

        // Windows与Linux兼容的原子重命名
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @unlink($path);
        }

        return @rename($tempPath, $path);
    }

    /**
     * 触发自愈并写入默认初始骨架
     */
    public static function healAndSaveDefault(): array
    {
        $default = [
            'version' => '1.0.0',
            'updated_at' => time(),
            'settings' => [
                'enable' => true,
                'admin_secret_key' => bin2hex(random_bytes(16)), // 32位独立管理安全通信密钥
                'deduct_traffic_mb' => 1024,      // 每次拉取扣除流量 (MB)
                'max_pull_per_day' => 10,         // 每日拉取上限次数 (0表示不限制)
                'node_prefix' => '⚡ [免费体验]',    // 节点统一人性化前缀
                'node_ad_suffix' => ' - 升级VIP享4K', // 广告/引导升级后缀
                'max_nodes_per_sub' => 15,        // 单次下发最大节点数 (防被一次性拖库)
                'auto_clean_offline_hours' => 12, // 连续离线超时自动清除 (小时)
            ],
            'sources' => [
                [
                    'id' => 1,
                    'name' => 'GitHub-Node-Pool-A',
                    'url' => 'https://raw.githubusercontent.com/freefq/free/master/v2',
                    'enabled' => true,
                    'last_sync_at' => 0,
                    'node_count' => 0
                ],
                [
                    'id' => 2,
                    'name' => 'GitHub-Node-Pool-B',
                    'url' => 'https://raw.githubusercontent.com/mfuu/v2ray/master/clash.yaml',
                    'enabled' => true,
                    'last_sync_at' => 0,
                    'node_count' => 0
                ]
            ],
            'nodes' => []
        ];

        self::save($default);
        return $default;
    }
}
