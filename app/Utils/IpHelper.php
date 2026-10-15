<?php

namespace App\Utils;

use Illuminate\Http\Request;

class IpHelper
{
    private const CLOUDFLARE_RANGES = [
        '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '104.16.0.0/13',   '104.24.0.0/14',   '108.162.192.0/18',
        '131.0.72.0/22',   '141.101.64.0/18', '162.158.0.0/15',
        '172.64.0.0/13',   '173.245.48.0/20', '188.114.96.0/20',
        '190.93.240.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '2400:cb00::/32',  '2606:4700::/32',  '2803:f800::/32',
        '2405:b500::/32',  '2405:8100::/32',  '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public static function getRealIp(Request $request): string
    {
        $candidates = [];
        
        // 1. Laravel resolved IP (accurate if TrustProxies is set to *)
        $requestIp = $request->ip();
        if ($requestIp) {
            $candidates[] = $requestIp;
        }

        // 2. X-Forwarded-For explicitly parsed 
        $xff = (string)$request->header('X-Forwarded-For');
        if ($xff !== '') {
            foreach (explode(',', $xff) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    $candidates[] = $candidate;
                }
            }
        }

        // 3. Other headers (X-Real-IP first, CF-Connecting-IP last to avoid CF override)
        foreach (['X-Real-IP', 'X-Tianque-Real-IP', 'True-Client-IP', 'CF-Connecting-IPv6', 'CF-Connecting-IP'] as $header) {
            $value = trim((string)$request->header($header));
            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) && !self::isCloudflareIp($candidate)) {
                return $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return $requestIp ?: '0.0.0.0';
    }

    public static function isCloudflareIp(string $ip): bool
    {
        foreach (self::CLOUDFLARE_RANGES as $range) {
            if (self::ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public static function ipInRange(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        [$subnet, $bits] = explode('/', $range);
        $bits = (int)$bits;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($bits < 0 || $bits > 32) return false;
            $mask = -1 << (32 - $bits);
            return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($bits < 0 || $bits > 128) return false;
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);
            if ($ipBin === false || $subnetBin === false) return false;

            $bytes = intdiv($bits, 8);
            $remainder = $bits % 8;

            if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
                return false;
            }

            if ($remainder === 0) {
                return true;
            }

            $mask = (0xff << (8 - $remainder)) & 0xff;
            return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
        }

        return false;
    }

    public static function ipLocation(string $ip): string
    {
        if (empty($ip) || $ip === '127.0.0.1' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '局域网';
        }

        $cacheKey = "ip_loc_" . md5($ip);
        $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (class_exists('\Illuminate\Support\Facades\Cache') && \Illuminate\Support\Facades\Cache::getFacadeRoot()) {
            if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                $cached = (string)\Illuminate\Support\Facades\Cache::get($cacheKey);
                // 历史旧脏缓存自动穿透与洗涤：
                // 如果是 IPv6 且旧缓存包含 -CN（旧 ip2region 标志）或旧误报数据，直接废弃并重新用纯真引擎计算并覆写！
                $isDirtyV6Cache = $isV6 && (
                    str_ends_with($cached, '-CN') ||
                    str_contains($cached, '北京市-电信') ||
                    str_contains($cached, '北京-电信')
                );

                if (!$isDirtyV6Cache && !empty($cached) && $cached !== '未知' && $cached !== '未知位置' && $cached !== '未知地理位置') {
                    return $cached;
                }
            }
        }

