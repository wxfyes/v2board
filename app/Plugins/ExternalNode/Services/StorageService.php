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

        // 🛡️ 安全通信密钥与独立小云朵域名设置自动补全机制
        $needSave = false;
        if (empty($data['settings']['admin_secret_key'])) {
            $data['settings']['admin_secret_key'] = bin2hex(random_bytes(16));
            $needSave = true;
        }
        if (!isset($data['settings']['free_sub_domain'])) {
            $data['settings']['free_sub_domain'] = '';
            $needSave = true;
        }
        if (!isset($data['settings']['free_sub_path'])) {
            $data['settings']['free_sub_path'] = 'api/v1/free/subscribe';
            $needSave = true;
        }
        if (!isset($data['settings']['free_plan_ids'])) {
            $data['settings']['free_plan_ids'] = '1';
            $needSave = true;
        }

        // 增量自动补充官方精选源（不覆盖用户已添加的自定义源）
        $existingUrls = array_column($data['sources'] ?? [], 'url');
        foreach (self::getDefaultSources() as $ds) {
            if (!in_array($ds['url'], $existingUrls)) {
                $ds['id'] = count($data['sources']) + 1;
                $data['sources'][] = $ds;
                $needSave = true;
            }
        }

        if ($needSave) {
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
                'free_sub_domain' => '',          // 独立引流域名 (支持 Cloudflare 小云朵，留空则使用当前访问域名)
                'free_sub_path' => 'api/v1/free/subscribe', // 自定义免费下发路径 (非写死，可任意自定义防特征阻断)
                'free_plan_ids' => '1',           // 关联的免费套餐 Plan ID (多个逗号隔开，命中则前端下发独立引流订阅)
                'deduct_traffic_mb' => 1024,      // 每次拉取扣除流量 (MB)
                'max_nodes_per_sub' => 15,        // 单次下发最大节点数 (防被一次性拖库)
                'auto_clean_offline_hours' => 12, // 连续离线超时自动清除 (小时)
                'node_prefix' => '⚡ [免费体验]',    // 节点统一人性化前缀
                'node_ad_suffix' => ' - 升级VIP享4K', // 广告/引导升级后缀
            ],
            'sources' => self::getDefaultSources(),
            'nodes' => []
        ];

        self::save($default);
        return $default;
    }

    /**
     * 官方全网优质订阅源清单（涵盖 8 大高可用每日轮换精选池）
     */
    public static function getDefaultSources(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'GitHub-Daily-Ermaozi (114KB高质池)',
                'url' => 'https://raw.githubusercontent.com/ermaozi/get_subscribe/main/subscribe/v2ray.txt',
                'enabled' => true,
                'last_sync_at' => 0,
                'node_count' => 0
            ],
            [
                'id' => 2,
                'name' => 'GitHub-Daily-Anaer (169KB优质Clash)',
                'url' => 'https://raw.githubusercontent.com/anaer/Sub/main/clash.yaml',
                'enabled' => true,
                'last_sync_at' => 0,
                'node_count' => 0
            ],
            [
                'id' => 3,
                'name' => 'GitHub-Daily-Free18 (43KB轮换精选)',
                'url' => 'https://raw.githubusercontent.com/free18/v2ray/master/v.txt',
                'enabled' => true,
                'last_sync_at' => 0,
                'node_count' => 0
            ],
            [
                'id' => 4,
                'name' => 'GitHub-Daily-Pawdroid (高可用精选)',
                'url' => 'https://raw.githubusercontent.com/Pawdroid/Free-servers/main/sub',
                'enabled' => true,
                'last_sync_at' => 0,
                'node_count' => 0
            ],
            [
                'id' => 5,
                'name' => 'GitHub-Daily-Ripao (日抛高带宽)',
                'url' => 'https://raw.githubusercontent.com/ripaojiedian/freenode/main/sub',
                'enabled' => true,
                'last_sync_at' => 0,
                'node_count' => 0
            ],
            [
                'id' => 6,
                'name' => 'GitHub-Daily-V2rayLinks (自建直连池)',
                'url' => 'https://raw.githubusercontent.com/v2ray-links/v2ray-free/master/v2ray',
                'enabled' => true,
                'last_sync_at' => 0,
                'node_count' => 0
            ],
            [
                'id' => 7,
                'name' => 'GitHub-Daily-Zhuhaiuk (通用V2Ray)',
                'url' => 'https://raw.githubusercontent.com/zhuhaiuk/free-nodes/main/nodes.txt',
                'enabled' => true,
                'last_sync_at' => 0,
                'node_count' => 0
            ],
            [
                'id' => 8,
                'name' => 'GitHub-Daily-Zhuhaiuk (Clash配置)',
                'url' => 'https://raw.githubusercontent.com/zhuhaiuk/free-nodes/main/clash_config.yaml',
                'enabled' => true,
                'last_sync_at' => 0,
                'node_count' => 0
            ]
        ];
    }

    /**
     * 智能分流订阅地址：若用户属于免费套餐，自动返回自定义的【CF小云朵独立引流域名 + 自定义路径】
     */
    public static function getSmartSubscribeUrl(array $user, string $originalUrl): string
    {
        $data = self::load();
        $settings = $data['settings'] ?? [];

        if (empty($settings['enable'])) {
            return $originalUrl;
        }

        $freePlanIdsStr = (string)($settings['free_plan_ids'] ?? '');
        $freePlanIds = array_filter(array_map('trim', explode(',', $freePlanIdsStr)));

        $userPlanId = (string)($user['plan_id'] ?? '');

        // 判定用户是否命中免费套餐（或者指定了免费套餐ID）
        $isFreeUser = false;
        if (!empty($freePlanIds) && in_array($userPlanId, $freePlanIds)) {
            $isFreeUser = true;
        }

        if (!$isFreeUser) {
            return $originalUrl;
        }

        // 构造自定义独立引流域名与自定义路径 (非写死)
        $customDomain = trim($settings['free_sub_domain'] ?? '');
        $customPath = ltrim(trim($settings['free_sub_path'] ?? 'api/v1/free/subscribe'), '/');

        if (empty($customDomain)) {
            $parsed = parse_url($originalUrl);
            $scheme = $parsed['scheme'] ?? 'https';
            $host = $parsed['host'] ?? '';
            $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
            $customDomain = "{$scheme}://{$host}{$port}";
        } else {
            $customDomain = rtrim($customDomain, '/');
            if (!preg_match('/^https?:\/\//i', $customDomain)) {
                $customDomain = 'https://' . $customDomain;
            }
        }

        $token = $user['token'] ?? '';
        return "{$customDomain}/{$customPath}?token={$token}";
    }
}

