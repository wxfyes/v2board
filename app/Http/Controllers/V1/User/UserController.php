<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserRedeemGiftCard;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Giftcard;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuthService;
use App\Services\OrderService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function getActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->getSessions()
        ]);
    }

    public function removeActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $authService = new AuthService($user);
        return response([
            'data' => $authService->removeSession($request->input('session_id'))
        ]);
    }

    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user['id'] ? true : false
        ];
        if ($request->user['is_admin']) {
            $data['is_admin'] = true;
        }
        return response([
            'data' => $data
        ]);
    }

    public function changePassword(UserChangePassword $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        // 检查用户是否关联了社交登录
        $hasSocial = DB::table('user_socials')->where('user_id', $user->id)->exists();

        // 只有当没有关联社交账号时，才严格校验旧密码是否正确
        if (!$hasSocial) {
            if (!Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $request->input('old_password'),
                $user->password
            )) {
                abort(500, __('The old password is wrong'));
            }
        }

        $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            abort(500, __('Save failed'));
        }
        $authService = new AuthService($user);
        $authService->removeAllSession();
        return response([
            'data' => true
        ]);
    }

    public function newPeriod(Request $request) 
    {
        $allowNewPeriod = (bool)config('v2board.allow_new_period', 0);
        // 兼容支持主题设置中的 enable_new_period 开关
        if (!$allowNewPeriod) {
            $theme = config('v2board.frontend_theme', 'v2nexus');
            $themeEnable = config("theme.{$theme}.enable_new_period", config('theme.v2nexus.enable_new_period', config('theme.ez.enable_new_period', '1')));
            if ($themeEnable !== '0' && $themeEnable !== 0) {
                $allowNewPeriod = true;
            }
        }
        if (!$allowNewPeriod) {
            abort(500, __('Renewal is not allowed'));
        }
        DB::beginTransaction();
        try {
            $user = User::lockForUpdate()->find($request->user['id']);
            if (!$user) {
                abort(500, __('The user does not exist'));
            }
            $usedTraffic = $user->u + $user->d;
            $remainingTraffic = $user->transfer_enable - $usedTraffic;
            // 流量耗尽容差：已用 >= 总量，或者剩余小于 500MB，或者剩余比例小于 1%
            $isExhausted = ($usedTraffic >= $user->transfer_enable) 
                || ($remainingTraffic <= 524288000) 
                || ($user->transfer_enable > 0 && ($remainingTraffic / $user->transfer_enable) <= 0.01);

            if (!$isExhausted) {
                abort(500, __('You have not used up your traffic, you cannot renew your subscription'));
            }
            $userService = new UserService();
            $reset_day = $userService->getResetDay($user);
            if ($reset_day === null) {
                abort(500, __('You do not allow to renew the subscription'));
            }
            unset($user->plan);
            $reset_period = $userService->getResetPeriod($user);
            if ($reset_period === null) {
                abort(500, __('You do not allow to renew the subscription'));
            }

            // 准入门槛锁 1：严格排斥一次性按量套餐 (无限期套餐绝对禁止开启新周期，防无限套现)
            $plan = Plan::find($user->plan_id);
            if (!$plan || $user->expired_at === null || $user->expired_at <= 0 || (int)$plan->reset_traffic_method === 2) {
                abort(500, '一次性按量套餐无使用期限，不支持提前开启新周期，用尽请购买流量重置包');
            }

            // 准入门槛锁 2：剩余有效时长必须 >= 60 天（至少还保有下一个完整周期）
            if (($user->expired_at - time()) < (60 * 86400)) {
                abort(500, '套餐剩余有效时长不足 60 天（已进入末期周期），无法提前透支下月流量，请购买流量重置包或续费套餐');
            }

            // 准入门槛锁 3：严禁月付与一次性用户，必须是季付及以上长期预付费套餐 (quarter/half_year/year/etc)
            $userPeriod = $this->getUserActivePeriod($user);

            // 方案 B 阶梯授权配额 (自然月内最大允许提前开启次数)
            $periodLimitMap = [
                'quarter_price'   => 1, // 季付：当月最多透支 1 次
                'half_year_price' => 2, // 半年付：当月最多透支 2 次
                'year_price'      => 3, // 年付：当月最多透支 3 次
                'two_year_price'  => 3, // 2年付：当月最多透支 3 次
                'three_year_price'=> 3  // 3年付：当月最多透支 3 次
            ];

            if (!$userPeriod || $userPeriod === 'onetime_price') {
                abort(500, '一次性按量套餐无使用期限，不支持提前开启新周期，用尽请购买流量重置包');
            }

            if ($userPeriod === 'month_price') {
                abort(500, '提前开启新周期为季付、半年付、年付等长期订阅会员专属特权，月付套餐请购买流量重置包或续费套餐');
            }

            if (!isset($periodLimitMap[$userPeriod])) {
                abort(500, '提前开启新周期为季付、半年付、年付等长期订阅会员专属特权，请购买流量重置包或续费套餐');
            }

            $maxAllowedTimes = $periodLimitMap[$userPeriod];
            $monthKey = 'USER_NEW_PERIOD_COUNT_' . $user->id . '_' . date('Ym');
            $usedTimes = (int)Cache::get($monthKey, 0);

            if ($usedTimes >= $maxAllowedTimes) {
                abort(500, "您本月提前开启新周期的次数已达上限（{$maxAllowedTimes}次），无法继续透支，请购买流量重置包或续费套餐");
            }

            // 精准计算扣减后的新到期时间（自然月/自然年回退，自动适应 28/29/30/31 天并防月末溢出）
            $newExpiredAt = null;
            if ($reset_period === 1 || $reset_period === 30) {
                // 自然月精准回退（大月扣31天、小月扣30天、2月扣28/29天，保持原到期日不变）
                $y = (int)date('Y', $user->expired_at);
                $m = (int)date('n', $user->expired_at);
                $d = (int)date('j', $user->expired_at);
                $his = date('H:i:s', $user->expired_at);

                $m--;
                if ($m < 1) {
                    $m = 12;
                    $y--;
                }
                // 获取目标月份的最大实际天数 (防止如 3月31日 减 1 个月溢出到 3月3日)
                $maxD = (int)date('t', strtotime("{$y}-" . sprintf('%02d', $m) . "-01"));
                $targetD = min($d, $maxD);
                $newExpiredAt = strtotime("{$y}-" . sprintf('%02d', $m) . '-' . sprintf('%02d', $targetD) . " {$his}");
            } elseif ($reset_period === 12 || $reset_period === 365) {
                // 自然年精准回退（平年扣365天、闰年扣366天）
                $y = (int)date('Y', $user->expired_at) - 1;
                $m = (int)date('n', $user->expired_at);
                $d = (int)date('j', $user->expired_at);
                $his = date('H:i:s', $user->expired_at);

                $maxD = (int)date('t', strtotime("{$y}-" . sprintf('%02d', $m) . "-01"));
                $targetD = min($d, $maxD);
                $newExpiredAt = strtotime("{$y}-" . sprintf('%02d', $m) . '-' . sprintf('%02d', $targetD) . " {$his}");
            } else {
                abort(500, __('Invalid reset period'));
            }

            // 防白嫖校验：扣减后的新到期时间必须仍然大于当前时间至少 1 小时，确保不会被白嫖或倒贴变过期
            if (!$newExpiredAt || $newExpiredAt <= (time() + 3600)) {
                abort(500, __('You do not have enough time to renew your subscription'));
            }

            $plan = Plan::find($user->plan_id);
            $updateData = [
                'expired_at' => $newExpiredAt,
                'u' => 0,
                'd' => 0
            ];
            if ($plan) {
                $updateData['transfer_enable'] = $plan->transfer_enable * 1073741824;
            }
            if (!$user->update($updateData)) {
                throw new \Exception(__('Save failed'));
            }

            // 累加当月已开启次数，缓存保留到下个月初
            $secondsUntilEndOfMonth = strtotime(date('Y-m-01 00:00:00', strtotime('+1 month'))) - time() + 86400;
            Cache::put($monthKey, $usedTimes + 1, $secondsUntilEndOfMonth);

            DB::commit();
            return response([
                'data' => true
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }
    }

    public function redeemgiftcard(UserRedeemGiftCard $request)
    {
        DB::beginTransaction();

        try {
            $user = User::find($request->user['id']);
            if (!$user) {
                abort(500, __('The user does not exist'));
            }
            $giftcard_input = $request->giftcard;
            $giftcard = Giftcard::where('code', $giftcard_input)->first();

            if (!$giftcard) {
                abort(500, __('The gift card does not exist'));
            }

            $currentTime = time();
            if ($giftcard->started_at && $currentTime < $giftcard->started_at) {
                abort(500, __('The gift card is not yet valid'));
            }

            if ($giftcard->ended_at && $currentTime > $giftcard->ended_at) {
                abort(500, __('The gift card has expired'));
            }

            if ($giftcard->limit_use !== null) {
                if (!is_numeric($giftcard->limit_use) || $giftcard->limit_use <= 0) {
                    abort(500, __('The gift card usage limit has been reached'));
                }
            }

            $usedUserIds = $giftcard->used_user_ids ? json_decode($giftcard->used_user_ids, true) : [];
            if (!is_array($usedUserIds)) {
                $usedUserIds = [];
            }

            if (in_array($user->id, $usedUserIds)) {
                abort(500, __('The gift card has already been used by this user'));
            }

            $usedUserIds[] = $user->id;
            $giftcard->used_user_ids = json_encode($usedUserIds);

            switch ($giftcard->type) {
                case 1:
                    $user->balance += $giftcard->value;
                    break;
                case 2:
                    if ($user->expired_at !== null) {
                        if ($user->expired_at <= $currentTime) {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        } else {
                            $user->expired_at += $giftcard->value * 86400;
                        }
                    } else {
                        abort(500, __('Not suitable gift card type'));
                    }
                    break;
                case 3:
                    $user->transfer_enable += $giftcard->value * 1073741824;
                    break;
                case 4:
                    $user->u = 0;
                    $user->d = 0;
                    break;
                case 5:
                    if ($user->plan_id == null || ($user->expired_at !== null && $user->expired_at < $currentTime)) {
                        $plan = Plan::where('id', $giftcard->plan_id)->first();
                        $user->plan_id = $plan->id;
                        $user->group_id = $plan->group_id;
                        $user->transfer_enable = $plan->transfer_enable * 1073741824;
                        $user->device_limit = $plan->device_limit;
                        $user->u = 0;
                        $user->d = 0;
                        if($giftcard->value == 0) {
                            $user->expired_at = null;
                        } else {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        }
                    } else {
                        abort(500, __('Not suitable gift card type'));
                    }
                    break;
                default:
                    abort(500, __('Unknown gift card type'));
            }

            if ($giftcard->limit_use !== null) {
                $giftcard->limit_use -= 1;
            }

            if (!$user->save() || !$giftcard->save()) {
                throw new \Exception(__('Save failed'));
            }

            DB::commit();

            return response([
                'data' => true,
                'type' => $giftcard->type,
                'value' => $giftcard->value
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }
    }

    public function info(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'email',
                'transfer_enable',
                'device_limit',
                'last_login_at',
                'created_at',
                'banned',
                'auto_renewal',
                'remind_expire',
                'remind_traffic',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid',
                'password_salt'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $user['avatar_url'] = 'https://cravatar.cn/avatar/' . md5($user->email) . '?s=64&d=identicon';
        $user['need_set_password'] = ($user->password_salt === 'social');
        unset($user['password_salt']);
        $this->appendSubscriptionMeta($user, $request->user['id']);
        return response([
            'data' => $user
        ]);
    }

    public function getStat(Request $request)
    {
        $stat = [
            Order::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            Ticket::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            User::where('invite_user_id', $request->user['id'])
                ->count()
        ];
        return response([
            'data' => $stat
        ]);
    }

    public function getSubscribe(Request $request)
    {
        $userId = $request->user['id'];
        $user = User::where('id', $userId)
            ->select([
                'id',
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'device_limit',
                'email',
                'uuid'
            ])
            ->first();
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                abort(500, __('Subscription plan does not exist'));
            }
        }

        //统计在线设备
        $countalive = 0;
        $ips_array = Cache::get('ALIVE_IP_USER_' . $userId);
        if ($ips_array) {
            $countalive = $ips_array['alive_ip'];
        }
        $user['alive_ip'] = $countalive;

        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);

        // 统一注入订阅周期与方案 3 准入门槛策略
        $this->appendSubscriptionMeta($user, $userId);

        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        return response([
            'data' => $user
        ]);
    }

    /**
     * 获取用户当前生效的订阅周期（兼容自适应：当前套餐订单 -> 历史最近订单 -> 长期有效时长智能推断）
     */
    private function getUserActivePeriod($user)
    {
        $userId = isset($user->id) ? $user->id : (isset($user['id']) ? $user['id'] : null);
        $planId = isset($user->plan_id) ? $user->plan_id : (isset($user['plan_id']) ? $user['plan_id'] : null);
        $expiredAt = isset($user->expired_at) ? $user->expired_at : (isset($user['expired_at']) ? $user['expired_at'] : null);
        $plan = isset($user->plan) ? $user->plan : (isset($user['plan']) ? $user['plan'] : ($planId ? Plan::find($planId) : null));

        if (!$userId || !$planId) {
            return null;
        }

        // 1. 一次性按量套餐无有效期
        if ($expiredAt === null || $expiredAt <= 0 || ($plan && (int)$plan->reset_traffic_method === 2)) {
            return 'onetime_price';
        }

        // 2. 优先查询当前套餐对应的最近一条已完成订单
        $activeOrder = Order::where('user_id', $userId)
            ->where('plan_id', $planId)
            ->where('status', 3)
            ->whereIn('period', [
                'month_price',
                'quarter_price',
                'half_year_price',
                'year_price',
                'two_year_price',
                'three_year_price',
                'onetime_price'
            ])
            ->orderBy('id', 'desc')
            ->first();

        // 3. 若无，查询该用户最近一条有效周期订单兜底
        if (!$activeOrder) {
            $activeOrder = Order::where('user_id', $userId)
                ->where('status', 3)
                ->whereIn('period', [
                    'month_price',
                    'quarter_price',
                    'half_year_price',
                    'year_price',
                    'two_year_price',
                    'three_year_price',
                    'onetime_price'
                ])
                ->orderBy('id', 'desc')
                ->first();
        }

        if ($activeOrder && $activeOrder->period) {
            return $activeOrder->period;
        }

        // 4. 智能自适应推断（针对管理员后台直接开通/改时间、无订单记录的账号）
        if ($expiredAt > time()) {
            $remainingDays = ($expiredAt - time()) / 86400;
            if ($remainingDays >= 500) return 'two_year_price';
            if ($remainingDays >= 200) return 'year_price';
            if ($remainingDays >= 100) return 'half_year_price';
            if ($remainingDays >= 60)  return 'quarter_price';
            return 'month_price';
        }

        return null;
    }

    /**
     * 统一注入订购周期与方案 B 开启新周期阶梯准入门槛
     */
    private function appendSubscriptionMeta(&$user, $userId)
    {
        $planId = isset($user->plan_id) ? $user->plan_id : (isset($user['plan_id']) ? $user['plan_id'] : null);
        $resetPrice = null;
        if ($planId) {
            $plan = isset($user['plan']) && $user['plan'] ? $user['plan'] : (isset($user->plan) && $user->plan ? $user->plan : Plan::find($planId));
            if ($plan && isset($plan->reset_price)) {
                $resetPrice = $plan->reset_price;
            }
        }

        $period = $this->getUserActivePeriod($user);
        $periodMap = [
            'month_price' => '月付',
            'quarter_price' => '季付',
            'half_year_price' => '半年付',
            'year_price' => '年付',
            'two_year_price' => '2年付',
            'three_year_price' => '3年付',
            'onetime_price' => '一次性按量'
        ];
        $periodName = ($period && isset($periodMap[$period])) ? $periodMap[$period] : null;

        $user['period'] = $period;
        $user['period_name'] = $periodName;

        $allowNewPeriod = (int)config('v2board.allow_new_period', 0);
        if (!$allowNewPeriod) {
            $theme = config('v2board.frontend_theme', 'v2nexus');
            $themeEnable = config("theme.{$theme}.enable_new_period", config('theme.v2nexus.enable_new_period', config('theme.ez.enable_new_period', '1')));
            if ($themeEnable !== '0' && $themeEnable !== 0) {
                $allowNewPeriod = 1;
            }
        }

        // 方案 B 阶梯准入门槛锁：
        // 1. 严格排斥一次性套餐 (无限期套餐绝对禁止开启新周期，防无限套现)
        // 2. 严格排斥月付套餐 (month_price)
        // 3. 剩余有效时长必须 >= 60 天
        // 4. 当月提前开启次数不能超过阶梯配额 (季付1次、半年付2次、年付3次)
        if ($allowNewPeriod) {
            $isQualified = false;
            $periodLimitMap = [
                'quarter_price'   => 1, // 季付：当月最多 1 次
                'half_year_price' => 2, // 半年付：当月最多 2 次
                'year_price'      => 3, // 年付：当月最多 3 次
                'two_year_price'  => 3, // 2年付：当月最多 3 次
                'three_year_price'=> 3  // 3年付：当月最多 3 次
            ];

            $expiredAt = isset($user->expired_at) ? $user->expired_at : (isset($user['expired_at']) ? $user['expired_at'] : null);
            $plan = isset($user['plan']) && $user['plan'] ? $user['plan'] : (isset($user->plan) && $user->plan ? $user->plan : ($planId ? Plan::find($planId) : null));
            $isOnetime = ($expiredAt === null || $expiredAt <= 0 || ($plan && (int)$plan->reset_traffic_method === 2));

            if (!$isOnetime && $planId && $expiredAt && ($expiredAt - time()) >= (60 * 86400)) {
                if ($period && isset($periodLimitMap[$period])) {
                    $maxTimes = $periodLimitMap[$period];
                    $monthKey = 'USER_NEW_PERIOD_COUNT_' . $userId . '_' . date('Ym');
                    $usedTimes = (int)Cache::get($monthKey, 0);
                    if ($usedTimes < $maxTimes) {
                        $isQualified = true;
                    }
                }
            }
            $allowNewPeriod = $isQualified ? 1 : 0;
        }
        $user['allow_new_period'] = $allowNewPeriod;
        $user['reset_price'] = $resetPrice;
    }

    public function unbindTelegram(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if (!$user->update(['telegram_id' => null])) {
            abort(500, __('Unbind telegram failed'));
        }
        return response([
            'data' => true
        ]);
    }

    public function resetSecurity(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            abort(500, __('Reset failed'));
        }
        return response([
            'data' => Helper::getSubscribeUrl($user['token'])
        ]);
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'auto_renewal',
            'remind_expire',
            'remind_traffic'
        ]);

        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        try {
            $user->update($updateData);
        } catch (\Exception $e) {
            abort(500, __('Save failed'));
        }

        return response([
            'data' => true
        ]);
    }

    public function transfer(UserTransfer $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }
        if ($request->input('transfer_amount') > $user->commission_balance) {
            abort(500, __('Insufficient commission balance'));
        }
        DB::beginTransaction();
        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $request->user['id'];
        $order->plan_id = 0;
        $order->period = 'deposit';
        $order->trade_no = Helper::generateOrderNo();
        $order->total_amount = $request->input('transfer_amount');

        $orderService->setOrderType($user);
        $orderService->setInvite($user);

        $user->commission_balance = $user->commission_balance - $request->input('transfer_amount');
        $user->balance = $user->balance + $request->input('transfer_amount');
        $order->status = 3;
        $order->total_amount = 0;
        $order->surplus_amount = $request->input('transfer_amount');
        $order->callback_no = '佣金划转 Commission transfer';
        if (!$order->save()||!$user->save()) {
            DB::rollback();
            abort(500, __('Transfer failed'));
        }

        DB::commit();

        return response([
            'data' => true
        ]);
    }

    public function getQuickLoginUrl(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            abort(500, __('The user does not exist'));
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 60);
        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (config('v2board.app_url')) {
            $url = config('v2board.app_url') . $redirect;
        } else {
            $url = url($redirect);
        }
        return response([
            'data' => $url
        ]);
    }
}
