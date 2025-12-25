<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;

class User
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
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        $token = $request->input('token') ?? $request->header('token');

        // 1. 优先尝试 JWT 认证
        if ($authorization) {
            $user = AuthService::decryptAuthData($authorization);
            if ($user) {
                $request->merge(['user' => $user]);
                return $next($request);
            }
        }

        // 2. 回退到 Token 认证（用于客户端）
        if ($token) {
            $user = \App\Models\User::where('token', $token)->first();
            if ($user) {
                $request->merge(['user' => $user->toArray()]);
                return $next($request);
            }
        }

        abort(403, '未登录或登陆已过期');
    }
}
