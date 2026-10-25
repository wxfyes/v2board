<?php

namespace App\Services;

use App\Utils\IpHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class VisitorService
{
    private static $searcherInstance = null;

    /**
     * 获取高精度离线 IP 库 Searcher 实例
     */
    private static function getSearcher()
    {
        if (self::$searcherInstance !== null) {
            return self::$searcherInstance;
        }

        try {
            $candidatePaths = [
                __DIR__ . '/../Utils/ip2region.xdb',
                __DIR__ . '/../Utils/ip2region_v4.xdb',
                base_path('app/Utils/ip2region.xdb'),
            ];

            $xdbPath = null;
            foreach ($candidatePaths as $p) {
                if (file_exists($p)) {
                    $xdbPath = $p;
                    break;
                }
            }

            if (!$xdbPath) {
                return null;
            }

            if (!class_exists('\App\Utils\Ip2RegionSearcher')) {
                require_once __DIR__ . '/../Utils/Ip2RegionSearcher.php';
            }

            $vIndex = \App\Utils\Util::loadVectorIndexFromFile($xdbPath);
            $header = \App\Utils\Util::loadHeaderFromFile($xdbPath);
            $version = \App\Utils\Util::versionFromHeader($header);
            self::$searcherInstance = \App\Utils\Ip2RegionSearcher::newWithVectorIndex($version, $xdbPath, $vIndex);
            return self::$searcherInstance;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 解析 IP 归属地与国家代码
     */
    public static function parseIpLocation(string $ip): array
    {
        try {
            if (empty($ip) || $ip === '127.0.0.1' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                return ['country' => '局域网', 'country_code' => 'LAN', 'city' => '内网', 'province' => ''];
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return ['country' => '局域网', 'country_code' => 'LAN', 'city' => '局域网', 'province' => ''];
            }

            $searcher = self::getSearcher();
            if ($searcher) {
                $raw = $searcher->search($ip);
                if ($raw) {
                    $parts = explode('|', $raw);
                    $c0 = isset($parts[0]) && $parts[0] !== '0' ? trim($parts[0]) : '';
                    $c1 = isset($parts[1]) && $parts[1] !== '0' ? trim($parts[1]) : '';
                    $c2 = isset($parts[2]) && $parts[2] !== '0' ? trim($parts[2]) : '';
                    $c3 = isset($parts[3]) && $parts[3] !== '0' ? trim($parts[3]) : '';
                    $c4 = isset($parts[4]) && $parts[4] !== '0' ? trim($parts[4]) : '';

                    // 提取 ISO 国家代码 (大写)
                    $countryCode = '';
                    if (!empty($c4) && strlen($c4) <= 4) {
                        $countryCode = strtoupper($c4);
                    } elseif ($c0 === '中国' || str_contains($c0, '中国')) {
                        $countryCode = 'CN';
                    } elseif (stripos($c0, 'United States') !== false || $c0 === 'USA' || $c0 === 'US') {
                        $countryCode = 'US';
                    } elseif (stripos($c0, 'Korea') !== false || $c0 === 'KR') {
                        $countryCode = 'KR';
                    } elseif (stripos($c0, 'North Korea') !== false || $c0 === 'KP') {
                        $countryCode = 'KP';
                    } elseif (stripos($c0, 'Japan') !== false || $c0 === 'JP') {
                        $countryCode = 'JP';
                    }

                    // 规范化城市名称（对齐图一）
                    $city = '';
                    if ($countryCode === 'CN') {
                        $city = $c2 ?: $c1;
                        // 移除结尾的 "市"，保持与图一一致的 "上海"、"广州"、"佛山"、"石家庄"、"杭州" 等
                        $city = preg_replace('/市$/u', '', $city);
                    } else {
                        // 海外 IP：优先城市，若为 0 则显示州/省或 ISP/国家名
                        $city = $c2 ?: ($c1 ?: ($c3 ?: $c0));
                    }

                    return [
                        'country' => $c0 ?: '未知',
                        'country_code' => $countryCode ?: 'UN',
                        'province' => $c1,
                        'city' => $city ?: '未知',
                        'location' => trim(($c0 ?: '') . ' ' . ($c1 ?: '') . ' ' . ($city ?: '')),
                    ];
                }
            }
        } catch (\Throwable $e) {}

        return ['country' => '未知', 'country_code' => 'UN', 'city' => '未知', 'province' => '', 'location' => '未知'];
    }

    /**
     * 自动捕获并记录前台访客（内存防抖 + 单 IP 聚合）
     */
    public static function logVisit(?Request $request = null, string $url = '/'): void
    {
        try {
            if (!$request) {
                $request = request();
            }
            if (!$request) {
                return;
            }

            $ip = IpHelper::getRealIp($request);
            if (empty($ip) || $ip === '0.0.0.0') {
                return;
            }

            // 1. 内存防抖（15 秒内同 IP 的连续请求不重复写库）
            $throttleKey = 'visitor_throttle_' . md5($ip);
            if (Cache::has($throttleKey)) {
                return;
            }
            Cache::put($throttleKey, 1, 15);

            // 2. 检查数据表是否存在
            self::ensureTable();

            // 3. 解析 UA 环境
            $ua = (string)$request->userAgent();
            $clientInfo = self::parseUserAgent($ua);

            // 4. 解析 IP 地理位置
            $geo = self::parseIpLocation($ip);

            $now = time();
            $nowUrl = substr($url ?: ($request->path() ?: '/'), 0, 255);

            // 5. 采用单 IP 优雅聚合更新 (原子自增)
            $affected = DB::table('v2_visitor_log')
                ->where('ip', $ip)
                ->increment('visit_count', 1, [
                    'country' => $geo['country'],
                    'country_code' => $geo['country_code'],
                    'province' => $geo['province'],
                    'city' => $geo['city'],
                    'location' => $geo['location'],
                    'browser' => $clientInfo['browser'],
                    'os' => $clientInfo['os'],
                    'device' => $clientInfo['device'],
                    'ua' => substr($ua, 0, 500),
                    'last_url' => $nowUrl,
                    'updated_at' => $now,
                ]);

            if ($affected === 0) {
                DB::table('v2_visitor_log')->insertOrIgnore([
                    'ip' => $ip,
                    'visit_count' => 1,
                    'country' => $geo['country'],
                    'country_code' => $geo['country_code'],
                    'flag' => $geo['country_code'],
                    'province' => $geo['province'],
                    'city' => $geo['city'],
                    'location' => $geo['location'],
                    'browser' => $clientInfo['browser'],
                    'os' => $clientInfo['os'],
                    'device' => $clientInfo['device'],
                    'ua' => substr($ua, 0, 500),
                    'last_url' => $nowUrl,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('VisitorService::logVisit failed: ' . $e->getMessage());
        }
    }

    /**
     * 自动检测建表（自愈机制）
     */
    public static function ensureTable(): void
    {
        if (Cache::get('v2_visitor_log_table_ready')) {
            return;
        }

        if (!Schema::hasTable('v2_visitor_log')) {
            Schema::create('v2_visitor_log', function ($table) {
                $table->bigIncrements('id');
                $table->string('ip', 45)->unique();
                $table->integer('visit_count')->default(1);
                $table->string('country', 64)->nullable();
                $table->string('country_code', 10)->nullable();
                $table->string('flag', 16)->nullable();
                $table->string('province', 64)->nullable();
                $table->string('city', 64)->nullable();
                $table->string('location', 128)->nullable();
                $table->string('browser', 64)->nullable();
                $table->string('os', 64)->nullable();
                $table->string('device', 32)->nullable();
                $table->text('ua')->nullable();
                $table->string('last_url', 255)->nullable();
                $table->integer('created_at')->index();
                $table->integer('updated_at')->index();
            });
        }

        Cache::put('v2_visitor_log_table_ready', true, 86400 * 30);
    }

    /**
     * 轻量级 User-Agent 客户端特征解析引擎
     */
    public static function parseUserAgent(string $ua): array
    {
        if (empty($ua)) {
            return [
                'browser' => 'Unknown',
                'os' => 'Unknown',
                'device' => 'Desktop',
            ];
        }

        $os = 'Unknown';
        if (preg_match('/Windows NT 10/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Windows/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Macintosh|Mac OS X/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/HarmonyOS/i', $ua)) {
            $os = 'HarmonyOS';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        $device = 'Desktop';
        if (preg_match('/iPad|Tablet|PlayBook/i', $ua)) {
            $device = 'Tablet';
        } elseif (preg_match('/Mobile|Android|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua)) {
            $device = 'Mobile';
        } elseif (preg_match('/bot|crawl|spider|slurp|facebookexternalhit/i', $ua)) {
            $device = 'Bot';
        }

        $browser = 'Chrome';
        if (preg_match('/MicroMessenger/i', $ua)) {
            $browser = 'WeChat';
        } elseif (preg_match('/Alipay/i', $ua)) {
            $browser = 'Alipay';
        } elseif (preg_match('/QQBrowser|MQQBrowser/i', $ua)) {
            $browser = 'QQ';
        } elseif (preg_match('/Edg/i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/OPR|Opera/i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/Firefox/i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Brave/i', $ua)) {
            $browser = 'Brave';
        } elseif (preg_match('/Vivaldi/i', $ua)) {
            $browser = 'Vivaldi';
        } elseif (preg_match('/UCBrowser|UCWEB/i', $ua)) {
            $browser = 'UC';
        } elseif (preg_match('/360SE|360EE/i', $ua)) {
            $browser = '360';
        } elseif (preg_match('/Chrome/i', $ua) && !preg_match('/Edg/i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari/i', $ua) && !preg_match('/Chrome|CriOS/i', $ua)) {
            $browser = 'Safari';
        }

        return [
            'browser' => $browser,
            'os' => $os,
            'device' => $device,
        ];
    }
}
