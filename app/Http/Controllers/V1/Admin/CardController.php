<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CardProduct;
use App\Models\Card;
use App\Models\User;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CardController extends Controller
{
    public function products(Request $request)
    {
        $products = CardProduct::orderBy('sort', 'ASC')->get();
        foreach ($products as $k => $v) {
            $products[$k]->total_stock = Card::where('product_id', $v->id)->count();
            $products[$k]->unsold_stock = Card::where('product_id', $v->id)->where('status', 0)->count();
        }
        return response([
            'data' => $products
        ]);
    }

    public function productSave(Request $request)
    {
        $request->validate([
            'name' => 'required',
            'price' => 'required|integer|min:0',
            'show' => 'required|in:0,1',
            'sort' => 'nullable|integer'
        ]);

        $params = $request->only(['name', 'description', 'price', 'show', 'sort']);
        $params['sort'] = $params['sort'] ?? 0;

        if ($request->input('id')) {
            $product = CardProduct::find($request->input('id'));
            if (!$product) {
                abort(500, '该商品不存在');
            }
            if (!$product->update($params)) {
                abort(500, '更新失败');
            }
            return response([
                'data' => true
            ]);
        }

        if (!CardProduct::create($params)) {
            abort(500, '创建失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function productDrop(Request $request)
    {
        $id = $request->input('id');
        if (empty($id)) {
            abort(500, '参数错误');
        }

        $product = CardProduct::find($id);
        if (!$product) {
            abort(500, '该商品不存在');
        }

        // Check if cards exist
        if (Card::where('product_id', $id)->first()) {
            abort(500, '该商品下存在卡密数据，请先删除卡密再删除商品');
        }

        if (!$product->delete()) {
            abort(500, '删除失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function fetch(Request $request)
    {
        $productId = $request->input('product_id');
        if (empty($productId)) {
            abort(500, '商品ID不能为空');
        }

        $model = Card::where('product_id', $productId)->orderBy('id', 'DESC');
        
        if ($request->input('status') !== null) {
            $model->where('status', $request->input('status'));
        }

        $cards = $model->get();

        // Enrich user and order info
        foreach ($cards as $k => $v) {
            if ($v->user_id) {
                $user = User::find($v->user_id);
                $cards[$k]->user_email = $user ? $user->email : '已注销用户';
            } else {
                $cards[$k]->user_email = null;
            }

            if ($v->order_id) {
                $order = Order::find($v->order_id);
                $cards[$k]->trade_no = $order ? $order->trade_no : null;
            } else {
                $cards[$k]->trade_no = null;
            }
        }

        return response([
            'data' => $cards
        ]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer',
            'codes' => 'required|string'
        ]);

        $productId = $request->input('product_id');
        $product = CardProduct::find($productId);
        if (!$product) {
            abort(500, '商品不存在');
        }

        $rawCodes = $request->input('codes');
        // Split by lines
        $lines = preg_split('/\r\n|\r|\n/', $rawCodes);
        $insertedCount = 0;

        DB::beginTransaction();
        try {
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }
                
                Card::create([
                    'product_id' => $productId,
                    'code' => $line,
                    'status' => 0,
                    'user_id' => null,
                    'order_id' => null
                ]);
                $insertedCount++;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, '导入失败: ' . $e->getMessage());
        }

        return response([
            'data' => [
                'count' => $insertedCount
            ]
        ]);
    }

    public function drop(Request $request)
    {
        $id = $request->input('id');
        if (empty($id)) {
            abort(500, '参数错误');
        }

        $card = Card::find($id);
        if (!$card) {
            abort(500, '卡密数据不存在');
        }

        if (!$card->delete()) {
            abort(500, '删除失败');
        }

        return response([
            'data' => true
        ]);
    }
}
