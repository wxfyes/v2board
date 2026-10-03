<?php

namespace App\Plugins\ExternalNode\Services;

class CheckerService
{
    /**
     * 对节点池进行分批非阻塞并发 TCPing 测活
     */
    public static function checkAll(array $nodes, int $batchSize = 40, float $timeout = 1.8): array
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
     * 单批次并发非阻塞探测
     */
    private static function checkBatch(array $nodes, float $timeout): array
    {
        $sockets = [];
        $startTimes = [];
        $nodeMap = [];

        foreach ($nodes as $index => $node) {
            $host = $node['host'] ?? '';
            $port = (int)($node['port'] ?? 0);

            if (empty($host) || $port <= 0) {
                $node['is_online'] = false;
                $node['offline_count'] = ($node['offline_count'] ?? 0) + 1;
                $nodeMap[$index] = $node;
                continue;
            }

            $remote = "tcp://{$host}:{$port}";
            $errno = 0;
            $errstr = '';

            // 非阻塞建立连接
            $startTimes[$index] = microtime(true);
            $socket = @stream_socket_client(
                $remote,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_ASYNC_CONNECT,
                stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])
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
            $usec = 200000; // 200ms

            $n = @stream_select($r, $w, $e, $sec, $usec);
            if ($n === false) break;

            if ($n > 0) {
                foreach ($w as $index => $sock) {
                    $endTime = microtime(true);
                    $latency = round(($endTime - $startTimes[$index]) * 1000);

                    // 检查是否真实连通
                    $peer = @stream_socket_get_name($sock, true);
                    if ($peer !== false) {
                        $nodeMap[$index]['is_online'] = true;
                        $nodeMap[$index]['latency'] = (int)$latency;
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
