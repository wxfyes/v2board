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
    /**
     * 国旗 Emoji 映射字典
     */
    private const COUNTRY_FLAGS = [
        '中国' => '🇨🇳', 'CN' => '🇨🇳',
        '香港' => '🇭🇰', 'HK' => '🇭🇰',
        '台湾' => '🇹🇼', 'TW' => '🇹🇼',
        '澳门' => '🇲🇴', 'MO' => '🇲🇴',
        '朝鲜' => '🇰🇵', 'KP' => '🇰🇵',
        '韩国' => '🇰🇷', 'KR' => '🇰🇷',
        '日本' => '🇯🇵', 'JP' => '🇯🇵',
        '美国' => '🇺🇸', 'US' => '🇺🇸',
        '英国' => '🇬🇧', 'GB' => '🇬🇧', 'UK' => '🇬🇧',
        '德国' => '🇩🇪', 'DE' => '🇩🇪',
        '法国' => '🇫🇷', 'FR' => '🇫🇷',
        '俄罗斯' => '🇷🇺', 'RU' => '🇷🇺',
        '加拿大' => '🇨🇦', 'CA' => '🇨🇦',
        '澳大利亚' => '🇦🇺', 'AU' => '🇦🇺',
        '新加坡' => '🇸🇬', 'SG' => '🇸🇬',
        '马来西亚' => '🇲🇾', 'MY' => '🇲🇾',
        '泰国' => '🇹🇭', 'TH' => '🇹🇭',
        '越南' => '🇻🇳', 'VN' => '🇻🇳',
        '菲律宾' => '🇵🇭', 'PH' => '🇵🇭',
        '印度' => '🇮🇳', 'IN' => '🇮🇳',
        '荷兰' => '🇳🇱', 'NL' => '🇳🇱',
        '意大利' => '🇮🇹', 'IT' => '🇮🇹',
        '西班牙' => '🇪🇸', 'ES' => '🇪🇸',
        '巴西' => '🇧🇷', 'BR' => '🇧🇷',
        '阿联酋' => '🇦🇪', 'AE' => '🇦🇪',
        '土耳其' => '🇹🇷', 'TR' => '🇹🇷',
        '印度尼西亚' => '🇮🇩', 'ID' => '🇮🇩',
        '局域网' => '🏠', '内网' => '🏠'
    ];

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

            // 1. 内存防抖（15 秒内同 IP 的连续请求不重复写库，避免并发或爬虫击穿）
            $throttleKey = 'visitor_throttle_' . md5($ip);
            if (Cache::has($throttleKey)) {
                return;
            }
            Cache::put($throttleKey, 1, 15);

            // 2. 检查数据表是否存在（带自愈兜底）
            self::ensureTable();

            // 3. 解析 UA 环境
            $ua = (string)$request->userAgent();
            $clientInfo = self::parseUserAgent($ua);

            // 4. 解析 IP 地理位置
            $geo = IpHelper::parseGeo($ip);
            $country = $geo['country'] ?? '未知';
            $province = $geo['province'] ?? '';
            $city = $geo['city'] ?? '';
            $flag = self::detectFlag($country, $geo['country_code'] ?? '');

            // 位置格式化：如果有城市优先显示国旗+城市；否则显示国旗+国家
            $displayLoc = !empty($city) ? $city : (!empty($province) ? $province : $country);
            $fullLocation = trim(($country ?: '') . ' ' . ($province ?: '') . ' ' . ($city ?: ''));

            $now = time();
            $nowUrl = substr($url ?: ($request->path() ?: '/'), 0, 255);

            // 5. 采用单 IP 优雅聚合更新 (原子 UPSERT)
            // 如果不存在则插入 (visit_count=1)；如果已存在则自增 (+1) 并更新最后访问时间与环境特征
            $affected = DB::table('v2_visitor_log')
                ->where('ip', $ip)
                ->increment('visit_count', 1, [
                    'updated_at' => $now,
                    'last_url' => $nowUrl,
                    'browser' => $clientInfo['browser'],
                    'os' => $clientInfo['os'],
                    'device' => $clientInfo['device'],
                    'ua' => substr($ua, 0, 500),
                ]);

            if ($affected === 0) {
                DB::table('v2_visitor_log')->insertOrIgnore([
                    'ip' => $ip,
                    'visit_count' => 1,
                    'country' => $country,
                    'country_code' => $geo['country_code'] ?? '',
                    'flag' => $flag,
                    'province' => $province,
                    'city' => $displayLoc,
                    'location' => $fullLocation ?: $country,
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
            // 静默安全兜底，严禁访客日志影响前台正常业务
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
     * 精准匹配国家国旗 Emoji
     */
    public static function detectFlag(string $country, string $countryCode = ''): string
    {
        if (!empty($countryCode) && isset(self::COUNTRY_FLAGS[strtoupper($countryCode)])) {
            return self::COUNTRY_FLAGS[strtoupper($countryCode)];
        }

        foreach (self::COUNTRY_FLAGS as $key => $flag) {
            if ($key !== '' && str_contains($country, $key)) {
                return $flag;
            }
        }

        return '🌐';
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

        // 1. 识别操作系统
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

        // 2. 识别设备类型 (Desktop, Mobile, Tablet)
        $device = 'Desktop';
        if (preg_match('/iPad|Tablet|PlayBook/i', $ua)) {
            $device = 'Tablet';
        } elseif (preg_match('/Mobile|Android|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua)) {
            $device = 'Mobile';
        } elseif (preg_match('/bot|crawl|spider|slurp|facebookexternalhit/i', $ua)) {
            $device = 'Bot';
        }

        // 3. 识别浏览器
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