        try {
            // 针对 IPv6：优先使用高精度纯离线纯真 ZXInc 引擎
            if ($isV6) {
                $geo = self::parseGeo($ip);
                if ($geo['is_lan']) {
                    return '局域网';
                }
                $locParts = [];
                if (!empty($geo['country']) && $geo['country'] !== '未知') $locParts[] = $geo['country'];
                if (!empty($geo['province'])) $locParts[] = $geo['province'];
                if (!empty($geo['city'])) $locParts[] = $geo['city'];
                if (!empty($geo['isp'])) $locParts[] = $geo['isp'];

                $location = !empty($locParts) ? implode('-', array_unique($locParts)) : '';
                if ($location) {
                    if (class_exists('\Illuminate\Support\Facades\Cache') && \Illuminate\Support\Facades\Cache::getFacadeRoot()) {
                        \Illuminate\Support\Facades\Cache::put($cacheKey, $location, 86400 * 30);
                    }
                    return $location;
                }
            }

            static $xdbSearcherV4 = null;
            static $xdbSearcherV6 = null;
            
            $candidatePaths = $isV6 ? [
                function_exists('app_path') ? app_path('Utils/ip2region_v6.xdb') : null,
                function_exists('base_path') ? base_path('app/Utils/ip2region_v6.xdb') : null,
                __DIR__ . '/ip2region_v6.xdb',
                dirname(__DIR__) . '/Utils/ip2region_v6.xdb',
            ] : [
                function_exists('app_path') ? app_path('Utils/ip2region_v4.xdb') : null,
                function_exists('base_path') ? base_path('app/Utils/ip2region_v4.xdb') : null,
                __DIR__ . '/ip2region_v4.xdb',
                dirname(__DIR__) . '/Utils/ip2region_v4.xdb',
                function_exists('app_path') ? app_path('Utils/ip2region.xdb') : null,
                function_exists('base_path') ? base_path('app/Utils/ip2region.xdb') : null,
                __DIR__ . '/ip2region.xdb',
                dirname(__DIR__) . '/Utils/ip2region.xdb',
            ];
            
            $xdbPath = null;
            foreach ($candidatePaths as $p) {
                if ($p && file_exists($p)) {
                    $xdbPath = $p;
                    break;
                }
            }

            if ($xdbPath) {
                if (!class_exists('\App\Utils\Ip2RegionSearcher')) {
                    $searcherFile = function_exists('app_path') ? app_path('Utils/Ip2RegionSearcher.php') : __DIR__ . '/Ip2RegionSearcher.php';
                    if (file_exists($searcherFile)) {
                        require_once $searcherFile;
                    }
                }
                
                if ($isV6) {
                    if ($xdbSearcherV6 === null) {
                        $header = \App\Utils\Util::loadHeaderFromFile($xdbPath);
                        $version = \App\Utils\Util::versionFromHeader($header);
                        $vIndex = \App\Utils\Util::loadVectorIndexFromFile($xdbPath);
                        $xdbSearcherV6 = \App\Utils\Ip2RegionSearcher::newWithVectorIndex($version, $xdbPath, $vIndex);
                    }
                    $searcher = $xdbSearcherV6;
                } else {
                    if ($xdbSearcherV4 === null) {
                        $header = \App\Utils\Util::loadHeaderFromFile($xdbPath);
                        $version = \App\Utils\Util::versionFromHeader($header);
                        $vIndex = \App\Utils\Util::loadVectorIndexFromFile($xdbPath);
                        $xdbSearcherV4 = \App\Utils\Ip2RegionSearcher::newWithVectorIndex($version, $xdbPath, $vIndex);
                    }
                    $searcher = $xdbSearcherV4;
                }
                
                try {
                    $region = $searcher->search($ip);
                } catch (\Throwable $e) {
                    // 如果文件被更新导致句柄失效，清空缓存下次重载
                    if ($isV6) {
                        $xdbSearcherV6 = null;
                    } else {
                        $xdbSearcherV4 = null;
                    }
                    throw $e;
                }
                if ($region) {
                    // ip2region format: 国家|区域|省份|城市|ISP
                    $parts = explode('|', $region);
                    $locationParts = [];
                    foreach ($parts as $part) {
                        if ($part !== '0' && !empty($part)) {
                            $locationParts[] = $part;
                        }
                    }
                    $location = implode('-', array_unique($locationParts));
                    if ($location) {
                        if (class_exists('\Illuminate\Support\Facades\Cache') && \Illuminate\Support\Facades\Cache::getFacadeRoot()) {
                            \Illuminate\Support\Facades\Cache::put($cacheKey, $location, 86400 * 30);
                        }
                        return $location;
                    }
                }
            }
        } catch (\Throwable $e) {
            if (class_exists('\Illuminate\Support\Facades\Log')) {
                \Illuminate\Support\Facades\Log::error('IpLocation Parse Error: ' . $e->getMessage());
            }
        }

