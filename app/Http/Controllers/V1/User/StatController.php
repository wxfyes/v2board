<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\StatUser;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatController extends Controller
{
    public function getTrafficLog(Request $request)
    {
        $userId = $request->user['id'];
        $startTimestamp = $this->calcPeriodStart($userId);

        $builder = StatUser::select([
            'u',
            'd',
            'record_at',
            'user_id',
            'server_rate'
        ])
            ->where('user_id', $userId)
            ->where('record_at', '>=', $startTimestamp)
            ->orderBy('record_at', 'DESC');

        return response([
            'data' => $builder->get()
        ]);
    }

    /**
     * 计算当前用户套餐计费周期的起始时间戳（按上次重置日）
     * 具备绝对防御性设计：如遇任何异常或无套餐用户，自动安全降级为当月1号，保证0报错
     */
    private function calcPeriodStart($userId): int
    {
        $fallback = strtotime(date('Y-m-01 00:00:00'));
        try {
            $user = User::find($userId);
            if (!$user || !$user->plan_id) {
                return $fallback;
            }

            $plan = Plan::find($user->plan_id);
            if (!$plan) {
                return $fallback;
            }

            $method = $plan->reset_traffic_method;
            if ($method === null) {
                $method = config('v2board.reset_traffic_method', 0);
            }
            $method = (int)$method;

            switch ($method) {
                // 方式 0: 每月1号重置 (默认)
                case 0:
                    return $fallback;

                // 方式 1: 按到期日(账单日)每月自动重置
                case 1:
                    if (!$user->expired_at) {
                        return $fallback;
                    }
                    $resetDay = (int)date('d', $user->expired_at);
                    $currentDay = (int)date('d');
                    if ($currentDay >= $resetDay) {
                        $year = (int)date('Y');
                        $month = (int)date('m');
                    } else {
                        $lastMonthTime = strtotime('first day of -1 month');
                        $year = (int)date('Y', $lastMonthTime);
                        $month = (int)date('m', $lastMonthTime);
                    }
                    $daysInMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
                    $actualDay = min($resetDay, $daysInMonth);
                    $calcTime = strtotime(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $actualDay));
                    return ($calcTime && $calcTime <= time()) ? $calcTime : $fallback;

                // 方式 2: 不重置 (一次性流量包，拉取全部明细)
                case 2:
                    return 0;

                // 方式 3: 每年1月1日重置
                case 3:
                    return strtotime(date('Y-01-01 00:00:00'));

                // 方式 4: 每年到期日重置
                case 4:
                    if (!$user->expired_at) {
                        return $fallback;
                    }
                    $md = date('m-d', $user->expired_at);
                    $thisYearReset = strtotime(date("Y-{$md} 00:00:00"));
                    if ($thisYearReset <= time()) {
                        return $thisYearReset;
                    }
                    return strtotime(date("Y-{$md} 00:00:00", strtotime('-1 year')));

                default:
                    return $fallback;
            }
        } catch (\Throwable $e) {
            return $fallback;
        }
    }
}
