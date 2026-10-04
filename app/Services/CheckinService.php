<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserCheckinLog;
use Illuminate\Support\Facades\DB;

class CheckinService
{
    public function formatBytes($bytes)
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        $val = round($bytes / pow(1024, $i), 2);
        return $val . ' ' . $units[$i];
    }

    public function getThemeConfig($key, $default = null)
    {
        $theme = config('v2board.frontend_theme', 'v2nexus');
        $val = config("theme.{$theme}.{$key}");
        if ($val !== null && $val !== '') {
            return $val;
        }
        $val = config("theme.v2nexus.{$key}");
        if ($val !== null && $val !== '') {
            return $val;
        }
        $val = config("theme.ez.{$key}");
        if ($val !== null && $val !== '') {
            return $val;
        }
        return $default;
    }

    public function calculateMonthlyCap(Plan $plan)
    {
        $planGb = (float)$plan->transfer_enable;
        $baseMonthlyGb = $planGb * (24.0 / 1024.0);

        $ratePercent = (float)$this->getThemeConfig('checkin_rate_percent', 0);
        $multiplier = max(0.05, 1.0 + ($ratePercent / 100.0));

        $maxMonthlyGb = $baseMonthlyGb * $multiplier;
        $maxBytes = (int)round($maxMonthlyGb * 1073741824);
        return max(10485760, $maxBytes);
    }

    public function getStatus(User $user)
    {
        $checkinEnable = (string)$this->getThemeConfig('checkin_enable', '1') !== '0';
        $plan = $user->plan_id ? Plan::find($user->plan_id) : null;

        $isOneTime = ($user->expired_at === null || $user->expired_at == 0 || (isset($plan->reset_traffic_method) && $plan->reset_traffic_method === 2));
        $hasActivePlan = ($user->plan_id && $plan && !$isOneTime && $user->expired_at > time() && !$user->banned);

        $canCheckin = ($checkinEnable && $hasActivePlan);
        $cantCheckinReason = !$checkinEnable ? '每日打卡签到功能暂未开启' : ($isOneTime ? '一次性不限时套餐暂不支持参与每日签到' : (!$hasActivePlan ? '未开通有效周期订阅套餐' : ''));

        $today = date('Y-m-d');
        $todayLog = UserCheckinLog::where('user_id', $user->id)
            ->where('checkin_date', $today)
            ->first();
        $isTodayChecked = (bool)$todayLog;

        $currentMonth = date('Y-m');
        $monthUsed = (int)UserCheckinLog::where('user_id', $user->id)
            ->where('month', $currentMonth)
            ->sum('traffic');

        $maxMonthlyBytes = $plan ? $this->calculateMonthlyCap($plan) : 0;
        $monthRemain = max(0, $maxMonthlyBytes - $monthUsed);

        $continuousDays = 0;
        for ($i = 0; $i < 30; $i++) {
            $checkDate = date('Y-m-d', strtotime("-{$i} days"));
            $hasLog = UserCheckinLog::where('user_id', $user->id)
                ->where('checkin_date', $checkDate)
                ->exists();
            if ($hasLog) {
                $continuousDays++;
            } else {
                if ($i === 0) continue;
                break;
            }
        }

        return [
            'checkin_enabled' => $checkinEnable,
            'is_today_checked' => $isTodayChecked,
            'today_traffic' => $todayLog ? $todayLog->traffic : 0,
            'today_traffic_formatted' => $todayLog ? $this->formatBytes($todayLog->traffic) : '0 B',
            'can_checkin' => $canCheckin,
            'cant_checkin_reason' => $cantCheckinReason,
            'continuous_days' => $continuousDays,
            'month_used' => $monthUsed,
            'month_used_formatted' => $this->formatBytes($monthUsed),
            'month_limit' => $maxMonthlyBytes,
            'month_limit_formatted' => $this->formatBytes($maxMonthlyBytes),
            'month_remain' => $monthRemain,
            'month_remain_formatted' => $this->formatBytes($monthRemain),
            'user_total_transfer_enable' => $user->transfer_enable,
            'user_total_transfer_enable_formatted' => $this->formatBytes($user->transfer_enable),
        ];
    }

    public function doCheckin(User $user)
    {
        $userId = $user->id;

        $checkinEnable = (string)$this->getThemeConfig('checkin_enable', '1') !== '0';
        if (!$checkinEnable) {
            throw new \Exception('每日打卡签到功能暂未开启');
        }

        if ($user->banned) {
            throw new \Exception('您的账号已被封禁，无法参与签到');
        }
        if (!$user->plan_id) {
            throw new \Exception('暂无有效套餐，请先购买周期订阅套餐激活每日签到特权');
        }

        $plan = Plan::find($user->plan_id);
        if (!$plan) {
            throw new \Exception('所绑定的套餐不存在或已被下架');
        }

        $isOneTime = ($user->expired_at === null || $user->expired_at == 0 || (isset($plan->reset_traffic_method) && $plan->reset_traffic_method === 2));
        if ($isOneTime) {
            throw new \Exception('每日打卡为周期订阅会员专属福利，不限时按量套餐暂不支持参与');
        }

        if ($user->expired_at < time()) {
            throw new \Exception('您的套餐已到期，请先续费套餐后参与签到');
        }

        $maxMonthlyBytes = $this->calculateMonthlyCap($plan);
        $today = date('Y-m-d');
        $currentMonth = date('Y-m');

        DB::beginTransaction();
        try {
            $todayLog = UserCheckinLog::where('user_id', $userId)
                ->where('checkin_date', $today)
                ->lockForUpdate()
                ->first();
            if ($todayLog) {
                throw new \Exception('今日已经签到过啦，明天再来吧！');
            }

            $monthUsed = (int)UserCheckinLog::where('user_id', $userId)
                ->where('month', $currentMonth)
                ->lockForUpdate()
                ->sum('traffic');

            $remainBytes = $maxMonthlyBytes - $monthUsed;
            if ($remainBytes <= 0) {
                throw new \Exception('本月签到流量奖励已领满，下月再来吧！');
            }

            $daysInMonth = (int)date('t');
            $currentDay = (int)date('j');
            $daysRemaining = max(1, $daysInMonth - $currentDay + 1);

            if ($daysRemaining === 1) {
                $trafficGain = $remainBytes;
            } else {
                $avgDailyBytes = (int)($remainBytes / $daysRemaining);
                $minGain = (int)($avgDailyBytes * 0.6);
                $maxGain = (int)($avgDailyBytes * 1.4);
                $randomVal = mt_rand(max(1048576, $minGain), max(1048576, $maxGain));
                $trafficGain = min($randomVal, $remainBytes);
            }

            $lockedUser = User::where('id', $userId)->lockForUpdate()->first();
            $lockedUser->transfer_enable += $trafficGain;
            if (!$lockedUser->save()) {
                throw new \Exception('更新用户流量失败');
            }

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
            if ($e->errorInfo[1] == 1062) {
                throw new \Exception('今日已经签到过啦，请勿重复操作');
            }
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        $newMonthUsed = $monthUsed + $trafficGain;
        $newMonthRemain = max(0, $maxMonthlyBytes - $newMonthUsed);

        return [
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
        ];
    }
}
