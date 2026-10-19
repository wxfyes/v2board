<?php

namespace App\Http\Middleware;

use App\Services\SecurityService;
use App\Utils\IpHelper;
use Closure;
use Illuminate\Http\Request;

class SecurityCheck
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $uri = $request->getRequestUri();

        // 0. 核心铁律：节点服务器通信直接秒级放行，绝对不进行任何校验或拦截，保障节点永不掉线！
        if (
            strpos($uri, '/api/v1/server/') !== false ||
            strpos($uri, '/api/v1/client/subscribe') !== false
        ) {
            return $next($request);
        }

        $startTime = microtime(true);
        $isMaliciousProbe = false;
        $ruleName = '异常探测';

        try {
            $ip = IpHelper::getRealIp($request);

            // 1. IP 黑名单校验（Redis 毫秒级极速命中，安全熔断）
            if (SecurityService::isBanned($ip)) {
                abort(403, '您的 IP 已被管理员封禁 (IP Blocked)');
            }

            // 2. 检查是否有针对 auth_data 的非法探测特征
            if (
                strpos($uri, 'auth_data%5B') !== false ||
                strpos($uri, 'auth_data[') !== false ||
                strpos($uri, 'getUserInfoById') !== false
            ) {
                $isMaliciousProbe = true;
                $ruleName = '类型混淆与越权探测';
            }
        } catch (\Throwable $e) {
            // 熔断旁路：如果判断过程中有任何异常，绝不阻塞用户请求，直接放行
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                throw $e; // 正常抛出 403 阻断
            }
        }

        $response = $next($request);

        // 3. 拦截事件与高危探测自动收录至【入侵感知】
        try {
            $statusCode = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
            if ($isMaliciousProbe || $statusCode === 403 || ($statusCode === 404 && strpos($uri, '/api/v1/') !== false)) {
                $duration = (int)round((microtime(true) - $startTime) * 1000);
                SecurityService::log($request, $statusCode, $ruleName, $duration);
            }
        } catch (\Throwable $e) {
            // 静默熔断
        }

        return $response;
    }
}
