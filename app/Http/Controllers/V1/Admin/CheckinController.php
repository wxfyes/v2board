<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserCheckinLog;
use Illuminate\Http\Request;

class CheckinController extends Controller
{
    private function formatBytes($bytes)
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        $val = round($bytes / pow(1024, $i), 2);
        return $val . ' ' . $units[$i];
    }

    public function fetch(Request $request)
    {
        $current = (int)($request->input('current') ?: 1);
        $pageSize = (int)($request->input('pageSize') ?: 20);
        if ($pageSize < 10) $pageSize = 10;
        if ($pageSize > 100) $pageSize = 100;

        $builder = UserCheckinLog::orderBy('id', 'DESC');

        // 筛选：用户 ID
        if ($request->filled('user_id')) {
            $builder->where('user_id', (int)$request->input('user_id'));
        }

        // 筛选：邮箱模糊或精确匹配
        if ($request->filled('email')) {
            $email = trim($request->input('email'));
            $userIds = User::where('email', 'like', "%{$email}%")->pluck('id');
            if ($userIds->isNotEmpty()) {
                $builder->whereIn('user_id', $userIds);
            } else {
                $builder->where('user_id', 0);
            }
        }

        // 筛选：指定单一日期 (YYYY-MM-DD)
        if ($request->filled('date')) {
            $builder->where('checkin_date', trim($request->input('date')));
        }

        // 筛选：日期范围
        if ($request->filled('start_date')) {
            $builder->where('checkin_date', '>=', trim($request->input('start_date')));
        }
        if ($request->filled('end_date')) {
            $builder->where('checkin_date', '<=', trim($request->input('end_date')));
        }

        $total = $builder->count();
        $logs = $builder->forPage($current, $pageSize)->get();

        // 关联用户与套餐信息
        $userIds = $logs->pluck('user_id')->unique()->filter();
        $users = $userIds->isNotEmpty()
            ? User::whereIn('id', $userIds)->select(['id', 'email', 'telegram_id'])->get()->keyBy('id')
            : collect();

        $plans = Plan::all()->keyBy('id');

        $list = [];
        foreach ($logs as $log) {
            $user = $users->get($log->user_id);
            $plan = $plans->get($log->plan_id);

            $createdAt = is_numeric($log->created_at) ? (int)$log->created_at : strtotime($log->created_at);

            $list[] = [
                'id' => $log->id,
                'user_id' => $log->user_id,
                'email' => $user ? $user->email : '已注销账号',
                'telegram_id' => $user ? $user->telegram_id : null,
                'plan_id' => $log->plan_id,
                'plan_name' => $plan ? $plan->name : '未知套餐',
                'traffic' => $log->traffic,
                'traffic_formatted' => $this->formatBytes($log->traffic),
                'checkin_date' => $log->checkin_date,
                'month' => $log->month,
                'created_at' => $createdAt,
                'created_at_formatted' => date('Y-m-d H:i:s', $createdAt),
                'time_formatted' => date('H:i:s', $createdAt),
            ];
        }

        // 统计总览数据（Daily & Monthly Overview）
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $currentMonth = date('Y-m');

        $todayUserCount = UserCheckinLog::where('checkin_date', $today)->count();
        $todayTraffic = (int)UserCheckinLog::where('checkin_date', $today)->sum('traffic');

        $yesterdayUserCount = UserCheckinLog::where('checkin_date', $yesterday)->count();
        $yesterdayTraffic = (int)UserCheckinLog::where('checkin_date', $yesterday)->sum('traffic');

        $monthUserCount = UserCheckinLog::where('month', $currentMonth)->count();
        $monthTraffic = (int)UserCheckinLog::where('month', $currentMonth)->sum('traffic');

        $totalCount = UserCheckinLog::count();
        $totalTraffic = (int)UserCheckinLog::sum('traffic');

        return response([
            'data' => $list,
            'total' => $total,
            'statistics' => [
                'today_date' => $today,
                'today_user_count' => $todayUserCount,
                'today_traffic' => $todayTraffic,
                'today_traffic_formatted' => $this->formatBytes($todayTraffic),
                'yesterday_user_count' => $yesterdayUserCount,
                'yesterday_traffic_formatted' => $this->formatBytes($yesterdayTraffic),
                'month_user_count' => $monthUserCount,
                'month_traffic' => $monthTraffic,
                'month_traffic_formatted' => $this->formatBytes($monthTraffic),
                'total_count' => $totalCount,
                'total_traffic' => $totalTraffic,
                'total_traffic_formatted' => $this->formatBytes($totalTraffic),
            ]
        ]);
    }
}
