<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\IpBlacklist;
use App\Models\SecurityLog;
use App\Services\SecurityService;
use Illuminate\Http\Request;

class SecurityController extends Controller
{
    /**
     * 获取入侵感知日志列表
     */
    public function logs(Request $request)
    {
        $current = (int)($request->input('current') ?: $request->input('page') ?: 1);
        $pageSize = (int)($request->input('pageSize') ?: $request->input('page_size') ?: 15);
        if ($pageSize > 100) $pageSize = 100;

        $builder = SecurityLog::orderBy('id', 'desc');

        if ($request->filled('ip')) {
            $builder->where('ip', 'like', '%' . trim($request->input('ip')) . '%');
        }

        if ($request->filled('status_code')) {
            $builder->where('status_code', (int)$request->input('status_code'));
        }

        if ($request->filled('keyword')) {
            $keyword = trim($request->input('keyword'));
            $builder->where(function ($query) use ($keyword) {
                $query->where('ip', 'like', "%{$keyword}%")
                    ->orWhere('location', 'like', "%{$keyword}%")
                    ->orWhere('url', 'like', "%{$keyword}%")
                    ->orWhere('rule_name', 'like', "%{$keyword}%");
            });
        }

        $total = $builder->count();
        $items = $builder->forPage($current, $pageSize)->get();

        // 仅比对当页 IP 是否已被拉黑，极大节约内存与数据库开销
        $pageIps = $items->pluck('ip')->unique()->toArray();
        $bannedIps = !empty($pageIps) ? IpBlacklist::whereIn('ip', $pageIps)->pluck('ip')->toArray() : [];
        foreach ($items as $item) {
            $item->is_banned = in_array($item->ip, $bannedIps);
        }

        return response([
            'data' => $items,
            'total' => $total,
            'current' => $current,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * 获取统计数据
     */
    public function stats()
    {
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $todayCount = SecurityLog::where('created_at', '>=', $todayStart)->count();
        $totalBlocked = SecurityLog::whereIn('status_code', [403, 404])->count();
        $blacklistCount = IpBlacklist::count();

        // 攻击排名前 5 的 IP
        $topIps = SecurityLog::selectRaw('ip, location, count(*) as count')
            ->where('created_at', '>=', $todayStart)
            ->groupBy('ip', 'location')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        return response([
            'data' => [
                'today_count' => $todayCount,
                'total_blocked' => $totalBlocked,
                'blacklist_count' => $blacklistCount,
                'top_ips' => $topIps,
            ]
        ]);
    }

    /**
     * 一键封禁 IP
     */
    public function ban(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'reason' => 'nullable|string|max:255',
        ]);

        $ip = trim($request->input('ip'));
        $reason = $request->input('reason') ?: '入侵感知后台一键封禁';
        $user = $request->user;
        $operator = $user ? $user->email : 'admin';

        $success = SecurityService::banIp($ip, $reason, $operator);
        if (!$success) {
            abort(500, '封禁失败，请检查 IP 格式');
        }

        return response(['data' => true]);
    }

    /**
     * 解封 IP
     */
    public function unban(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
        ]);

        $ip = trim($request->input('ip'));
        SecurityService::unbanIp($ip);

        return response(['data' => true]);
    }

    /**
     * 获取黑名单列表
     */
    public function blacklist(Request $request)
    {
        $items = IpBlacklist::orderBy('id', 'desc')->get();
        return response(['data' => $items]);
    }

    /**
     * 清理 7 天前的安全日志
     */
    public function dropLogs()
    {
        $expireTime = time() - (86400 * 7);
        SecurityLog::where('created_at', '<', $expireTime)->delete();
        return response(['data' => true]);
    }
}
