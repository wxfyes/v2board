<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class StaffRoute
{
    public function map(Registrar $router)
    {
        // 🛡️ 安全加固：员工模式（Staff）为上游遗留的废弃半成品功能，所有官方前端与双管理后台均无调用。
        // 为彻底消除未排除管理员越权改密等潜在安全攻击面，已全面注销关闭所有 /api/v1/staff/* 路由。
        return;
    }
}
