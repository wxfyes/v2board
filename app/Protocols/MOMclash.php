<?php

namespace App\Protocols;

use App\Utils\Helper;
use Symfony\Component\Yaml\Yaml;

class MOMclash
{
    public $flag = 'momclash';
    private $servers;
    private $user;

    public function __construct($user, $servers)
    {
        $this->user = $user;
        $this->servers = $servers;
    }

    public function handle()
    {
        $servers = $this->servers;
        $user = $this->user;
        $appName = config('v2board.app_name', 'V2Board');
        header("subscription-userinfo: upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}");
        header('profile-update-interval: 24');
        header("content-disposition:attachment;filename*=UTF-8''" . rawurlencode($appName));

        // -----------------------------------------------------------
        // MOMclash 专属配置逻辑
        // 优先加载 momclash.yaml，找不到则回退到 custom.clash.yaml
        // -----------------------------------------------------------
        $momConfig = base_path() . '/resources/rules/momclash.yaml';
        $defaultConfig = base_path() . '/resources/rules/default.clash.yaml';
        $customConfig = base_path() . '/resources/rules/custom.clash.yaml';

        if (\File::exists($momConfig)) {
            $config = Yaml::parseFile($momConfig);
        } elseif (\File::exists($customConfig)) {
            $config = Yaml::parseFile($customConfig);
        } else {
            $config = Yaml::parseFile($defaultConfig);
        }

        $proxy = [];
        $proxies = [];

        foreach ($servers as $item) {
            if (($item['type'] ?? null) === 'v2node' && isset($item['protocol'])) {
                $item['type'] = $item['protocol'];
            }
            switch ($item['type']) {
                case 'shadowsocks':
                    $proxy[] = ClashMeta::buildShadowsocks($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'vmess':
                    $proxy[] = ClashMeta::buildVmess($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'vless':
                    $proxy[] = ClashMeta::buildVless($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'trojan':
                    $proxy[] = ClashMeta::buildTrojan($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'tuic':
                    $proxy[] = ClashMeta::buildTuic($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'anytls':
                    $proxy[] = ClashMeta::buildAnyTLS($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'hysteria':
                    $proxy[] = ClashMeta::buildHysteria($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
                case 'hysteria2':
                    // Note: buildHysteria2 is private in ClashMeta, so we cannot call it statically if we didn't copy it.
                    // But wait, I'm copying the whole file? No, I'm calling ClashMeta::build... methods.
                    // Static methods in ClashMeta are public, so that's fine.
                    // BUT buildHysteria2 is PRIVATE in original file.
                    // So I must copy the private methods too.
                    $proxy[] = $this->buildHysteria2($user['uuid'], $item);
                    $proxies[] = $item['name'];
                    break;
            }
        }

        $config['proxies'] = array_merge($config['proxies'] ? $config['proxies'] : [], $proxy);
        foreach ($config['proxy-groups'] as $k => $v) {
            if (!is_array($config['proxy-groups'][$k]['proxies']))
                $config['proxy-groups'][$k]['proxies'] = [];
            $isFilter = false;
            foreach ($config['proxy-groups'][$k]['proxies'] as $src) {
                foreach ($proxies as $dst) {
                    if (!$this->isRegex($src))
                        continue;
                    $isFilter = true;
                    $config['proxy-groups'][$k]['proxies'] = array_values(array_diff($config['proxy-groups'][$k]['proxies'], [$src]));
                    if ($this->isMatch($src, $dst)) {
                        array_push($config['proxy-groups'][$k]['proxies'], $dst);
                    }
                }
                if ($isFilter)
                    continue;
            }
            if ($isFilter)
                continue;
            $config['proxy-groups'][$k]['proxies'] = array_merge($config['proxy-groups'][$k]['proxies'], $proxies);
        }
        $config['proxy-groups'] = array_filter($config['proxy-groups'], function ($group) {
            return !empty($group['proxies']);
        });
        $config['proxy-groups'] = array_values($config['proxy-groups']);

        // -----------------------------------------------------------
        // 🚀 纯 PHP 动态优化 (不依赖 YAML)
        // -----------------------------------------------------------
        $subscribeHost = $_SERVER['HTTP_HOST'] ?? null;
        if ($subscribeHost) {
            // 1. 自动将面板域名加入 DNS 加速 (防止解析不出 IP)
            if (!isset($config['dns']['fallback-filter']['domain'])) {
                $config['dns']['fallback-filter']['domain'] = [];
            }
            if (!in_array("+.$subscribeHost", $config['dns']['fallback-filter']['domain'])) {
                $config['dns']['fallback-filter']['domain'][] = "+.$subscribeHost";
            }

            // 2. 自动在规则列表首部添加该域名，但不强制 DIRECT
            // 默认让它走“🚀 节点选择”或者跟随规则集，确保代理可兜底
            if (!isset($config['rules']) || !is_array($config['rules'])) {
                $config['rules'] = [];
            }
            // 我们可以把它加在最前面作为“匹配项”，但目标设为一个策略组名
            // 这样它就会走代理，直到直连规则（如 GEOIP,CN）接管它
            array_unshift($config['rules'], "DOMAIN,{$subscribeHost},🚀 节点选择");
        }

        // 3. 基础解析 IP 放行 (仅用于解析服务器，不影响业务)
        if (isset($config['dns']['proxy-server-nameserver']) && is_array($config['dns']['proxy-server-nameserver'])) {
            foreach (array_reverse($config['dns']['proxy-server-nameserver']) as $ds) {
                if (preg_match('/(\d+\.\d+\.\d+\.\d+)/', $ds, $matches)) {
                    array_unshift($config['rules'], "IP-CIDR,{$matches[1]}/32,DIRECT,no-resolve");
                }
            }
        }

        $yaml = Yaml::dump($config, 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $yaml = str_replace('$app_name', config('v2board.app_name', 'V2Board'), $yaml);
        return $yaml;
    }

    // Copied private helper methods from ClashMeta because we can't access them

    private function buildHysteria2($password, $server)
    {
        $tlsSettings = $server['tls_settings'] ?? [];
        $array = [
            'name' => $server['name'],
            'type' => 'hysteria2',
            'server' => $server['host'],
            'password' => $password,
            'skip-cert-verify' => ($tlsSettings['allow_insecure'] ?? 0) == 1 ? true : false,
            'sni' => $tlsSettings['server_name'] ?? '',
            'udp' => true,
        ];
        $parts = explode(",", $server['port']);
        $firstPart = $parts[0];
        if (strpos($firstPart, '-') !== false) {
            $range = explode('-', $firstPart);
            $firstPort = $range[0];
        } else {
            $firstPort = $firstPart;
        }
        $array['port'] = (int) $firstPort;
        if (count($parts) !== 1 || strpos($parts[0], '-') !== false) {
            $array['ports'] = $server['port'];
            $array['mport'] = $server['port'];
        }
        if (isset($server['obfs'])) {
            $array['obfs'] = $server['obfs'];
            $array['obfs-password'] = $server['obfs_password'];
        }
        return $array;
    }

    private function isMatch($exp, $str)
    {
        return @preg_match($exp, $str);
    }

    private function isRegex($exp)
    {
        return @preg_match($exp, '') !== false;
    }
}
