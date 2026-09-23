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
        $requestIp = $request->ip();

        $candidates = [];

        foreach (['X-Tianque-Real-IP', 'CF-Connecting-IPv6', 'CF-Connecting-IP', 'True-Client-IP', 'X-Real-IP'] as $header) {
            $value = trim((string)$request->header($header));
            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        $xff = (string)$request->header('X-Forwarded-For');
        if ($xff !== '') {
            foreach (explode(',', $xff) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    $candidates[] = $candidate;
                }
            }
        }

        $requestIp = $request->ip();
        if ($requestIp) {
            $candidates[] = $requestIp;
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
}
