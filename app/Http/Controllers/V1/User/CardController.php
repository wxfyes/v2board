<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\CardProduct;
use App\Models\Card;
use App\Models\Order;
use Illuminate\Http\Request;

class CardController extends Controller
{
    public function products(Request $request)
    {
        $products = CardProduct::where('show', 1)
            ->orderBy('sort', 'ASC')
            ->get();

        foreach ($products as $k => $v) {
            // Count remaining stock
            $products[$k]->stock = Card::where('product_id', $v->id)
                ->where('status', 0)
                ->count();
        }

        return response([
            'data' => $products
        ]);
    }

    public function fetchByOrder(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        if (empty($tradeNo)) {
            abort(500, '订单号不能为空');
        }

        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user['id'])
            ->first();

        if (!$order) {
            abort(500, '订单不存在');
        }

        if ($order->status !== 3) {
            abort(500, '订单尚未完成支付，无法提取');
        }

        if ($order->period !== 'card') {
            abort(500, '非虚拟卡密商品订单');
        }

        $product = CardProduct::find($order->plan_id);
        
        // Find the card allocated to this order
        $card = Card::where('order_id', $order->id)
            ->where('user_id', $request->user['id'])
            ->first();

        if (!$card) {
            abort(500, '卡密尚未分配或出库失败，请联系管理员');
        }

        return response([
            'data' => [
                'trade_no' => $order->trade_no,
                'product_name' => $product ? $product->name : '未知商品',
                'description' => $product ? $product->description : '',
                'code' => $card->code,
                'created_at' => $card->updated_at // 售出/分发时间
            ]
        ]);
    }
}
