<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class Client
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        // 1. 🛡️ 强制校验 User-Agent，拦截空 UA 或者是脚本/爬虫工具（如 Python、curl、wget 等）
        $userAgent = $request->header('User-Agent');
        if (empty($userAgent) || trim($userAgent) === '') {
            $reason = 'User-Agent 请求头为空（疑似自写脚本拉取）';
            $this->sendTgAlert($request, $reason);
            \Log::warning('Blocked subscription request due to EMPTY User-Agent from IP: ' . $this->getRealIp($request));
            abort(400, 'Invalid User-Agent');
        }

        $uaLower = strtolower($userAgent);
        $bannedUAs = ['python', 'curl', 'wget', 'go-http-client', 'okhttp', 'postman', 'urllib', 'aiohttp', 'scrapy', 'node-fetch', 'axios', 'libcurl', 'java'];
        foreach ($bannedUAs as $bannedUa) {
            if (strpos($uaLower, $bannedUa) !== false) {
                $reason = "使用命令行/开发库 UA 请求: {$userAgent}";
                $this->sendTgAlert($request, $reason);
                \Log::warning('Blocked script/crawler subscription request. UA: ' . $userAgent . ' from IP: ' . $this->getRealIp($request));
                abort(403, 'Access Denied');
            }
        }

        // 2. 🛡️ 彻底关闭 JWT 登录态越权拉取订阅的后门，强制拉取订阅时必须携带 token 参数
        $token = $request->input('token');
        if (empty($token)) {
            $reason = '拉取订阅请求中未携带 token 参数';
            $this->sendTgAlert($request, $reason);
            abort(403, 'token is null');
        }
        $submethod = (int) config('v2board.show_subscribe_method', 0);
        switch ($submethod) {
            case 0:
                break;
            case 1:
                if (!Cache::has("otpn_{$token}")) {
                    $reason = "非法的 OTP Token (Token: {$token})";
                    $this->sendTgAlert($request, $reason);
                    abort(403, 'token is error');
                }
                $usertoken = Cache::pull("otpn_{$token}");
                Cache::forget("otp_{$usertoken}");
                $token = $usertoken;
                break;
            case 2:
                $usertoken = Cache::get("totp_{$token}");
                if (!$usertoken) {
                    $timestep = (int) config('v2board.show_subscribe_expire', 5) * 60;
                    $counter = floor(time() / $timestep);
                    $counterBytes = pack('N*', 0) . pack('N*', $counter);
                    $idhash = Helper::base64DecodeUrlSafe($token);
                    if (strpos($idhash, ':') === false) {
                        $reason = "非法的 TOTP 格式 (Token: {$token})";
                        $this->sendTgAlert($request, $reason);
                        abort(403, 'token is error');
                    }
                    $parts = explode(':', $idhash, 2);
                    [$userid, $clienthash] = $parts;
                    if (!$userid || !$clienthash) {
                        $reason = "解析 TOTP 用户 ID 失败 (Token: {$token})";
                        $this->sendTgAlert($request, $reason);
                        abort(403, 'token is error');
                    }
                    $user = User::where('id', $userid)->select('token')->first();
                    if (!$user) {
                        $reason = "TOTP 关联的数据库用户不存在 (UserID: {$userid})";
                        $this->sendTgAlert($request, $reason);
                        abort(403, 'token is error');
                    }
                    $usertoken = $user->token;
                    $hash = hash_hmac('sha1', $counterBytes, $usertoken, false);
                    if ($clienthash !== $hash) {
                        $reason = "TOTP 签名校验失败 (UserID: {$userid})";
                        $this->sendTgAlert($request, $reason);
                        abort(403, 'token is error');
                    }
                    Cache::put("totp_{$token}", $usertoken, $timestep);
                }
                $token = $usertoken;
                break;
            default:
                break;
        }
        $user = User::where('token', $token)->first();
        if (!$user) {
            $reason = "Token 未匹配到任何系统有效用户 (Token: {$token})";
            $this->sendTgAlert($request, $reason);
            abort(403, 'token is error');
        }
        $request->merge([
            'user' => $user
        ]);
        return $next($request);
    }

    /**
     * 🛡️ 订阅拦截警报实时推送至管理员 Telegram
     */
    private function sendTgAlert($request, $reason)
    {
        try {
            $ip = $this->getRealIp($request);
            $ua = $request->header('User-Agent') ?? '无';
            $path = $request->getRequestUri();
            
            // 协助审计定位具体的泄露账号
            $userEmail = '未匹配到账号';
            $userId = '未知';
            
            $token = $request->input('token');
            if ($token) {
                // 如果是 TOTP 类型，尝试解析出真实的用户 ID
                if (strpos($token, '-') === false && strpos($token, '_') === false && strlen($token) > 32) {
                    $idhash = Helper::base64DecodeUrlSafe($token);
                    if (strpos($idhash, ':') !== false) {
                        $parts = explode(':', $idhash, 2);
                        if (is_numeric($parts[0])) {
                            $user = User::find($parts[0]);
                            if ($user) {
                                $userEmail = $user->email;
                                $userId = $user->id;
                            }
                        }
                    }
                }
                
                // 如果是常规 Token，直接查询
                if ($userId === '未知') {
                    $user = User::where('token', $token)->first();
                    if ($user) {
                        $userEmail = $user->email;
                        $userId = $user->id;
                    }
                }
            }
            
            $msg = "🚨 【天阙订阅拦截警报】\n"
                 . "发现并精准拦截了一次违规拉取订阅请求！\n\n"
                 . "👤 关联账号: `{$userEmail}` (ID: {$userId})\n"
                 . "🌐 请求 IP: `{$ip}`\n"
                 . "📝 请求路径: `{$path}`\n"
                 . "💻 User-Agent: `{$ua}`\n"
                 . "❌ 拦截原因: `{$reason}`\n"
                 . "📅 时间: " . date('Y-m-d H:i:s');
            
            // 优先检查 .env 中的独立安全审计机器人配置
            $customToken = env('SECURITY_TG_TOKEN');
            $customChat = env('SECURITY_TG_CHAT');
            
            if (empty($customToken) || empty($customChat)) {
                $envPath = base_path('.env');
                if (file_exists($envPath)) {
                    $envContent = @file_get_contents($envPath);
                    if (!empty($envContent)) {
                        if (empty($customToken) && preg_match('/^SECURITY_TG_TOKEN\s*=\s*(.*)$/m', $envContent, $matches)) {
                            $customToken = trim($matches[1], "\"' ");
                        }
                        if (empty($customChat) && preg_match('/^SECURITY_TG_CHAT\s*=\s*(.*)$/m', $envContent, $matches)) {
                            $customChat = trim($matches[1], "\"' ");
                        }
                    }
                }
            }
            
            if (!empty($customToken) && !empty($customChat)) {
                $url = "https://api.telegram.org/bot{$customToken}/sendMessage";
                $postData = [
                    'chat_id' => $customChat,
                    'text' => $msg,
                    'parse_mode' => 'Markdown'
                ];
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt_array($ch, [
                    CURLOPT_POSTFIELDS => http_build_query($postData),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5
                ]);
                curl_exec($ch);
                curl_close($ch);
            } else if (class_exists('App\Services\TelegramService')) {
                // 如果没有独立配置，则调用系统内置的主 TG 机器人通知管理员
                $telegramService = new \App\Services\TelegramService();
                $telegramService->sendMessageWithAdmin($msg);
            }
        } catch (\Exception $e) {
            \Log::error('Send TG Alert Error: ' . $e->getMessage());
        }
    }

    /**
     * 穿透 CDN 与反向代理获取真实用户公网 IP
     */
    private function getRealIp($request)
    {
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        return $request->ip();
    }
}
