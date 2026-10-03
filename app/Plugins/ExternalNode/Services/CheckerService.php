<?php

namespace App\Plugins\ExternalNode\Services;

class CheckerService
{
    /**
     * 对节点池进行分批非阻塞并发探测测活
     */
    public static function checkAll(array $nodes, int $batchSize = 35, float $timeout = 2.0): array
    {
        if (empty($nodes)) {
            return [];
        }

        $chunks = array_chunk($nodes, $batchSize);
        $checkedNodes = [];

        foreach ($chunks as $chunk) {
            $results = self::checkBatch($chunk, $timeout);
            foreach ($results as $item) {
                $checkedNodes[] = $item;
            }
        }

        return $checkedNodes;
    }

    /**
     * 单批次并发非阻塞探测与真实握手校验
     */
    private static function checkBatch(array $nodes, float $timeout): array
    {
        $sockets = [];
        $startTimes = [];
        $nodeMap = [];

        // 知名欺骗性/免流宿主公共域名黑名单（直接判定假节点/过滤，避免误测）
        $bogusHostPatterns = [
            'gov.uk', 'apple.com', 'itunes.apple.com', 'microsoft.com',
            'speedtest.net', 'speedtest.cn'
        ];

        foreach ($nodes as $index => $node) {
            $host = trim($node['host'] ?? '');
            $port = (int)($node['port'] ?? 0);

            // 1. 基础校验
            if (empty($host) || $port <= 0 || $port > 65535) {
                $node['is_online'] = false;
                $node['offline_count'] = ($node['offline_count'] ?? 0) + 1;
                $nodeMap[$index] = $node;
                continue;
            }

            // 2. 拦截免流与借壳宿主公共域名（这些宿主 443 永远通，但节点必定无法连接）
            foreach ($bogusHostPatterns as $bogus) {
                if (strcasecmp($host, $bogus) === 0 || (strlen($host) > strlen($bogus) && str_ends_with(strtolower($host), '.' . $bogus))) {
                    $node['is_online'] = false;
                    $node['latency'] = 0;
                    $node['offline_count'] = ($node['offline_count'] ?? 0) + 1;
                    $nodeMap[$index] = $node;
                    continue 2;
                }
            }

            $remote = "tcp://{$host}:{$port}";
            $errno = 0;
            $errstr = '';

            // 非阻塞建立连接
            $startTimes[$index] = microtime(true);
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'peer_name' => !empty($node['sni']) ? $node['sni'] : $host
                ]
            ]);

            $socket = @stream_socket_client(
                $remote,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_ASYNC_CONNECT,
                $context
            );

            if ($socket) {
                stream_set_blocking($socket, false);
                $sockets[$index] = $socket;
            } else {
                $node['is_online'] = false;
                $node['latency'] = 0;
                $node['offline_count'] = ($node['offline_count'] ?? 0) + 1;
            }

            $nodeMap[$index] = $node;
        }

        // 使用 stream_select 等待并收集完成的 socket
        $expireTime = microtime(true) + $timeout;

        while (!empty($sockets) && microtime(true) < $expireTime) {
            $r = [];
            $w = $sockets;
            $e = $sockets;
            $sec = 0;
            $usec = 150000; // 150ms

            $n = @stream_select($r, $w, $e, $sec, $usec);
            if ($n === false) break;

            if ($n > 0) {
                foreach ($w as $index => $sock) {
                    $endTime = microtime(true);
                    $latency = round(($endTime - $startTimes[$index]) * 1000);

                    // 检查是否真实连通
                    $peer = @stream_socket_get_name($sock, true);
                    $isLive = ($peer !== false);

                    if ($isLive) {
                        $node = $nodeMap[$index];
                        // 针对 TLS 节点进行轻量握手验证，杜绝证书错位或假在线
                        if (!empty($node['tls']) || in_array($node['type'] ?? '', ['trojan', 'hysteria2']) || $node['port'] == 443) {
                            stream_set_blocking($sock, true);
                            stream_set_timeout($sock, 1);
                            $crypto = @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                            if ($crypto !== true) {
                                // TLS 握手失败（如证书不匹配，或被服务端丢弃）
                                $isLive = false;
                            }
                        }
                    }

                    if ($isLive) {
                        $nodeMap[$index]['is_online'] = true;
                        $nodeMap[$index]['latency'] = max((int)$latency, 10);
                        $nodeMap[$index]['offline_count'] = 0;
                        $nodeMap[$index]['last_online_at'] = time();
                    } else {
                        $nodeMap[$index]['is_online'] = false;
                        $nodeMap[$index]['latency'] = 0;
                        $nodeMap[$index]['offline_count'] = ($nodeMap[$index]['offline_count'] ?? 0) + 1;
                    }

                    @fclose($sock);
                    unset($sockets[$index]);
                }
            }
        }

        // 超时剩余未完成的 socket 一律置为离线
        foreach ($sockets as $index => $sock) {
            $nodeMap[$index]['is_online'] = false;
            $nodeMap[$index]['latency'] = 0;
            $nodeMap[$index]['offline_count'] = ($nodeMap[$index]['offline_count'] ?? 0) + 1;
            @fclose($sock);
        }

        return array_values($nodeMap);
    }
}
