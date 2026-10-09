<?php
namespace App\Protocols\Singbox;

use App\Utils\Helper;

class Singbox
{
    public $flag = 'sing';
    private $servers;
    private $user;
    private $config;

    public function __construct($user, $servers, array $options = null)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        $this->config = $this->loadConfig();
        $proxies = $this->buildProxies();
        $outbounds = $this->addProxies($proxies);
        $this->config['outbounds'] = $outbounds;

        return json_encode($this->config, JSON_UNESCAPED_SLASHES);
    }

    protected function loadConfig()
    {
        $defaultConfig = base_path('resources/rules/default.sing-box.json');
        $customConfig = base_path('resources/rules/custom.sing-box.json');
        $jsonData = file_exists($customConfig) ? file_get_contents($customConfig) : file_get_contents($defaultConfig);

        return json_decode($jsonData, true);
    }

    protected function buildProxies()
    {
        $proxies = [];
    
        foreach ($this->servers as $item) {
            if ($item['type'] === 'v2node') {
                $item['type'] = $item['protocol'];
            }
            switch ($item['type']) {
                case 'shadowsocks':
                    $ssConfig = $this->buildShadowsocks($this->user['uuid'], $item);
                    $proxies[] = $ssConfig;
                    break;
                case 'trojan':
                    $trojanConfig = $this->buildTrojan($this->user['uuid'], $item);
                    $proxies[] = $trojanConfig;
                    break;
                case 'vmess':
                    $vmessConfig = $this->buildVmess($this->user['uuid'], $item);
                    $proxies[] = $vmessConfig;
                    break;
                case 'vless':
                    $vlessConfig = $this->buildVless($this->user['uuid'], $item);
                    $proxies[] = $vlessConfig;
                    break;
                case 'tuic':
                    $tuicConfig = $this->buildTuic($this->user['uuid'], $item);
                    $proxies[] = $tuicConfig;
                    break;
                case 'anytls':
                    $anytlsConfig = $this->buildAnyTLS($this->user['uuid'], $item);
                    $proxies[] = $anytlsConfig;
                    break;
                case 'hysteria':
                    $hysteriaConfig = $this->buildHysteria($this->user['uuid'], $item, $this->user);
                    $proxies[] = $hysteriaConfig;
                    break;
                case 'hysteria2':
                    $hysteria2Config = $this->buildHysteria2($this->user['uuid'], $item);
                    $proxies[] = $hysteria2Config;
                    break;
                case 'mieru':
                    $mieruConfig = $this->buildMieru($this->user['uuid'], $item);
                    $proxies[] = $mieruConfig;
                    break;
            }
        }
    
        return $proxies;
    }

    protected function addProxies($proxies)
    {
        foreach ($proxies as &$proxy) {
            if (isset($proxy['transport']) && (empty($proxy['transport']) || !is_array($proxy['transport']) || empty($proxy['transport']['type']))) {
                unset($proxy['transport']);
            }
        }
        unset($proxy);

        foreach ($this->config['outbounds'] as &$outbound) {
            if (($outbound['type'] === 'selector' && $outbound['tag'] === '节点选择') || ($outbound['type'] === 'urltest' && $outbound['tag'] === '自动选择') || ($outbound['type'] === 'selector' && strpos($outbound['tag'], '#') === 0 )) {
                array_push($outbound['outbounds'], ...array_column($proxies, 'tag'));
            }
        }
        unset($outbound);
        $outbounds = array_merge($this->config['outbounds'], $proxies);

        foreach ($outbounds as &$o) {
            if (isset($o['transport']) && (empty($o['transport']) || !is_array($o['transport']) || empty($o['transport']['type']))) {
                unset($o['transport']);
            }
        }
        unset($o);

        return $outbounds;
    }

    protected function buildShadowsocks($password, $server)
    {
        if (strpos($server['cipher'], '2022-blake3') !== false) {
            $length = $server['cipher'] === '2022-blake3-aes-128-gcm' ? 16 : 32;
            $serverKey = Helper::getServerKey($server['created_at'], $length);
            $userKey = Helper::uuidToBase64($password, $length);
            $password = "{$serverKey}:{$userKey}";
        }
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'shadowsocks';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['method'] = $server['cipher'];
        $array['password'] = $password;
        if (isset($server['obfs']) && $server['obfs'] === 'http') {
            $array['plugin'] = 'obfs-local';
            $plugin_opts_parts = [];
            $plugin_opts_parts[] = "obfs=" . $server['obfs'];
            if (isset($server['obfs-host'])) {
                $plugin_opts_parts[] = "obfs-host=" . $server['obfs-host'];
            }
            if (isset($server['obfs-path'])) {
                $plugin_opts_parts[] = "path=" . $server['obfs-path'];
            }
            $array['plugin_opts'] = implode(';', $plugin_opts_parts);
        } else if ((($server['network'] ?? null) == 'http') && isset($server['network_settings']['Host'])) {
            $array['plugin'] = 'obfs-local';
            $plugin_opts_parts = [];
            $plugin_opts_parts[] = "obfs=http";
            $networkSettings = $server['network_settings'];
            $plugin_opts_parts[] = "obfs-host=" . $networkSettings['Host'];
            $plugin_opts_parts[] = "path=" . ($networkSettings['path'] ?? '/');

            $array['plugin_opts'] = implode(';', $plugin_opts_parts);
        }
        return $array;
    }


    protected function buildVmess($uuid, $server)
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'vmess';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['uuid'] = $uuid;
        $array['security'] = 'auto';
        $array['alter_id'] = 0;

        if ($server['tls']) {
            $tlsConfig = [];
            $tlsConfig['enabled'] = true;
            $tlsSettings = $server['tls_settings'] ?? $server['tlsSettings'] ?? [];
            $tlsConfig['insecure'] = ($tlsSettings['allow_insecure'] ?? ($tlsSettings['allowInsecure'] ?? 0)) == 1 ? true : false;
            $tlsConfig['server_name'] = !empty($tlsSettings['server_name']) ? (string)$tlsSettings['server_name'] : (!empty($tlsSettings['serverName']) ? (string)$tlsSettings['serverName'] : '');
            $array['tls'] = $tlsConfig;
        }
        if ($server['network'] === 'tcp') {
            $tcpSettings = $server['networkSettings'] ?? ($server['network_settings'] ?? []);
            if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] == 'http') {
                $array['transport'] = [
                    'type' => 'http',
                    'host' => isset($tcpSettings['header']['request']['headers']['Host']) ? (array)$tcpSettings['header']['request']['headers']['Host'] : [],
                    'path' => $tcpSettings['header']['request']['path'][0] ?? '/'
                ];
            }
        }
        if ($server['network'] === 'ws') {
            $wsSettings = $server['networkSettings'] ?? ($server['network_settings'] ?? []);
            $array['transport'] = [
                'type' => 'ws',
                'path' => $wsSettings['path'] ?? '/',
                'max_early_data' => 2048,
                'early_data_header_name' => 'Sec-WebSocket-Protocol'
            ];
            if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host'])) {
                $array['transport']['headers'] = ['Host' => (array)$wsSettings['headers']['Host']];
            }
        }
        if ($server['network'] === 'grpc') {
            $grpcSettings = $server['networkSettings'] ?? ($server['network_settings'] ?? []);
            $array['transport'] = [
                'type' => 'grpc',
                'service_name' => !empty($grpcSettings['serviceName']) ? $grpcSettings['serviceName'] : 'Tun'
            ];
        }

        return $array;
    }

    protected function buildVless($password, $server)
    {
        $array = [
            "type" => "vless",
            "tag" => $server['name'],
            "server" => $server['host'],
            "server_port" => $server['port'],
            "uuid" => $password,
            "packet_encoding" => "xudp"
        ];

        // XTLS Vision 流控智能映射：
        // 1. 若配置了标准 xtls-rprx-vision，输出 xtls-rprx-vision
        // 2. 若配置了自研流控 mom-vision 或其它包含 vision 的流控，转换为开源客户端标准 xtls-rprx-vision
        // 3. 其余非 vision 流控绝不输出 flow 键，避免第三方客户端抛出 unknown flow
        $rawFlow = trim((string)($server['flow'] ?? ''));
        if (!empty($rawFlow) && ($rawFlow === 'xtls-rprx-vision' || stripos($rawFlow, 'vision') !== false)) {
            $array['flow'] = 'xtls-rprx-vision';
        }

        $tlsSettings = $server['tls_settings'] ?? ($server['tlsSettings'] ?? []);
        if (is_string($tlsSettings)) {
            $tlsSettings = json_decode($tlsSettings, true) ?: [];
        }

        if ($server['tls']) {
            $tlsConfig = [];
            $tlsConfig['enabled'] = true;

            $serverName = $tlsSettings['server_name'] ?? ($tlsSettings['serverName'] ?? ($server['server_name'] ?? ''));
            if (!empty($serverName)) {
                $tlsConfig['server_name'] = (string)$serverName;
            }

            if ($server['tls'] == 2) {
                // Reality 协议：通过 public_key 验证，严禁设置普通 TLS 的 insecure 标志，且必须保证 server_name 存在
                if (empty($tlsConfig['server_name'])) {
                    $tlsConfig['server_name'] = (string)($server['host'] ?? '');
                }
                $publicKey = $tlsSettings['public_key'] ?? ($tlsSettings['publicKey'] ?? '');
                $shortId = $tlsSettings['short_id'] ?? ($tlsSettings['shortId'] ?? '');
                $tlsConfig['reality'] = [
                    'enabled' => true,
                    'public_key' => (string)$publicKey,
                    'short_id' => (string)$shortId
                ];
            } else {
                // 普通 TLS 协议
                $tlsConfig['insecure'] = ($tlsSettings['allow_insecure'] ?? ($tlsSettings['allowInsecure'] ?? 0)) == 1 ? true : false;
            }

            $fingerprint = !empty($tlsSettings['fingerprint']) ? $tlsSettings['fingerprint'] : 'chrome';
            $tlsConfig['utls'] = [
                "enabled" => true,
                "fingerprint" => $fingerprint
            ];

            $array['tls'] = $tlsConfig;
        }

        if ($server['network'] === 'tcp') {
            $tcpSettings = $server['network_settings'] ?? ($server['networkSettings'] ?? []);
            if (is_string($tcpSettings)) {
                $tcpSettings = json_decode($tcpSettings, true) ?: [];
            }
            if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] == 'http') {
                $array['transport'] = [
                    'type' => 'http',
                    'host' => isset($tcpSettings['header']['request']['headers']['Host']) ? (array)$tcpSettings['header']['request']['headers']['Host'] : [],
                    'path' => $tcpSettings['header']['request']['path'][0] ?? '/'
                ];
            }
        }
        if ($server['network'] === 'ws') {
            $wsSettings = $server['network_settings'] ?? ($server['networkSettings'] ?? []);
            if (is_string($wsSettings)) {
                $wsSettings = json_decode($wsSettings, true) ?: [];
            }
            $array['transport'] = [
                'type' => 'ws',
                'path' => $wsSettings['path'] ?? '/',
                'max_early_data' => 2048,
                'early_data_header_name' => 'Sec-WebSocket-Protocol'
            ];
            if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host'])) {
                $array['transport']['headers'] = ['Host' => (array)$wsSettings['headers']['Host']];
            }
        }
        if ($server['network'] === 'grpc') {
            $grpcSettings = $server['network_settings'] ?? ($server['networkSettings'] ?? []);
            if (is_string($grpcSettings)) {
                $grpcSettings = json_decode($grpcSettings, true) ?: [];
            }
            $array['transport'] = [
                'type' => 'grpc',
                'service_name' => !empty($grpcSettings['serviceName']) ? $grpcSettings['serviceName'] : 'Tun'
            ];
        }

        return $array;
    }

    protected function buildTrojan($password, $server) 
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'trojan';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['password'] = $password;

        $tlsSettings = $server['tls_settings'] ?? [];
        $array['tls'] = [
            'enabled' => true,
            'insecure' => ($server['allow_insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0)) == 1 ? true : false,
            'server_name' => $server['server_name'] ?? ($tlsSettings['server_name'] ?? '')
        ];

        if (isset($server['network']) && in_array($server['network'], ["grpc", "ws"])) {
            if ($server['network'] === "grpc") {
                $grpcSettings = $server['network_settings'] ?? [];
                $array['transport'] = [
                    'type' => 'grpc',
                    'service_name' => !empty($grpcSettings['serviceName']) ? $grpcSettings['serviceName'] : 'Tun'
                ];
            } else if ($server['network'] === "ws") {
                $wsSettings = $server['network_settings'] ?? [];
                $array['transport'] = [
                    'type' => 'ws',
                    'path' => $wsSettings['path'] ?? '/',
                    'max_early_data' => 2048,
                    'early_data_header_name' => 'Sec-WebSocket-Protocol'
                ];
                if (isset($wsSettings['headers']['Host']) && !empty($wsSettings['headers']['Host'])) {
                    $array['transport']['headers'] = ['Host' => (array)$wsSettings['headers']['Host']];
                }
            }
        }

        return $array;
    }

    protected function buildTuic($password, $server)
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'tuic';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['uuid'] = $password;
        $array['password'] = $password;
        $array['congestion_control'] = $server['congestion_control'] ?? 'cubic';
        $array['udp_relay_mode'] = $server['udp_relay_mode'] ?? 'native';
        $array['zero_rtt_handshake'] = $server['zero_rtt_handshake'] ? true : false;

        $tlsSettings = $server['tls_settings'] ?? [];
        $array['tls'] = [
            'enabled' => true,
            'insecure' => ($server['insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0)) == 1 ? true : false,
            'alpn' => ['h3'],
            'disable_sni' => $server['disable_sni'] ? true : false,
        ];
        $array['tls']['server_name'] = $server['server_name'] ?? ($tlsSettings['server_name'] ?? '');

        return $array;
    }

    protected function buildAnyTLS($password, $server)
    {
        $array = [];
        $array['tag'] = $server['name'];
        $array['type'] = 'anytls';
        $array['server'] = $server['host'];
        $array['server_port'] = $server['port'];
        $array['password'] = $password;

        $tlsSettings = $server['tls_settings'] ?? [];
        $array['tls'] = [
            'enabled' => true,
            'insecure' => ($server['insecure'] ?? ($tlsSettings['allow_insecure'] ?? 0)) == 1 ? true : false,
            'alpn' => [
                'h2',
                'http/1.1',
            ],
        ];
        $array['tls']['server_name'] = $server['server_name'] ?? ($tlsSettings['server_name'] ?? '');
        return $array;
    }

    protected function buildHysteria($password, $server, $user)
    {
        $parts = array_map('trim', explode(',', $server['port']));
        $portConfig = [];
        
        // 检查是否为单端口
        if (count($parts) === 1 && !str_contains($parts[0], '-')) {
            $port = (int)$parts[0];
        } else {
            // 处理多端口情况 舍弃单独的端口 只保留范围端口
            foreach ($parts as $part) {
                if (str_contains($part, '-')) {
                    $portConfig[] = str_replace('-', ':', $part);
                }
            }
        }

        $array = [
            'tag' => $server['name'],
            'server' => $server['host'],
            'tls' => [
                'enabled' => true,
                'insecure' => $server['insecure'] ? true : false,
                'server_name' => $server['server_name']
            ]
        ];

        // 设置端口配置
        if (isset($port)) {
            $array['server_port'] = $port;
        } else {
            $array['server_ports'] = $portConfig;
        }

        if (is_null($server['version']) || $server['version'] == 1) {
            $array['auth_str'] = $password;
            $array['type'] = 'hysteria';
            $array['up_mbps'] = ($user['speed_limit'] ?? 0) ? min($server['down_mbps'], $user['speed_limit']) : $server['down_mbps'];
            $array['down_mbps'] = ($user['speed_limit'] ?? 0) ? min($server['up_mbps'], $user['speed_limit']) : $server['up_mbps'];
            if (isset($server['obfs']) && isset($server['obfs_password'])) {
                $array['obfs'] = $server['obfs_password'];
            }

            $array['disable_mtu_discovery'] = true;

        } elseif ($server['version'] == 2) {
            $array['password'] = $password;
            $array['type'] = 'hysteria2';
            $array['password'] = $password;

            if (isset($server['obfs'])) {
                $array['obfs']['type'] = $server['obfs'];
                $array['obfs']['password'] = $server['obfs_password'];
            }
        }

        return $array;
    }

    protected function buildHysteria2($password, $server)
    {
        $parts = explode(",",$server['port']);
        $firstPart = $parts[0];
        if (strpos($firstPart, '-') !== false) {
            $range = explode('-', $firstPart);
            $firstPort = $range[0];
        } else {
            $firstPort = $firstPart;
        }
        $tlsSettings = $server['tls_settings'] ?? [];
        $array = [
            'server' => $server['host'],
            'server_port' => (int)$firstPort,
            'tls' => [
                'enabled' => true,
                'insecure' => ($tlsSettings['allow_insecure'] ?? 0) == 1 ? true : false,
                'server_name' => $tlsSettings['server_name'] ?? ''
            ],
            'password' => $password,
            'tag' => $server['name'],
            'type' => 'hysteria2'
        ];
        if (isset($server['obfs'])) {
            $array['obfs']['type'] = $server['obfs'];
            $array['obfs']['password'] = $server['obfs_password'];
        }
        return $array;
    }

    protected function buildMieru($password, $server)
    {
        $serverPort = (int)($server['port'] ?? 0);
        if ($serverPort <= 0 && !empty($server['port_range'])) {
            $parts = explode('-', $server['port_range']);
            $serverPort = (int)($parts[0] ?? 443);
        }
        $array = [
            'type' => 'mieru',
            'tag' => $server['name'],
            'server' => $server['host'],
            'server_port' => $serverPort > 0 ? $serverPort : 443,
            'username' => $password,
            'password' => $password
        ];
        return $array;
    }
}
