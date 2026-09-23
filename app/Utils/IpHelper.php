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
        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return \Illuminate\Support\Facades\Cache::get($cacheKey);
        }

        try {
            static $xdbSearcherV4 = null;
            static $xdbSearcherV6 = null;
            
            $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            $xdbPath = $isV6 ? app_path('Utils/ip2region_v6.xdb') : app_path('Utils/ip2region_v4.xdb');
            
            // 兼容之前下载的 ip2region.xdb (作为 v4)
            if (!$isV6 && !file_exists($xdbPath) && file_exists(app_path('Utils/ip2region.xdb'))) {
                $xdbPath = app_path('Utils/ip2region.xdb');
            }

            if (file_exists($xdbPath)) {
                if (!class_exists('\App\Utils\Ip2RegionSearcher')) {
                    require_once app_path('Utils/Ip2RegionSearcher.php');
                }
                
                $searcher =& ${$isV6 ? 'xdbSearcherV6' : 'xdbSearcherV4'};
                
                if ($searcher === null) {
                    $header = \App\Utils\Util::loadHeaderFromFile($xdbPath);
                    $version = \App\Utils\Util::versionFromHeader($header);
                    $vIndex = \App\Utils\Util::loadVectorIndexFromFile($xdbPath);
                    $searcher = \App\Utils\Ip2RegionSearcher::newWithVectorIndex($version, $xdbPath, $vIndex);
                }
                
                try {
                    $region = $searcher->search($ip);
                } catch (\Throwable $e) {
                    // 如果文件被更新导致句柄失效，清空缓存下次重载
                    $searcher = null;
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
                        \Illuminate\Support\Facades\Cache::put($cacheKey, $location, 86400 * 30);
                        return $location;
                    }
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('IpLocation Parse Error: ' . $e->getMessage());
        }

        return '未知';
    }
}
