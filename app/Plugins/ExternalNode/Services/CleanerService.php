<?php

namespace App\Plugins\ExternalNode\Services;

class CleanerService
{
    /**
     * 常用地区匹配字典与国旗 Emoji
     */
    private static array $regionRules = [
        ['pattern' => '/(香港|HK|Hong Kong|HongKong)/i', 'name' => '香港', 'emoji' => '🇭🇰'],
        ['pattern' => '/(日本|JP|Japan|Tokyo|Osaka)/i', 'name' => '日本', 'emoji' => '🇯🇵'],
        ['pattern' => '/(新加坡|SG|Singapore|狮城)/i', 'name' => '新加坡', 'emoji' => '🇸🇬'],
        ['pattern' => '/(美国|US|United States|America|Los Angeles|Silicon)/i', 'name' => '美国', 'emoji' => '🇺🇸'],
        ['pattern' => '/(台湾|TW|Taiwan|台北)/i', 'name' => '台湾', 'emoji' => '🇹🇼'],
        ['pattern' => '/(韩国|KR|Korea|首尔|Seoul)/i', 'name' => '韩国', 'emoji' => '🇰🇷'],
        ['pattern' => '/(英国|UK|London|GB)/i', 'name' => '英国', 'emoji' => '🇬🇧'],
        ['pattern' => '/(德国|DE|Germany|Frankfurt)/i', 'name' => '德国', 'emoji' => '🇩🇪'],
        ['pattern' => '/(加拿大|CA|Canada)/i', 'name' => '加拿大', 'emoji' => '🇨🇦'],
        ['pattern' => '/(澳大利亚|澳洲|AU|Australia|Sydney)/i', 'name' => '澳大利亚', 'emoji' => '🇦🇺'],
    ];

    /**
     * 广告与垃圾字符过滤黑名单
     */
    private static array $adPatterns = [
        '/https?:\/\/[^\s]+/i',
        '/t\.me\/[^\s]+/i',
        '/(TG|群|频道|官网|最新发布|免流|公益|合租|打赏|通知|备用|失联|防止)/iu',
        '/\[.*?\]/',
        '/\(.*?\)/',
        '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/'
    ];

    /**
     * 清洗节点名称并进行标准化商业命名
     */
    public static function cleanAndFormatName(string $rawName, int $index, array $settings): array
    {
        $regionName = '通用';
        $regionEmoji = '🌐';

        // 匹配地区与国旗
        foreach (self::$regionRules as $rule) {
            if (preg_match($rule['pattern'], $rawName)) {
                $regionName = $rule['name'];
                $regionEmoji = $rule['emoji'];
                break;
            }
        }

        // 清洗原本广告
        $cleanedRaw = preg_replace(self::$adPatterns, '', $rawName);
        $cleanedRaw = trim(preg_replace('/\s+/', ' ', $cleanedRaw));

        $prefix = $settings['node_prefix'] ?? '⚡ [免费体验]';
        $suffix = $settings['node_ad_suffix'] ?? ' - 升级VIP享专线';
        $indexStr = str_pad((string)$index, 2, '0', STR_PAD_LEFT);

        $formattedName = "{$regionEmoji} {$prefix} {$regionName} {$indexStr}{$suffix}";

        return [
            'formatted_name' => $formattedName,
            'region' => $regionName,
            'emoji' => $regionEmoji
        ];
    }
}
