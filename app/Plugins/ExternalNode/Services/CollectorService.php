<?php

namespace App\Plugins\ExternalNode\Services;

use Symfony\Component\Yaml\Yaml;

class CollectorService
{
    /**
     * 拉取并解析单个订阅源
     */
    public static function fetchSource(string $url): array
    {
        $content = self::httpGet($url);
        if (empty($content)) {
            return [];
        }

        return self::parseContent($content);
    }

    /**
     * 内容解析器（智能识别 YAML、Base64 或纯文本链接）
     */
    public static function parseContent(string $raw): array
    {
        $raw = trim($raw);
        $nodes = [];

        // 1. 尝试判断是否为 Clash YAML
        if (stripos($raw, 'proxies:') !== false) {
            try {
                $yaml = Yaml::parse($raw);
                if (isset($yaml['proxies']) && is_array($yaml['proxies'])) {
                    foreach ($yaml['proxies'] as $item) {
                        $parsed = self::formatClashProxy($item);
                        if ($parsed) {
                            $nodes[] = $parsed;
                        }
                    }
                    if (!empty($nodes)) {
                        return $nodes;
                    }
                }
            } catch (\Throwable $e) {
                // YAML 解析失败则进入下一种模式探测
            }
        }

        // 2. 尝试 Base64 解码
        $decoded = @base64_decode($raw, true);
        if ($decoded !== false && (stripos($decoded, '://') !== false || stripos($decoded, 'proxies:') !== false)) {
            $raw = $decoded;
            // 递归一次，如果是 Base64 包装的 YAML
            if (stripos($raw, 'proxies:') !== false) {
                return self::parseContent($raw);
            }
        }

        // 3. 逐行解析链接 (vmess://, vless://, ss://, trojan://, hysteria2://)
        $lines = preg_split('/[\r\n]+/', $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $node = self::parseUri($line);
            if ($node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * 解析各类代理协议 URI
     */
    public static function parseUri(string $uri): ?array
    {
        $uri = trim($uri);
        $scheme = parse_url($uri, PHP_URL_SCHEME);

        switch (strtolower((string)$scheme)) {
            case 'vmess':
                return self::parseVmess($uri);
            case 'vless':
                return self::parseVless($uri);
            case 'trojan':
                return self::parseTrojan($uri);
            case 'ss':
                return self::parseShadowsocks($uri);
            case 'hy2':
            case 'hysteria2':
                return self::parseHysteria2($uri);
            default:
                return null;
        }
    }

    /**
     * 解析 vmess://
     */
    private static function parseVmess(string $uri): ?array
    {
        $sub = substr($uri, 8);
        $jsonStr = @base64_decode($sub);
        if (!$jsonStr) return null;

        $json = @json_decode($jsonStr, true);
        if (!is_array($json) || empty($json['add']) || empty($json['port'])) {
            return null;
        }

        return [
            'id' => md5($json['add'] . ':' . $json['port']),
            'raw_name' => $json['ps'] ?? 'VMess Node',
            'type' => 'vmess',
            'host' => $json['add'],
            'port' => (int)$json['port'],
            'uuid' => $json['id'] ?? '',
            'alterId' => (int)($json['aid'] ?? 0),
            'cipher' => $json['scy'] ?? 'auto',
            'network' => $json['net'] ?? 'tcp',
            'tls' => ($json['tls'] ?? '') === 'tls' ? 1 : 0,
            'sni' => $json['sni'] ?? ($json['host'] ?? ''),
            'path' => $json['path'] ?? '',
            'raw_data' => $json
        ];
    }

    /**
     * 解析 vless://
     */
    private static function parseVless(string $uri): ?array
    {
        $parts = parse_url($uri);
        if (!$parts || empty($parts['host']) || empty($parts['port'])) return null;

        parse_str($parts['query'] ?? '', $query);

        return [
            'id' => md5($parts['host'] . ':' . $parts['port']),
            'raw_name' => urldecode($parts['fragment'] ?? 'VLess Node'),
            'type' => 'vless',
            'host' => $parts['host'],
            'port' => (int)$parts['port'],
            'uuid' => $parts['user'] ?? '',
            'network' => $query['type'] ?? 'tcp',
            'flow' => $query['flow'] ?? '',
            'tls' => ($query['security'] ?? '') === 'tls' || ($query['security'] ?? '') === 'reality' ? 1 : 0,
            'security' => $query['security'] ?? 'none',
            'sni' => $query['sni'] ?? '',
            'path' => $query['path'] ?? '',
            'pbk' => $query['pbk'] ?? '',
            'sid' => $query['sid'] ?? '',
            'raw_data' => $parts
        ];
    }

    /**
     * 解析 trojan://
     */
    private static function parseTrojan(string $uri): ?array
    {
        $parts = parse_url($uri);
        if (!$parts || empty($parts['host']) || empty($parts['port'])) return null;

        parse_str($parts['query'] ?? '', $query);

        return [
            'id' => md5($parts['host'] . ':' . $parts['port']),
            'raw_name' => urldecode($parts['fragment'] ?? 'Trojan Node'),
            'type' => 'trojan',
            'host' => $parts['host'],
            'port' => (int)$parts['port'],
            'password' => $parts['user'] ?? '',
            'sni' => $query['sni'] ?? ($parts['host'] ?? ''),
            'tls' => 1,
            'network' => $query['type'] ?? 'tcp',
            'path' => $query['path'] ?? '',
            'raw_data' => $parts
        ];
    }

    /**
     * 解析 ss://
     */
    private static function parseShadowsocks(string $uri): ?array
    {
        $sub = substr($uri, 5);
        $fragment = '';
        if (strpos($sub, '#') !== false) {
            [$sub, $fragment] = explode('#', $sub, 2);
        }

        $decoded = @base64_decode($sub);
        if ($decoded && strpos($decoded, '@') !== false) {
            [$cipherPwd, $serverPort] = explode('@', $decoded, 2);
            [$cipher, $password] = explode(':', $cipherPwd, 2);
            [$server, $port] = explode(':', $serverPort, 2);

            return [
                'id' => md5($server . ':' . $port),
                'raw_name' => urldecode($fragment ?: 'SS Node'),
                'type' => 'shadowsocks',
                'host' => $server,
                'port' => (int)$port,
                'cipher' => $cipher,
                'password' => $password,
                'raw_data' => ['server' => $server, 'port' => $port]
            ];
        }

        return null;
    }

    /**
     * 解析 hysteria2://
     */
    private static function parseHysteria2(string $uri): ?array
    {
        $parts = parse_url($uri);
        if (!$parts || empty($parts['host']) || empty($parts['port'])) return null;

        parse_str($parts['query'] ?? '', $query);

        return [
            'id' => md5($parts['host'] . ':' . $parts['port']),
            'raw_name' => urldecode($parts['fragment'] ?? 'Hysteria2 Node'),
            'type' => 'hysteria2',
            'host' => $parts['host'],
            'port' => (int)$parts['port'],
            'password' => $parts['user'] ?? '',
            'sni' => $query['sni'] ?? '',
            'insecure' => (int)($query['insecure'] ?? 0),
            'raw_data' => $parts
        ];
    }

    /**
     * 格式化 Clash Proxy 节点为统一内部格式
     */
    private static function formatClashProxy(array $p): ?array
    {
        if (empty($p['server']) || empty($p['port']) || empty($p['type'])) {
            return null;
        }

        return [
            'id' => md5($p['server'] . ':' . $p['port']),
            'raw_name' => $p['name'] ?? 'Clash Proxy',
            'type' => strtolower($p['type']),
            'host' => $p['server'],
            'port' => (int)$p['port'],
            'uuid' => $p['uuid'] ?? ($p['password'] ?? ''),
            'password' => $p['password'] ?? ($p['uuid'] ?? ''),
            'cipher' => $p['cipher'] ?? 'auto',
            'network' => $p['network'] ?? 'tcp',
            'tls' => !empty($p['tls']) ? 1 : 0,
            'sni' => $p['servername'] ?? ($p['sni'] ?? ''),
            'raw_data' => $p
        ];
    }

    /**
     * 高容错 HTTP 客户端（10秒超时、跳过证书校验、防爬虫伪装 UA）
     */
    private static function httpGet(string $url): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'ClashMeta/v1.18.0 (Clash for Windows; Chrome/120.0.0.0)'
        ]);

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($code >= 200 && $code < 300) ? (string)$res : '';
    }
}
