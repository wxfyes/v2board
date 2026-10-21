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
            // 节点服务器向面板推送通信时，动态学习并记录节点公网出口 IP 到免死白名单
            if (strpos($uri, '/api/v1/server/') !== false) {
                try {
                    $nodeIp = IpHelper::getRealIp($request);
                    if (!empty($nodeIp)) {
                        SecurityService::whitelistNodeIp($nodeIp);
                    }
                } catch (\Throwable $e) {}
            }
            return $next($request);
        }

        $startTime = microtime(true);
        $isMaliciousProbe = false;
        $ruleName = '';

        // 辨识是否为 MOMclash 商业客户端官方流量（UA 包含 TianQueApp 或携带 X-Device-Id 特征）
        $ua = (string)$request->userAgent();
        $isClient = (
            strpos($ua, 'TianQueApp') !== false ||
            strpos($ua, 'com.tianque.app') !== false ||
            $request->hasHeader('X-Device-Id') ||
            strpos((string)$request->header('X-Requested-With'), 'tianque') !== false
        );

        try {
            $ip = IpHelper::getRealIp($request);

            // 1. IP 黑名单校验
            if (SecurityService::isBanned($ip)) {
                // 特权保护：如果该请求来自官方 MOMclash 商业客户端，且调用的仅为常规合法业务，
                // 且该 IP 并非管理员手动永久封禁（仅为自动防御误中），直接予以业务放行，杜绝误杀真实付费用户！
                $isPermBanned = \App\Models\IpBlacklist::where('ip', $ip)->where('operator', '!=', '[系统自动防御]')->exists();
                if ($isClient && !$isPermBanned && !$this->hasExploitPayload($uri)) {
                    // 放行客户端正常业务流量
                } else {
                    SecurityService::log($request, 403, '黑名单IP拦截', 0);
                    abort(403, '您的 IP 已被安全系统封禁 (IP Blocked)');
                }
            }

            // 2. 深度识别真实攻击特征（仅针对明确注入特征）
            if ($this->hasExploitPayload($uri)) {
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

        // 3. 安全事件精准收录
        try {
            $statusCode = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;

            // 仅在明确命中黑客攻击特征、或扫描非法探测路径时收录
            if ($isMaliciousProbe && $statusCode !== 200) {
                $duration = (int)round((microtime(true) - $startTime) * 1000);
                SecurityService::log($request, $statusCode, $ruleName, $duration);
                // 触发高危探测滑动窗口违规判定（60秒内连续3次自动封禁，免死白名单自动豁免）
                SecurityService::recordViolation($ip, 'high_risk', $ruleName, $request->fullUrl());
            } elseif ($statusCode === 404) {
                // 彻底忽略正常浏览器自动请求的 favicon.ico 和 robots.txt，杜绝误报正常用户
                if (strpos($uri, 'favicon.ico') !== false || strpos($uri, 'robots.txt') !== false) {
                    return $response;
                }

                // 核心保护：官方 MOMclash 客户端正常业务请求（如节点切换、版本探测）导致的 404 绝不计入盲扫封禁！
                if ($isClient) {
                    return $response;
                }

                $duration = (int)round((microtime(true) - $startTime) * 1000);
                if (
                    preg_match('/\.(php|jsp|asp|aspx|sh|bash)$/i', $uri) ||
                    (strpos($uri, '/api/v1/') === false && !str_starts_with($uri, '/assets/'))
                ) {
                    SecurityService::log($request, 404, '恶意探针扫描', $duration);
                    // 触发普通404盲扫滑动窗口违规判定（60秒内累计10次自动封禁，免死白名单自动豁免）
                    SecurityService::recordViolation($ip, 'probe_404', '恶意探针扫描', $request->fullUrl());
                }
            }
        } catch (\Throwable $e) {
            // 静默熔断
        }

        return $response;
    }

    private function hasExploitPayload(string $uri): bool
    {
        return strpos($uri, 'auth_data%5B') !== false ||
               strpos($uri, 'auth_data[') !== false ||
               strpos($uri, 'getUserInfoById') !== false;
    }
}