        return '未知';
    }

    /**
     * 结构化解析 IP 地理位置信息 (极速离线内存查询)
     */
    public static function parseGeo(string $ip): array
    {
        $default = [
            'ip' => $ip,
            'country' => '局域网/未知',
            'region' => '未知',
            'province' => '',
            'city' => '',
            'isp' => '',
            'is_china' => false,
            'is_lan' => true,
        ];

        if (empty($ip) || $ip === '127.0.0.1' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return $default;
        }

        // 检查是否为私有保留内网 IP
        if (
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            return [
                'ip' => $ip,
                'country' => '局域网',
                'region' => '内网',
                'province' => '',
                'city' => '',
                'isp' => '局域网',
                'is_china' => false,
                'is_lan' => true,
            ];
        }

        try {
            $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

            // 针对 IPv6：优先使用高精度纯离线纯真 ZXInc 数据库
            if ($isV6) {
                $v6Geo = self::parseIPv6Geo($ip);
                if ($v6Geo !== null) {
                    return $v6Geo;
                }
            }

            static $searcherV4 = null;
            static $searcherV6 = null;
            $candidatePaths = $isV6 ? [
                function_exists('app_path') ? app_path('Utils/ip2region_v6.xdb') : null,
                function_exists('base_path') ? base_path('app/Utils/ip2region_v6.xdb') : null,
                __DIR__ . '/ip2region_v6.xdb',
                dirname(__DIR__) . '/Utils/ip2region_v6.xdb',
            ] : [
                function_exists('app_path') ? app_path('Utils/ip2region_v4.xdb') : null,
                function_exists('base_path') ? base_path('app/Utils/ip2region_v4.xdb') : null,
                __DIR__ . '/ip2region_v4.xdb',
                dirname(__DIR__) . '/Utils/ip2region_v4.xdb',
                function_exists('app_path') ? app_path('Utils/ip2region.xdb') : null,
                function_exists('base_path') ? base_path('app/Utils/ip2region.xdb') : null,
                __DIR__ . '/ip2region.xdb',
                dirname(__DIR__) . '/Utils/ip2region.xdb',
            ];

            $xdbPath = null;
            foreach ($candidatePaths as $p) {
                if ($p && file_exists($p)) {
                    $xdbPath = $p;
                    break;
                }
            }

            if (!$xdbPath) {
                return $default;
            }

            if (!class_exists('\App\Utils\Ip2RegionSearcher')) {
                $searcherFile = function_exists('app_path') ? app_path('Utils/Ip2RegionSearcher.php') : __DIR__ . '/Ip2RegionSearcher.php';
                if (file_exists($searcherFile)) {
                    require_once $searcherFile;
                }
            }

            if ($isV6) {
                if ($searcherV6 === null) {
                    $header = \App\Utils\Util::loadHeaderFromFile($xdbPath);
                    $version = \App\Utils\Util::versionFromHeader($header);
                    $vIndex = \App\Utils\Util::loadVectorIndexFromFile($xdbPath);
                    $searcherV6 = \App\Utils\Ip2RegionSearcher::newWithVectorIndex($version, $xdbPath, $vIndex);
                }
                $searcher = $searcherV6;
            } else {
                if ($searcherV4 === null) {
                    $header = \App\Utils\Util::loadHeaderFromFile($xdbPath);
                    $version = \App\Utils\Util::versionFromHeader($header);
                    $vIndex = \App\Utils\Util::loadVectorIndexFromFile($xdbPath);
                    $searcherV4 = \App\Utils\Ip2RegionSearcher::newWithVectorIndex($version, $xdbPath, $vIndex);
                }
                $searcher = $searcherV4;
            }

            $rawRegion = $searcher->search($ip);
            if (!$rawRegion) {
                return $default;
            }

            $parts = explode('|', $rawRegion);
            $country = isset($parts[0]) && $parts[0] !== '0' ? trim($parts[0]) : '';
            
            // 智能识别当前 xdb 的格式：
            // 格式 A（当前本地库真实格式）：国家|省份|城市|ISP|国家简码 (例如: 中国|广东省|广州市|电信|CN)
            // 格式 B（经典格式）：国家|区域|省份|城市|ISP (例如: 中国|0|广东省|广州市|电信)
            $p1 = isset($parts[1]) && $parts[1] !== '0' ? trim($parts[1]) : '';
            $p2 = isset($parts[2]) && $parts[2] !== '0' ? trim($parts[2]) : '';
            $p3 = isset($parts[3]) && $parts[3] !== '0' ? trim($parts[3]) : '';
            $p4 = isset($parts[4]) && $parts[4] !== '0' ? trim($parts[4]) : '';

            $isLan = ($country === '内网IP' || $p1 === '内网IP' || $p2 === '内网IP' || $p3 === '内网IP');
            if ($isLan || $country === '局域网') {
                return [
                    'ip' => $ip,
                    'country' => '局域网',
                    'region' => '内网',
                    'province' => '',
                    'city' => '',
                    'isp' => '局域网',
                    'is_china' => false,
                    'is_lan' => true,
                ];
            }

            $isChina = ($country === '中国');
            $province = '';
            $city = '';
            $isp = '';
            $region = '';

            if ($isChina) {
                // 如果 p1 已经是省份（如 "广东省"、"北京市"、"浙江"）
                if (
                    str_contains($p1, '省') || str_contains($p1, '市') || str_contains($p1, '自治区')
                    || in_array($p1, ['北京', '天津', '河北', '山西', '内蒙古', '辽宁', '吉林', '黑龙江', '上海', '江苏', '浙江', '安徽', '福建', '江西', '山东', '河南', '湖北', '湖南', '广东', '广西', '海南', '重庆', '四川', '贵州', '云南', '西藏', '陕西', '甘肃', '青海', '宁夏', '新疆', '香港', '澳门', '台湾'], true)
                ) {
                    $province = self::normalizeProvince($p1);
                    $city = $p2;
                    $isp = $p3;
                } else {
                    // 格式 B：p1 是区域（如 华南 / 0），p2 是省份
                    $province = self::normalizeProvince($p2);
                    $city = $p3;
                    $isp = $p4;
                }
                $region = self::getChinaRegionByProvince($province);
            } else {
                $province = $p1 ?: $p2;
                $city = $p2 ?: $p3;
                $isp = $p3 ?: $p4;
                $region = $country ?: '海外';
            }

            return [
                'ip' => $ip,
                'country' => $country ?: '未知',
                'region' => $region ?: '其他地区',
                'province' => $province,
                'city' => $city,
                'isp' => $isp,
                'is_china' => $isChina,
                'is_lan' => false,
            ];
        } catch (\Throwable $e) {
            if (class_exists('\Illuminate\Support\Facades\Log')) {
                \Illuminate\Support\Facades\Log::error('IpHelper::parseGeo Error: ' . $e->getMessage());
            }
            return $default;
        }
    }

    /**
     * 规范化中国省份名称（严格匹配 ECharts 中国地图官方命名）
     */
    public static function normalizeProvince(string $province): string
    {
        $province = trim($province);
        if (empty($province) || $province === '0') {
            return '';
        }

        $special = [
            '北京' => '北京市',
            '天津' => '天津市',
            '上海' => '上海市',
            '重庆' => '重庆市',
            '内蒙古' => '内蒙古自治区',
            '广西' => '广西壮族自治区',
            '西藏' => '西藏自治区',
            '宁夏' => '宁夏回族自治区',
            '新疆' => '新疆维吾尔自治区',
            '香港' => '香港特别行政区',
            '澳门' => '澳门特别行政区',
            '台湾' => '台湾省',
        ];

        foreach ($special as $key => $val) {
            if ($province === $key || $province === $val || str_starts_with($province, $key)) {
                return $val;
            }
        }

        // 如果已有常规后缀
        if (str_ends_with($province, '省') || str_ends_with($province, '市') || str_ends_with($province, '自治区') || str_ends_with($province, '特别行政区')) {
            return $province;
        }

        return $province . '省';
    }

    /**
     * 获取省份对应的中国七大地理分区
     */
    public static function getChinaRegionByProvince(string $province): string
    {
        $regions = [
            '华北地区' => ['北京市', '天津市', '河北省', '山西省', '内蒙古自治区'],
            '东北地区' => ['辽宁省', '吉林省', '黑龙江省'],
            '华东地区' => ['上海市', '江苏省', '浙江省', '安徽省', '福建省', '江西省', '山东省', '台湾省'],
            '华中地区' => ['河南省', '湖北省', '湖南省'],
            '华南地区' => ['广东省', '广西壮族自治区', '海南省', '香港特别行政区', '澳门特别行政区'],
            '西南地区' => ['重庆市', '四川省', '贵州省', '云南省', '西藏自治区'],
            '西北地区' => ['陕西省', '甘肃省', '青海省', '宁夏回族自治区', '新疆维吾尔自治区'],
        ];

        foreach ($regions as $region => $provinces) {
            if (in_array($province, $provinces, true)) {
                return $region;
            }
        }

        return '其他地区';
    }

    /**
     * 纯真 ZXInc 纯离线 IPv6 解析与标准化
     */
    private static function parseIPv6Geo(string $ip): ?array
    {
        try {
            if (!class_exists('\App\Utils\IPv6WrySearcher')) {
                $searcherFile = function_exists('app_path') ? app_path('Utils/IPv6WrySearcher.php') : __DIR__ . '/IPv6WrySearcher.php';
                if (file_exists($searcherFile)) {
                    require_once $searcherFile;
                }
            }

            if (!class_exists('\App\Utils\IPv6WrySearcher')) {
                return null;
            }

            $rawText = \App\Utils\IPv6WrySearcher::search($ip);
            if (empty($rawText)) {
                return null;
            }

            $clean = preg_replace('/[\t\s]+/', ' ', trim($rawText));
            if (str_contains($clean, '局域网') || str_contains($clean, '本地') || str_contains($clean, '保留地址')) {
                return [
                    'ip' => $ip,
                    'country' => '局域网',
                    'region' => '内网',
                    'province' => '',
                    'city' => '',
                    'isp' => '局域网',
                    'is_china' => false,
                    'is_lan' => true,
                ];
            }

            $isChina = false;
            $country = '海外';
            $province = '';
            $city = '';
            $isp = '';

            if (
                str_starts_with($clean, '中国') ||
                str_contains($clean, '省') ||
                str_contains($clean, '自治区') ||
                str_contains($clean, '特别行政区')
            ) {
                $isChina = true;
                $country = '中国';
            }

            $provinces = [
                '北京', '天津', '河北', '山西', '内蒙古', '辽宁', '吉林', '黑龙江',
                '上海', '江苏', '浙江', '安徽', '福建', '江西', '山东', '河南',
                '湖北', '湖南', '广东', '广西', '海南', '重庆', '四川', '贵州',
                '云南', '西藏', '陕西', '甘肃', '青海', '宁夏', '新疆', '香港', '澳门', '台湾'
            ];

            foreach ($provinces as $p) {
                if (str_contains($clean, $p)) {
                    $isChina = true;
                    $country = '中国';
                    $province = self::normalizeProvince($p);
                    break;
                }
            }

            // ISP 提取
            if (str_contains($clean, '电信')) $isp = '中国电信';
            elseif (str_contains($clean, '移动')) $isp = '中国移动';
            elseif (str_contains($clean, '联通')) $isp = '中国联通';
            elseif (str_contains($clean, '广电')) $isp = '中国广电';
            elseif (str_contains($clean, '教育网') || str_contains($clean, 'CERNET') || str_contains($clean, '大学')) $isp = '教育网';
            elseif (str_contains($clean, '阿里云') || str_contains($clean, 'Alibaba')) $isp = '阿里云';
            elseif (str_contains($clean, '腾讯云') || str_contains($clean, 'Tencent')) $isp = '腾讯云';
            elseif (str_contains($clean, '华为云') || str_contains($clean, 'Huawei')) $isp = '华为云';
            elseif (str_contains($clean, 'Google')) $isp = 'Google';
            elseif (str_contains($clean, 'Cloudflare')) $isp = 'Cloudflare';
            elseif (str_contains($clean, 'Microsoft')) $isp = 'Microsoft';

            // 地级市/区县提取
            $tokens = explode(' ', $clean);
            foreach ($tokens as $token) {
                if (
                    empty($token) ||
                    $token === '中国' ||
                    $token === '全球' ||
                    str_contains($token, '电信') ||
                    str_contains($token, '移动') ||
                    str_contains($token, '联通') ||
                    str_contains($token, '广电') ||
                    str_contains($token, '公众宽带') ||
                    str_contains($token, 'Anycast')
                ) {
                    continue;
                }
                if (!empty($province)) {
                    $shortP = mb_substr($province, 0, 2);
                    if (str_contains($token, $shortP)) {
                        $rem = str_replace([$province, $shortP . '省', $shortP . '市', $shortP . '区', $shortP . '自治区', $shortP], '', $token);
                        if (!empty($rem)) {
                            $city = $rem;
                            break;
                        }
                        continue;
                    }
                }
                if (preg_match('/(市|地区|州|盟|县|区)$/u', $token)) {
                    $city = $token;
                    break;
                }
            }

            $region = $isChina ? self::getChinaRegionByProvince($province) : ($country ?: '海外');

            return [
                'ip' => $ip,
                'country' => $country,
                'region' => $region ?: '其他地区',
                'province' => $province,
                'city' => $city,
                'isp' => $isp,
                'is_china' => $isChina,
                'is_lan' => false,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
