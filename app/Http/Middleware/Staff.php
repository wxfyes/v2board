<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;

class Staff
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
        // 🛡️ 安全加固：员工模式已被系统永久禁用，直接返回 404
        abort(404);
    }
}
