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

    // 常见国家英文/简码到标准中文的映射
    private static array $countryMap = [
        'UNITED STATES' => '美国', 'USA' => '美国', 'US' => '美国',
        'FRANCE' => '法国', 'FR' => '法国',
        'UNITED KINGDOM' => '英国', 'UK' => '英国', 'GREAT BRITAIN' => '英国', 'ENGLAND' => '英国', 'GB' => '英国',
        'JAPAN' => '日本', 'JP' => '日本',
        'KOREA' => '韩国', 'SOUTH KOREA' => '韩国', 'REPUBLIC OF KOREA' => '韩国', 'KR' => '韩国',
        'NORTH KOREA' => '朝鲜', 'KP' => '朝鲜',
        'GERMANY' => '德国', 'DE' => '德国',
        'RUSSIA' => '俄罗斯', 'RUSSIAN FEDERATION' => '俄罗斯', 'RU' => '俄罗斯',
        'SINGAPORE' => '新加坡', 'SG' => '新加坡',
        'CANADA' => '加拿大', 'CA' => '加拿大',
        'AUSTRALIA' => '澳大利亚', 'AU' => '澳大利亚',
        'NETHERLANDS' => '荷兰', 'NL' => '荷兰',
        'NORWAY' => '挪威', 'NO' => '挪威',
        'SWEDEN' => '瑞典', 'SE' => '瑞典',
        'SWITZERLAND' => '瑞士', 'CH' => '瑞士',
        'ITALY' => '意大利', 'IT' => '意大利',
        'SPAIN' => '西班牙', 'ES' => '西班牙',
        'BRAZIL' => '巴西', 'BR' => '巴西',
        'INDIA' => '印度', 'IN' => '印度',
        'VIETNAM' => '越南', 'VN' => '越南',
        'THAILAND' => '泰国', 'TH' => '泰国',
        'MALAYSIA' => '马来西亚', 'MY' => '马来西亚',
        'INDONESIA' => '印尼', 'ID' => '印尼',
        'PHILIPPINES' => '菲律宾', 'PH' => '菲律宾',
        'IRELAND' => '爱尔兰', 'IE' => '爱尔兰',
        'FINLAND' => '芬兰', 'FI' => '芬兰',
        'POLAND' => '波兰', 'PL' => '波兰',
        'TURKEY' => '土耳其', 'TR' => '土耳其',
        'UKRAINE' => '乌克兰', 'UA' => '乌克兰',
        'MEXICO' => '墨西哥', 'MX' => '墨西哥',
        'HONG KONG' => '香港', 'HK' => '香港',
        'MACAU' => '澳门', 'MO' => '澳门',
        'TAIWAN' => '台湾', 'TW' => '台湾',
        'CHINA' => '中国', 'CN' => '中国',
    ];

    // 常见国外主要城市与地区英文到中文映射
    private static array $cityMap = [
        'LOS ANGELES' => '洛杉矶',
        'NEW YORK' => '纽约',
        'NEW YORK CITY' => '纽约',
        'SAN JOSE' => '圣何塞',
        'SAN FRANCISCO' => '旧金山',
        'SEATTLE' => '西雅图',
        'CHICAGO' => '芝加哥',
        'DALLAS' => '达拉斯',
        'WASHINGTON' => '华盛顿',
        'SILICON VALLEY' => '硅谷',
        'VIRGINIA BEACH' => '弗吉尼亚海滩',
        'VIRGINIA' => '弗吉尼亚',
        'MOSES LAKE' => '摩西湖',
        'CALIFORNIA' => '加利福尼亚',
        'PARIS' => '巴黎',
        'LONDON' => '伦敦',
        'ENGLAND' => '英格兰',
        'TOKYO' => '东京',
        'OSAKA' => '大阪',
        'SEOUL' => '首尔',
        'OSLO' => '奥斯陆',
        'FRANKFURT' => '法兰克福',
        'FRANKFURT AM MAIN' => '法兰克福',
        'BERLIN' => '柏林',
        'AMSTERDAM' => '阿姆斯特丹',
        'SYDNEY' => '悉尼',
        'MELBOURNE' => '墨尔本',
        'TORONTO' => '多伦多',
        'VANCOUVER' => '温哥华',
        'MOSCOW' => '莫斯科',
        'SAINT PETERSBURG' => '圣彼得堡',
        'SINGAPORE' => '新加坡',
        'BANGKOK' => '曼谷',
        'KUALA LUMPUR' => '吉隆坡',
        'MANILA' => '马尼拉',
        'HANOI' => '河内',
        'HO CHI MINH CITY' => '胡志明市',
        'TAIPEI' => '台北',
        'HONG KONG' => '香港',
        'MACAU' => '澳门',
    ];

    /**
     * 解析 IP 归属地与国家代码（支持 IPv4/IPv6、中文规整化、省份+城市展示）
     */
    public static function parseIpLocation(string $ip): array
    {
        try {
            if (empty($ip) || $ip === '127.0.0.1' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                return ['country' => '局域网', 'country_code' => 'LAN', 'city' => '内网', 'province' => '', 'location' => '内网'];
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return ['country' => '局域网', 'country_code' => 'LAN', 'city' => '局域网', 'province' => '', 'location' => '局域网'];
            }

            $isV6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

            // 1. IPv6 优先复用系统自带的纯真 IPv6 数据库
            if ($isV6) {
                return self::parseIPv6Location($ip);
            }

            // 2. IPv4 使用离线 ip2region.xdb 解析
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
                    } else {
                        $c0Upper = strtoupper($c0);
                        if (isset(self::$countryMap[$c0Upper])) {
                            // 查找对应的二字码
                            foreach (self::$countryMap as $k => $v) {
                                if (strlen($k) === 2 && self::$countryMap[$c0Upper] === $v) {
                                    $countryCode = $k;
                                    break;
                                }
                            }
                        }
                    }
                    if (empty($countryCode)) {
                        $countryCode = ($c0 === '中国' || str_contains($c0, '中国')) ? 'CN' : 'GL';
                    }

                    // A. 中国大陆及港澳台处理
                    if ($countryCode === 'CN' || $c0 === '中国') {
                        // 特别行政区处理
                        if (str_contains($c1, '香港') || str_contains($c0, '香港')) {
                            return ['country' => '中国香港', 'country_code' => 'HK', 'city' => '香港', 'province' => '香港', 'location' => '中国香港'];
                        }
                        if (str_contains($c1, '澳门') || str_contains($c0, '澳门')) {
                            return ['country' => '中国澳门', 'country_code' => 'MO', 'city' => '澳门', 'province' => '澳门', 'location' => '中国澳门'];
                        }
                        if (str_contains($c1, '台湾') || str_contains($c0, '台湾')) {
                            $twCity = self::cleanCityName($c2);
                            $display = $twCity ? "台湾 $twCity" : '台湾';
                            return ['country' => '中国台湾', 'country_code' => 'TW', 'city' => $display, 'province' => '台湾', 'location' => '中国台湾 ' . $twCity];
                        }

                        // 国内普通省份 + 城市（先省份后地名，如 "安徽 铜陵"、"福建 泉州"）
                        $prov = self::cleanProvinceName($c1);
                        $city = self::cleanCityName($c2);
                        $display = self::formatChinaLocation($prov, $city);

                        return [
                            'country' => '中国',
                            'country_code' => 'CN',
                            'province' => $prov,
                            'city' => $display,
                            'location' => trim('中国 ' . $prov . ' ' . $city),
                        ];
                    }

                    // B. 海外 IP（国家中文 + 城市中文，整齐规整）
                    $cUpper = strtoupper($c0);
                    $countryCn = self::$countryMap[$cUpper] ?? (self::$countryMap[$countryCode] ?? $c0);

                    $cityRaw = $c2 ?: ($c1 ?: $c0);
                    $cityUpper = strtoupper($cityRaw);
                    $cityCn = self::$cityMap[$cityUpper] ?? $cityRaw;

                    $display = trim($countryCn . ' ' . $cityCn);
                    return [
                        'country' => $countryCn,
                        'country_code' => $countryCode,
                        'province' => $c1,
                        'city' => $display ?: $countryCn,
                        'location' => $display ?: $countryCn,
                    ];
                }
            }
        } catch (\Throwable $e) {}

        return ['country' => '未知', 'country_code' => 'UN', 'city' => '未知', 'province' => '', 'location' => '未知'];
    }

    /**
     * 复用纯真数据库解析 IPv6
     */
    private static function parseIPv6Location(string $ip): array
    {
        try {
            if (!class_exists('\App\Utils\IPv6WrySearcher')) {
                $searcherFile = __DIR__ . '/../Utils/IPv6WrySearcher.php';
                if (file_exists($searcherFile)) {
                    require_once $searcherFile;
                }
            }

            if (!class_exists('\App\Utils\IPv6WrySearcher')) {
                return ['country' => '未知', 'country_code' => 'UN', 'city' => '未知', 'province' => '', 'location' => '未知'];
            }

            $rawText = \App\Utils\IPv6WrySearcher::search($ip);
            if (empty($rawText)) {
                return ['country' => '未知', 'country_code' => 'UN', 'city' => '未知', 'province' => '', 'location' => '未知'];
            }

            $clean = preg_replace('/[\t\s]+/', ' ', trim($rawText));
            if (str_contains($clean, '局域网') || str_contains($clean, '本地') || str_contains($clean, '保留地址')) {
                return ['country' => '局域网', 'country_code' => 'LAN', 'city' => '局域网', 'province' => '', 'location' => '局域网'];
            }

            // 1. 中国 IPv6（如 "中国 福建省 厦门市 集美区 中国移动公众宽带"）
            if (str_starts_with($clean, '中国') || str_contains($clean, '省') || str_contains($clean, '自治区') || str_contains($clean, '特别行政区')) {
                $parts = explode(' ', $clean);
                $provRaw = $parts[1] ?? ($parts[0] ?? '');
                $cityRaw = $parts[2] ?? '';

                if (str_contains($clean, '香港')) {
                    return ['country' => '中国香港', 'country_code' => 'HK', 'city' => '香港', 'province' => '香港', 'location' => '中国香港'];
                }
                if (str_contains($clean, '澳门')) {
                    return ['country' => '中国澳门', 'country_code' => 'MO', 'city' => '澳门', 'province' => '澳门', 'location' => '中国澳门'];
                }
                if (str_contains($clean, '台湾')) {
                    $twCity = self::cleanCityName($cityRaw);
                    return ['country' => '中国台湾', 'country_code' => 'TW', 'city' => $twCity ? "台湾 $twCity" : '台湾', 'province' => '台湾', 'location' => '中国台湾'];
                }

                $prov = self::cleanProvinceName($provRaw);
                $city = self::cleanCityName($cityRaw);
                $display = self::formatChinaLocation($prov, $city);

                return [
                    'country' => '中国',
                    'country_code' => 'CN',
                    'province' => $prov,
                    'city' => $display,
                    'location' => trim('中国 ' . $prov . ' ' . $city),
                ];
            }

            // 2. 海外 IPv6（如 "德国 Hessen州 Frankfurt Am Main Google Inc 服务器网段"）
            $parts = explode(' ', $clean);
            $cName = $parts[0] ?? '海外';
            $pName = $parts[1] ?? '';
            $cityName = $parts[2] ?? '';

            $cUpper = strtoupper($cName);
            $countryCode = 'GL';
            $countryCn = self::$countryMap[$cUpper] ?? $cName;

            foreach (self::$countryMap as $code => $cn) {
                if ($cName === $cn || str_contains($cName, $cn)) {
                    if (strlen($code) === 2) {
                        $countryCode = $code;
                        $countryCn = $cn;
                        break;
                    }
                }
            }

            $cityCn = self::$cityMap[strtoupper($cityName)] ?? (self::$cityMap[strtoupper($pName)] ?? ($cityName ?: $pName));
            $display = trim($countryCn . ' ' . $cityCn);

            return [
                'country' => $countryCn,
                'country_code' => $countryCode,
                'province' => $pName,
                'city' => $display ?: $countryCn,
                'location' => $clean,
            ];
        } catch (\Throwable $e) {
            return ['country' => '未知', 'country_code' => 'UN', 'city' => '未知', 'province' => '', 'location' => '未知'];
        }
    }

    /**
     * 清洗中国省份名称（去除后缀保留纯地名，如 "安徽省" -> "安徽"）
     */
    private static function cleanProvinceName(string $prov): string
    {
        $prov = trim($prov);
        return preg_replace('/(省|壮族自治区|维吾尔自治区|回族自治区|自治区|特别行政区)$/u', '', $prov);
    }

    /**
     * 清洗中国城市名称（去除结尾"市"等后缀，如 "铜陵市" -> "铜陵"）
     */
    private static function cleanCityName(string $city): string
    {
        $city = trim($city);
        if ($city === '0' || empty($city)) return '';
        if ($city !== '市') {
            $city = preg_replace('/市$/u', '', $city);
        }
        return $city;
    }

    /**
     * 规范化中国地理显示（直辖市直接显示，其他显示 "省份 城市"）
     */
    private static function formatChinaLocation(string $prov, string $city): string
    {
        $directCities = ['北京', '上海', '天津', '重庆'];
        foreach ($directCities as $dc) {
            if ($prov === $dc || str_contains($prov, $dc)) {
                return $dc;
            }
        }

        if (!empty($prov) && !empty($city)) {
            if ($prov === $city) {
                return $prov;
            }
            return "$prov $city";
        }

        return $prov ?: ($city ?: '中国');
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
