<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\Request;

class InviteController extends Controller
{
    public function save(Request $request)
    {
        if (InviteCode::where('user_id', $request->user['id'])->where('status', 0)->count() >= config('v2board.invite_gen_limit', 5)) {
            abort(500, __('The maximum number of creations has been reached'));
        }
        $inviteCode = new InviteCode();
        $inviteCode->user_id = $request->user['id'];
        $inviteCode->code = Helper::randomChar(8);
        return response([
            'data' => $inviteCode->save()
        ]);
    }

    public function details(Request $request)
    {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('page_size') >= 10 ? $request->input('page_size') : 10;
        $builder = CommissionLog::leftJoin('v2_user', 'v2_commission_log.user_id', '=', 'v2_user.id')
            ->where('v2_commission_log.invite_user_id', $request->user['id'])
            ->where('v2_commission_log.get_amount', '>', 0)
            ->select([
                'v2_commission_log.id',
                'v2_commission_log.user_id',
                'v2_commission_log.trade_no',
                'v2_commission_log.order_amount',
                'v2_commission_log.get_amount',
                'v2_commission_log.created_at',
                'v2_user.email as user_email'
            ])
            ->orderBy('v2_commission_log.created_at', 'DESC');
        $total = $builder->count();
        $details = $builder->forPage($current, $pageSize)
            ->get();
        // 对被邀请人邮箱进行安全性脱敏（如 w***5@gmail.com）
        foreach ($details as $item) {
            if ($item->user_email) {
                $parts = explode('@', $item->user_email);
                $name = $parts[0];
                $domain = isset($parts[1]) ? '@' . $parts[1] : '';
                if (strlen($name) > 3) {
                    $item->user_email = substr($name, 0, 2) . '***' . substr($name, -1) . $domain;
                } elseif (strlen($name) > 1) {
                    $item->user_email = substr($name, 0, 1) . '***' . $domain;
                } else {
                    $item->user_email = $name . '***' . $domain;
                }
            }
        }
        return response([
            'data' => $details,
            'total' => $total
        ]);
    }

    public function fetch(Request $request)
    {
        $codes = InviteCode::where('user_id', $request->user['id'])
            ->where('status', 0)
            ->get();
        $commission_rate = config('v2board.invite_commission', 10);
        $user = User::find($request->user['id']);
        if ($user->commission_rate) {
            $commission_rate = $user->commission_rate;
        }
        $uncheck_commission_balance = (int)Order::where('status', 3)
            ->where('commission_status', 0)
            ->where('invite_user_id', $request->user['id'])
            ->sum('commission_balance');
        if (config('v2board.commission_distribution_enable', 0)) {
            $uncheck_commission_balance = $uncheck_commission_balance * (config('v2board.commission_distribution_l1') / 100);
        }
        $stat = [
            //已注册用户数
            (int)User::where('invite_user_id', $request->user['id'])->count(),
            //有效的佣金
            (int)CommissionLog::where('invite_user_id', $request->user['id'])
                ->sum('get_amount'),
            //确认中的佣金
            $uncheck_commission_balance,
            //佣金比例
            (int)$commission_rate,
            //可用佣金
            (int)$user->commission_balance
        ];
        return response([
            'data' => [
                'codes' => $codes,
                'stat' => $stat
            ]
        ]);
    }
}
