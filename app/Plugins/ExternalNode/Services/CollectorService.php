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
            'fp' => $query['fp'] ?? 'chrome',
            'spx' => $query['spx'] ?? '',
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
     * 解析 ss:// (深度兼容 Legacy 全 Base64 与 SIP002 标准格式，绝不抛 Undefined array key)
     */
    private static function parseShadowsocks(string $uri): ?array
    {
        $sub = substr($uri, 5);
        $fragment = '';
        if (strpos($sub, '#') !== false) {
            $parts = explode('#', $sub, 2);
            $sub = $parts[0];
            $fragment = $parts[1] ?? '';
        }

        $server = '';
        $port = 0;
        $cipher = 'aes-128-gcm';
        $password = '';

        // 格式 1: SIP002 标准格式 ss://BASE64(cipher:pwd)@server:port
        if (strpos($sub, '@') !== false) {
            $atParts = explode('@', $sub, 2);
            $userinfo = $atParts[0];
            $serverPort = $atParts[1] ?? '';

            if (strpos($serverPort, ':') !== false) {
                $spParts = explode(':', $serverPort, 2);
                $server = trim($spParts[0]);
                $port = (int)($spParts[1] ?? 0);
            }

            $decodedUser = @base64_decode($userinfo);
            if ($decodedUser && strpos($decodedUser, ':') !== false) {
                $cpParts = explode(':', $decodedUser, 2);
                $cipher = trim($cpParts[0]);
                $password = trim($cpParts[1] ?? '');
            } else {
                $password = $userinfo;
            }
        } else {
            // 格式 2: Legacy 全 Base64 格式 ss://BASE64(cipher:pwd@server:port)
            $decoded = @base64_decode($sub);
            if ($decoded && strpos($decoded, '@') !== false && strpos($decoded, ':') !== false) {
                $atParts = explode('@', $decoded, 2);
                $cipherPwd = $atParts[0];
                $serverPort = $atParts[1] ?? '';

                if (strpos($cipherPwd, ':') !== false) {
                    $cpParts = explode(':', $cipherPwd, 2);
                    $cipher = trim($cpParts[0]);
                    $password = trim($cpParts[1] ?? '');
                }

                if (strpos($serverPort, ':') !== false) {
                    $spParts = explode(':', $serverPort, 2);
                    $server = trim($spParts[0]);
                    $port = (int)($spParts[1] ?? 0);
                }
            }
        }

        if (empty($server) || $port <= 0) {
            return null;
        }

        return [
            'id' => md5($server . ':' . $port),
            'raw_name' => urldecode($fragment ?: 'SS Node'),
            'type' => 'shadowsocks',
            'host' => $server,
            'port' => $port,
            'cipher' => $cipher,
            'password' => $password,
            'raw_data' => ['server' => $server, 'port' => $port]
        ];
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

    private static function formatClashProxy(array $p): ?array
    {
        if (empty($p['server']) || empty($p['port']) || empty($p['type'])) {
            return null;
        }

        $type = strtolower($p['type']);
        $wsOpts = $p['ws-opts'] ?? [];
        $grpcOpts = $p['grpc-opts'] ?? [];
        $path = $wsOpts['path'] ?? ($p['plugin-opts']['path'] ?? '');
        $headers = $wsOpts['headers'] ?? [];
        $sni = $p['servername'] ?? ($p['sni'] ?? ($headers['Host'] ?? ''));

        // Reality 特性智能提取
        $realityOpts = $p['reality-opts'] ?? [];
        $pbk = $realityOpts['public-key'] ?? ($p['public-key'] ?? ($p['pbk'] ?? ''));
        $sid = $realityOpts['short-id'] ?? ($p['short-id'] ?? ($p['sid'] ?? ''));
        $fp = $p['client-fingerprint'] ?? ($p['fp'] ?? 'chrome');
        $flow = $p['flow'] ?? '';
        $isReality = !empty($pbk) || !empty($realityOpts) || ($p['security'] ?? '') === 'reality';

        return [
            'id' => md5($p['server'] . ':' . $p['port'] . ':' . ($p['uuid'] ?? ($p['password'] ?? ''))),
            'raw_name' => $p['name'] ?? 'Clash Proxy',
            'type' => $type,
            'host' => $p['server'],
            'port' => (int)$p['port'],
            'uuid' => $p['uuid'] ?? ($p['password'] ?? ''),
            'password' => $p['password'] ?? ($p['uuid'] ?? ''),
            'cipher' => $p['cipher'] ?? 'auto',
            'alterId' => (int)($p['alterId'] ?? 0),
            'network' => $p['network'] ?? 'tcp',
            'tls' => (!empty($p['tls']) || $isReality) ? 1 : 0,
            'security' => $isReality ? 'reality' : (!empty($p['tls']) ? 'tls' : 'none'),
            'sni' => $sni,
            'path' => $path,
            'headers' => $headers,
            'grpc_service_name' => $grpcOpts['grpc-service-name'] ?? '',
            'skip_cert_verify' => !empty($p['skip-cert-verify']),
            'flow' => $flow,
            'pbk' => $pbk,
            'sid' => $sid,
            'fp' => $fp,
            'raw_data' => $p
        ];
    }

    /**
     * 高性能并发并行拉取多个订阅源 (curl_multi，彻底告别单线程阻塞)
     */
    public static function fetchSourcesMulti(array &$sources, float $timeout = 4.0): array
    {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($sources as $idx => $src) {
            $isEnabled = filter_var($src['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
            if (!$isEnabled || empty($src['url'])) continue;

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $src['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => (int)ceil($timeout),
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_USERAGENT => 'ClashMeta/v1.18.0 (Clash for Windows; Chrome/120.0.0.0)'
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[$idx] = $ch;
        }

        if (empty($handles)) {
            curl_multi_close($mh);
            return [];
        }

        // 并发执行拉取
        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc === CURLM_OK) {
            if (curl_multi_select($mh, 0.2) !== -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc === CURLM_CALL_MULTI_PERFORM);
            }
        }

        // 收集结果并去重解析
        $allNodes = [];
        $uniqueMap = [];

        foreach ($handles as $idx => $ch) {
            $content = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $sources[$idx]['last_sync_at'] = time();

            if ($code >= 200 && $code < 300 && !empty($content)) {
                $nodes = self::parseContent($content);
                $sources[$idx]['node_count'] = count($nodes);

                foreach ($nodes as $n) {
                    $sig = ($n['host'] ?? '') . ':' . ($n['port'] ?? '');
                    if (!isset($uniqueMap[$sig]) && !empty($n['host'])) {
                        $uniqueMap[$sig] = true;
                        $allNodes[] = $n;
                    }
                }
            } else {
                $sources[$idx]['node_count'] = 0;
            }
        }

        curl_multi_close($mh);
        return $allNodes;
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
