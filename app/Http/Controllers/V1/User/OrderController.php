<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\OrderSave;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PlanService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function fetch(Request $request)
    {
        $model = Order::where('user_id', $request->user['id'])
            ->orderBy('created_at', 'DESC');
        if ($request->input('status') !== null) {
            $model->where('status', $request->input('status'));
        }
        $order = $model->get();
        $plan = Plan::get();
        $cardProducts = \App\Models\CardProduct::get();
        for ($i = 0; $i < count($order); $i++) {
            if ($order[$i]['period'] === 'card') {
                foreach ($cardProducts as $product) {
                    if ($order[$i]['plan_id'] === $product->id) {
                        $order[$i]['plan'] = [
                            'id' => $product->id,
                            'name' => $product->name,
                            'card' => $product->price,
                            'transfer_enable' => null
                        ];
                        break;
                    }
                }
            } else {
                for ($x = 0; $x < count($plan); $x++) {
                    if ($order[$i]['plan_id'] === $plan[$x]['id']) {
                        $order[$i]['plan'] = $plan[$x];
                    }
                }
            }
        }
        return response([
            'data' => $order->makeHidden(['id', 'user_id'])
        ]);
    }

    public function detail(Request $request)
    {
        $order = Order::where('user_id', $request->user['id'])
            ->where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist or has been paid'));
        }
        if ($order->plan_id == 0) {
            $order['plan'] = [
                'id' => 0,
                'name' => 'deposit'
            ];
            $order->bounus = $this->getbounus($order->total_amount);
            $order->get_amount = $order->total_amount + $order->bounus;

            return response([
                'data' => $order
            ]);
        }
        if ($order->period === 'card') {
            $product = \App\Models\CardProduct::find($order->plan_id);
            $order['plan'] = [
                'id' => $product ? $product->id : 0,
                'name' => $product ? $product->name : '未知商品',
                'card' => $product ? $product->price : 0,
                'transfer_enable' => null
            ];
            return response([
                'data' => $order
            ]);
        }
        $order['plan'] = Plan::find($order->plan_id);
        $order['try_out_plan_id'] = (int)config('v2board.try_out_plan_id');
        if (!$order['plan']) {
            abort(500, __('Subscription plan does not exist'));
        }
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        return response([
            'data' => $order
        ]);
    }

    public function save(OrderSave $request)
    {
        try {
            $user = User::find($request->user['id']);
            $orders = Order::where('user_id', $user->id)
                ->where('period', '!=', 'reset_price')
                ->where('period', '!=', 'onetime_price')
                ->where('period', '!=', 'deposit')
                ->where('period', '!=', 'card')
                ->where('status', 3)
                ->get()
                ->toArray();
            
            $debug = [
                'user_plan_id' => $user->plan_id,
                'user_expired_at' => $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : null,
                'current_time' => date('Y-m-d H:i:s'),
                'orders_found' => count($orders),
                'order_details' => []
            ];

            $orderAmountSum = 0;
            $orderMonthSum = 0;
            $lastValidateAt = null;
            $STR_TO_TIME = [
                'month_price' => 1,
                'quarter_price' => 3,
                'half_year_price' => 6,
                'year_price' => 12,
                'two_year_price' => 24,
                'three_year_price' => 36
            ];

            foreach ($orders as $item) {
                if (!isset($STR_TO_TIME[$item['period']])) {
                    $debug['order_details'][] = [
                        'id' => $item['id'],
                        'period' => $item['period'],
                        'status' => 'skipped (not in STR_TO_TIME)'
                    ];
                    continue;
                }
                $period = $STR_TO_TIME[$item['period']];
                $orderEndTime = strtotime("+{$period} month", $item['created_at']);
                $is_expired = $orderEndTime < time();
                
                if (!$is_expired) {
                    $lastValidateAt = $item['created_at'] > $lastValidateAt ? $item['created_at'] : $lastValidateAt;
                    $orderMonthSum += $period;
                    $orderAmountSum += $item['total_amount'] + $item['balance_amount'] + (int)($item['surplus_amount'] ?? 0) - (int)($item['refund_amount'] ?? 0);
                }

                $debug['order_details'][] = [
                    'id' => $item['id'],
                    'plan_id' => $item['plan_id'],
                    'period' => $item['period'],
                    'created_at' => date('Y-m-d H:i:s', $item['created_at']),
                    'end_time' => date('Y-m-d H:i:s', $orderEndTime),
                    'total_amount' => $item['total_amount'],
                    'balance_amount' => $item['balance_amount'],
                    'surplus_amount' => $item['surplus_amount'],
                    'refund_amount' => $item['refund_amount'],
                    'skipped' => $is_expired
                ];
            }

            $debug['lastValidateAt'] = $lastValidateAt ? date('Y-m-d H:i:s', $lastValidateAt) : null;
            $debug['orderMonthSum'] = $orderMonthSum;
            $debug['orderAmountSum'] = $orderAmountSum;
            
            if ($lastValidateAt !== null) {
                $expiredAtByOrder = strtotime("+{$orderMonthSum} month", $lastValidateAt);
                $debug['expiredAtByOrder'] = date('Y-m-d H:i:s', $expiredAtByOrder);
                $debug['cond1_expiredAtByOrder_lt_time'] = $expiredAtByOrder < time();
                $debug['cond2_expiredAtByUser_lt_time'] = $user->expired_at < time();
            }

            @file_put_contents(public_path('surplus_debug.json'), json_encode($debug, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            abort(500, '调试信息已保存！请访问您的网站域名 /surplus_debug.json 查看完整日志，并将里面的内容复制发给我。');

            $userService = new UserService();
            if ($userService->isNotCompleteOrderByUserId($request->user['id'])) {
                abort(500, __('You have an unpaid or pending order, please try again later or cancel it'));
            }
            if ($request->input('period') === 'card') {
                $product = \App\Models\CardProduct::find($request->input('plan_id'));
                if (!$product || !$product->show) {
                    abort(500, '商品不存在或已下架');
                }
                $stock = \App\Models\Card::where('product_id', $product->id)->where('status', 0)->count();
                if ($stock <= 0) {
                    abort(500, '该商品暂时缺货');
                }

                DB::beginTransaction();
                $order = new Order();
                $orderService = new OrderService($order);
                $order->user_id = $request->user['id'];
                $order->plan_id = $product->id;
                $order->period = 'card';
                $order->trade_no = Helper::generateOrderNo();
                $order->total_amount = $product->price;
                $order->type = 5;

                if ($request->input('coupon_code')) {
                    $couponService = new CouponService($request->input('coupon_code'));
                    if (!$couponService->use($order)) {
                        DB::rollBack();
                        abort(500, __('Coupon failed'));
                    }
                    $order->coupon_id = $couponService->getId();
                }

                $user = User::find($request->user['id']);
                if ($user->balance > 0 && $order->total_amount > 0) {
                    $remainingBalance = $user->balance - $order->total_amount;
                    $userService = new UserService();
                    if ($remainingBalance > 0) {
                        if (!$userService->addBalance($order->user_id, - $order->total_amount)) {
                            DB::rollBack();
                            abort(500, __('Insufficient balance'));
                        }
                        $order->balance_amount = $order->total_amount;
                        $order->total_amount = 0;
                    } else {
                        if (!$userService->addBalance($order->user_id, - $user->balance)) {
                            DB::rollBack();
                            abort(500, __('Insufficient balance'));
                        }
                        $order->balance_amount = $user->balance;
                        $order->total_amount -= $user->balance;
                    }
                }

                $orderService->setInvite($user);

                if (!$order->save()) {
                    DB::rollback();
                    abort(500, __('Failed to create order'));
                }

                DB::commit();

                return response([
                    'data' => $order->trade_no
                ]);
            }
            if ($request->input('plan_id') == 0) {
                $amount = $request->input('deposit_amount');
                if ($amount <= 0) {
                    abort(500, __('Failed to create order, deposit amount must be greater than 0'));
                }
                if ($amount >= 9999999 ) {
                    abort(500, __('Deposit amount too large, please contact the administrator'));
                }
                $user = User::find($request->user['id']);
                DB::beginTransaction();
                $order = new Order();
                $orderService = new OrderService($order);
                $order->user_id = $request->user['id'];
                $order->plan_id = $request->input('plan_id');
                $order->period = 'deposit';
                $order->trade_no = Helper::generateOrderNo();
                $order->total_amount = $amount;
                
                $orderService->setOrderType($user);
                $orderService->setInvite($user);

                if (!$order->save()) {
                    DB::rollback();
                    abort(500, __('Failed to create order'));
                }
        
                DB::commit();
        
                return response([
                    'data' => $order->trade_no
                ]);
            }
            $planService = new PlanService($request->input('plan_id'));

            $plan = $planService->plan;
            $user = User::find($request->user['id']);

            if (!$plan) {
                abort(500, __('Subscription plan does not exist'));
            }

            if ($user->plan_id !== $plan->id && !$planService->haveCapacity() && $request->input('period') !== 'reset_price') {
                abort(500, __('Current product is sold out'));
            }

            if ($plan[$request->input('period')] === NULL) {
                abort(500, __('This payment period cannot be purchased, please choose another period'));
            }

            if ($request->input('period') === 'reset_price') {
                if (!$userService->isAvailable($user) || $plan->id !== $user->plan_id) {
                    abort(500, __('Subscription has expired or no active subscription, unable to purchase Data Reset Package'));
                }
            }

            if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
                if ($request->input('period') !== 'reset_price') {
                    abort(500, __('This subscription has been sold out, please choose another subscription'));
                }
            }

            if (!$plan->renew && $user->plan_id == $plan->id && $request->input('period') !== 'reset_price') {
                abort(500, __('This subscription cannot be renewed, please change to another subscription'));
            }


            if (!$plan->show && $plan->renew && !$userService->isAvailable($user)) {
                abort(500, __('This subscription has expired, please change to another subscription'));
            }

            DB::beginTransaction();
            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $request->user['id'];
            $order->plan_id = $plan->id;
            $order->period = $request->input('period');
            $order->trade_no = Helper::generateOrderNo();
            $order->total_amount = $plan[$request->input('period')];

            if ($request->input('coupon_code')) {
                $couponService = new CouponService($request->input('coupon_code'));
                if (!$couponService->use($order)) {
                    DB::rollBack();
                    abort(500, __('Coupon failed'));
                }
                $order->coupon_id = $couponService->getId();
            }

            $orderService->setVipDiscount($user);
            $orderService->setOrderType($user);

            if ($user->balance > 0 && $order->total_amount > 0) {
                $remainingBalance = $user->balance - $order->total_amount;
                $userService = new UserService();
                if ($remainingBalance > 0) {
                    if (!$userService->addBalance($order->user_id, - $order->total_amount)) {
                        DB::rollBack();
                        abort(500, __('Insufficient balance'));
                    }
                    $order->balance_amount = $order->total_amount;
                    $order->total_amount = 0;
                } else {
                    if (!$userService->addBalance($order->user_id, - $user->balance)) {
                        DB::rollBack();
                        abort(500, __('Insufficient balance'));
                    }
                    $order->balance_amount = $user->balance;
                    $order->total_amount -= $user->balance;
                }
            }

            $orderService->setInvite($user);

            if (!$order->save()) {
                DB::rollback();
                abort(500, __('Failed to create order'));
            }

            DB::commit();

            return response([
                'data' => $order->trade_no
            ]);
        } catch (\Throwable $e) {
            abort(500, 'Order save error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    public function checkout(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $method = $request->input('method');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->where('status', 0)
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist or has been paid'));
        }
        // free process
        if ($order->total_amount <= 0) {
            $orderService = new OrderService($order);
            if (!$orderService->paid($order->trade_no)) abort(500, '');
            return response([
                'type' => -1,
                'data' => true
            ]);
        }
        $payment = Payment::find($method);
        if (!$payment || $payment->enable !== 1) abort(500, __('Payment method is not available'));
        $paymentService = new PaymentService($payment->payment, $payment->id);
        $order->handling_amount = NULL;
        if ($payment->handling_fee_fixed || $payment->handling_fee_percent) {
            $order->handling_amount = round(($order->total_amount * ($payment->handling_fee_percent / 100)) + $payment->handling_fee_fixed);
        }
        $order->payment_id = $method;
        if (!$order->save()) abort(500, __('Request failed, please try again later'));
        $result = $paymentService->pay([
            'trade_no' => $tradeNo,
            'total_amount' => isset($order->handling_amount) ? ($order->total_amount + $order->handling_amount) : $order->total_amount,
            'user_id' => $order->user_id,
            'stripe_token' => $request->input('token')
        ]);
        return response([
            'type' => $result['type'],
            'data' => $result['data']
        ]);
    }

    public function check(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist'));
        }
        return response([
            'data' => $order->status
        ]);
    }

    public function getPaymentMethod()
    {
        $methods = Payment::select([
            'id',
            'name',
            'payment',
            'icon',
            'handling_fee_fixed',
            'handling_fee_percent'
        ])
            ->where('enable', 1)
            ->orderBy('sort', 'ASC')
            ->get();

        return response([
            'data' => $methods
        ]);
    }

    public function cancel(Request $request)
    {
        if (empty($request->input('trade_no'))) {
            abort(500, __('Invalid parameter'));
        }
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$order) {
            abort(500, __('Order does not exist'));
        }
        if ($order->status !== 0) {
            abort(500, __('You can only cancel pending orders'));
        }
        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            abort(500, __('Cancel failed'));
        }
        return response([
            'data' => true
        ]);
    }

    private function getbounus($total_amount) {
        $deposit_bounus = config('v2board.deposit_bounus', []);
        if (empty($deposit_bounus) || $deposit_bounus[0] === null) {
            return 0;
        }
        $add = 0;
        foreach ($deposit_bounus as $tier) {
            list($amount, $bounus) = explode(':', $tier);
            $amount = (float)$amount * 100;
            $bounus = (float)$bounus * 100;
            $amount = (int)$amount;
            $bounus = (int)$bounus;
            if ($total_amount >= $amount) {
                $add = max($add, $bounus);
            }
        }
        return $add;
    }
}
