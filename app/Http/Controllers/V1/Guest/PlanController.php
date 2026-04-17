<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\PlanService;

class PlanController extends Controller
{
    public function fetch()
    {
        $counts = PlanService::countActiveUsers();
        $plans = Plan::where('show', 1)
            ->orderBy('sort', 'ASC')
            ->get();
        foreach ($plans as $k => $v) {
            if ($plans[$k]->capacity_limit === NULL)
                continue;
            if (!isset($counts[$plans[$k]->id]))
                continue;
            $plans[$k]->capacity_limit = $plans[$k]->capacity_limit - $counts[$plans[$k]->id]->count;
        }
        return response([
            'data' => $plans
        ]);
    }
}
