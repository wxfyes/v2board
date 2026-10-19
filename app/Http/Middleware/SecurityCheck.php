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

        // 0. 核心白名单：所有节点服务器通信、订阅直接秒级放行，绝对不进行任何拦截，保障节点永不掉线！
        if (
            strpos($uri, '/api/v1/server/') !== false ||
            strpos($uri, '/api/v1/client/subscribe') !== false
        ) {
            return $next($request);
        }

        $startTime = microtime(true);
        $isMaliciousProbe = false;
        $ruleName = '';

        try {
            $ip = IpHelper::getRealIp($request);

            // 1. IP 黑名单校验（仅拦截被管理员手动一键封禁的恶意 IP）
            if (SecurityService::isBanned($ip)) {
                SecurityService::log($request, 403, '黑名单IP拦截', 0);
                abort(403, '您的 IP 已被管理员封禁 (IP Blocked)');
            }

            // 2. 深度识别真实攻击特征（杜绝将 MOMclash 等客户端正常业务请求误判）
            if (
                strpos($uri, 'auth_data%5B') !== false ||
                strpos($uri, 'auth_data[') !== false ||
                strpos($uri, 'getUserInfoById') !== false
            ) {
                $isMaliciousProbe = true;
                $ruleName = '类型混淆与越权探测';
            } elseif (
                preg_match('/\.(env|git|svn|bak|sql|tar|gz|zip|cfg|ini)(\?|$)/i', $uri) ||
                preg_match('/(phpinfo|actuator|wp-login|shell|webshell|cgi-bin)/i', $uri)
            ) {
                $isMaliciousProbe = true;
                $ruleName = '敏感文件与漏洞扫描';
            }
        } catch (\Throwable $e) {
            // 熔断旁路：如果判断过程中有任何异常，绝不阻塞用户请求，直接放行
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                throw $e; // 正常抛出 403 阻断
            }
        }

        $response = $next($request);

        // 3. 安全事件精准收录（保护正常客户端正常业务，如 token 过期导致的 403 绝不记录）
        try {
            $statusCode = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;

            // 仅在明确命中黑客攻击特征、或扫描非法探测路径时收录
            if ($isMaliciousProbe) {
                $duration = (int)round((microtime(true) - $startTime) * 1000);
                SecurityService::log($request, $statusCode, $ruleName, $duration);
            } elseif ($statusCode === 404) {
                $duration = (int)round((microtime(true) - $startTime) * 1000);
                if (strpos($uri, 'favicon.ico') !== false) {
                    SecurityService::log($request, 404, '空间测绘指纹探测 (Favicon)', $duration);
                } elseif (
                    preg_match('/\.(php|jsp|asp|aspx|sh|bash)$/i', $uri) ||
                    (strpos($uri, '/api/v1/') === false && !str_starts_with($uri, '/assets/'))
                ) {
                    SecurityService::log($request, 404, '恶意探针扫描', $duration);
                }
            }
        } catch (\Throwable $e) {
            // 静默熔断
        }

        return $response;
    }
}
