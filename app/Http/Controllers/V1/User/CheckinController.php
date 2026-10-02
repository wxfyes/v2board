<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserCheckinLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckinController extends Controller
{
    /**
     * 流量单位友好格式化 (B/KB/MB/GB/TB)
     */
    private function formatBytes($bytes)
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        $val = round($bytes / pow(1024, $i), 2);
        return $val . ' ' . $units[$i];
    }

    /**
     * 计算套餐当月签到总上限 (字节数)
     * 精确规则：1024 GB (1 TB) 对应 24 GB 差额 (比率 24 / 1024 ≈ 2.34375%)
     * 例如：
     *   100 GB  => 2.34 GB  (~2,400 MB)
     *   200 GB  => 4.69 GB  (~4,800 MB)
     *   300 GB  => 7.03 GB  (~7,200 MB)
     *   500 GB  => 11.72 GB (~12,000 MB)
     *   1024 GB => 24.00 GB (24,576 MB)
     */
    private function calculateMonthlyCap(Plan $plan)
    {
        $planGb = (float)$plan->transfer_enable;
        $maxMonthlyGb = $planGb * (24.0 / 1024.0);
        $maxBytes = (int)round($maxMonthlyGb * 1073741824);
        // 保底：即使微型测试套餐也至少可领 100 MB
        return max(104857600, $maxBytes);
    }

    /**
     * 获取用户签到状态与月度进度
     */
    public function status(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, '用户不存在');
        }

        $today = date('Y-m-d');
        $currentMonth = date('Y-m');

        // 是否拥有有效套餐与是否是一次性/不限时套餐
        $plan = $user->plan_id ? Plan::find($user->plan_id) : null;
        $isOneTime = ($user->expired_at === null || $user->expired_at == 0 || ($plan && isset($plan->reset_traffic_method) && $plan->reset_traffic_method === 2));
        $hasActivePlan = ($user->plan_id > 0 && $user->banned == 0 && !$isOneTime && $user->expired_at > time());

        // 今日是否已签到
        $todayLog = UserCheckinLog::where('user_id', $user->id)
            ->where('checkin_date', $today)
            ->first();
        $todayChecked = (bool)$todayLog;

        // 本月已领流量
        $monthUsed = (int)UserCheckinLog::where('user_id', $user->id)
            ->where('month', $currentMonth)
            ->sum('traffic');

        // 本月上限
        $monthLimit = ($plan && $hasActivePlan) ? $this->calculateMonthlyCap($plan) : 0;
        $monthRemain = max(0, $monthLimit - $monthUsed);

        // 计算连续签到天数
        $consecutiveDays = 0;
        $checkDate = $todayChecked ? $today : date('Y-m-d', strtotime('-1 day'));
        while (true) {
            $hasLog = UserCheckinLog::where('user_id', $user->id)
                ->where('checkin_date', $checkDate)
                ->exists();
            if ($hasLog) {
                $consecutiveDays++;
                $checkDate = date('Y-m-d', strtotime($checkDate . ' -1 day'));
            } else {
                break;
            }
        }

        // 最近签到历史 (最近 7 条)
        $history = UserCheckinLog::where('user_id', $user->id)
            ->orderBy('id', 'DESC')
            ->limit(7)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'checkin_date' => $item->checkin_date,
                    'traffic' => $item->traffic,
                    'traffic_formatted' => $this->formatBytes($item->traffic),
                    'created_at' => $item->created_at,
                ];
            });

        return response([
            'data' => [
                'has_active_plan' => $hasActivePlan,
                'is_onetime_plan' => $isOneTime,
                'can_checkin' => $hasActivePlan,
                'plan_name' => $plan ? $plan->name : '未开通套餐',
                'today_checked' => $todayChecked,
                'today_traffic' => $todayLog ? $todayLog->traffic : 0,
                'today_traffic_formatted' => $todayLog ? $this->formatBytes($todayLog->traffic) : null,
                'month_used' => $monthUsed,
                'month_used_formatted' => $this->formatBytes($monthUsed),
                'month_limit' => $monthLimit,
                'month_limit_formatted' => $this->formatBytes($monthLimit),
                'month_remain' => $monthRemain,
                'month_remain_formatted' => $this->formatBytes($monthRemain),
                'month_percentage' => $monthLimit > 0 ? round(($monthUsed / $monthLimit) * 100, 1) : 0,
                'consecutive_days' => $consecutiveDays,
                'history' => $history,
            ]
        ]);
    }

    /**
     * 执行每日签到 (防刷、事务行锁、唯一索引幂等)
     */
    public function doCheckin(Request $request)
    {
        $userId = $request->user['id'];
        $user = User::find($userId);
        if (!$user) {
            abort(500, '用户不存在');
        }

        // 1. 资格校验 (防刷第一道防线)
        if ($user->banned) {
            abort(500, '您的账号已被封禁，无法参与签到');
        }
        if (!$user->plan_id) {
            abort(500, '暂无有效套餐，请先购买周期订阅套餐激活每日签到特权');
        }

        $plan = Plan::find($user->plan_id);
        if (!$plan) {
            abort(500, '所绑定的套餐不存在或已被下架');
        }

        // 拦截一次性/不限时按量套餐
        $isOneTime = ($user->expired_at === null || $user->expired_at == 0 || (isset($plan->reset_traffic_method) && $plan->reset_traffic_method === 2));
        if ($isOneTime) {
            abort(500, '每日打卡为周期订阅会员专属福利，不限时按量套餐暂不支持参与');
        }

        if ($user->expired_at < time()) {
            abort(500, '您的套餐已到期，请先续费套餐后参与签到');
        }

        $maxMonthlyBytes = $this->calculateMonthlyCap($plan);
        $today = date('Y-m-d');
        $currentMonth = date('Y-m');

        $trafficGain = 0;
        $updatedUser = null;

        // 2. 数据库事务 + 行级悲观锁 (防高并发连击与脏读)
        DB::beginTransaction();
        try {
            // 检查今日是否已签
            $todayLog = UserCheckinLog::where('user_id', $userId)
                ->where('checkin_date', $today)
                ->lockForUpdate()
                ->first();
            if ($todayLog) {
                abort(500, '今日已经签到过啦，明天再来吧！');
            }

            // 计算本月累计已领流量
            $monthUsed = (int)UserCheckinLog::where('user_id', $userId)
                ->where('month', $currentMonth)
                ->lockForUpdate()
                ->sum('traffic');

            $remainBytes = $maxMonthlyBytes - $monthUsed;
            if ($remainBytes <= 0) {
                abort(500, '本月签到流量奖励已领满，下月再来吧！');
            }

            // 3. 动态平滑随机算法 (60% ~ 140% 期望波动，平滑控量不超标)
            $daysInMonth = (int)date('t');
            $currentDay = (int)date('j');
            $daysRemaining = max(1, $daysInMonth - $currentDay + 1);

            if ($daysRemaining === 1) {
                // 当月最后一天，全量领取剩余差额
                $trafficGain = $remainBytes;
            } else {
                $avgDailyBytes = (int)($remainBytes / $daysRemaining);
                $minGain = (int)($avgDailyBytes * 0.6);
                $maxGain = (int)($avgDailyBytes * 1.4);
                // 确保单次随机至少 1MB 且上限合理
                $randomVal = mt_rand(max(1048576, $minGain), max(1048576, $maxGain));
                $trafficGain = min($randomVal, $remainBytes);
            }

            // 4. 原子更新用户可用流量 (直接写入 transfer_enable)
            $lockedUser = User::where('id', $userId)->lockForUpdate()->first();
            $lockedUser->transfer_enable += $trafficGain;
            if (!$lockedUser->save()) {
                throw new \Exception('更新用户流量失败');
            }

            // 5. 写入签到流水日志 (依赖数据库唯一约束保证幂等)
            UserCheckinLog::create([
                'user_id' => $userId,
                'plan_id' => $plan->id,
                'traffic' => $trafficGain,
                'checkin_date' => $today,
                'month' => $currentMonth,
            ]);

            DB::commit();
            $updatedUser = $lockedUser;
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            // 捕获 MySQL 1062 唯一键冲突 (并发重放攻击兜底)
            if ($e->errorInfo[1] == 1062) {
                abort(500, '今日已经签到过啦，请勿重复操作');
            }
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }

        // 计算更新后的本月累计与剩余
        $newMonthUsed = $monthUsed + $trafficGain;
        $newMonthRemain = max(0, $maxMonthlyBytes - $newMonthUsed);

        return response([
            'data' => [
                'traffic_gain' => $trafficGain,
                'traffic_gain_formatted' => $this->formatBytes($trafficGain),
                'total_transfer_enable' => $updatedUser->transfer_enable,
                'total_transfer_enable_formatted' => $this->formatBytes($updatedUser->transfer_enable),
                'month_used' => $newMonthUsed,
                'month_used_formatted' => $this->formatBytes($newMonthUsed),
                'month_limit' => $maxMonthlyBytes,
                'month_limit_formatted' => $this->formatBytes($maxMonthlyBytes),
                'month_remain' => $newMonthRemain,
                'month_remain_formatted' => $this->formatBytes($newMonthRemain),
            ]
        ]);
    }
}
