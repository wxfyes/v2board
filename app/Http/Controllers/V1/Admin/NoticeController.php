<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NoticeSave;
use App\Models\Notice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        return response([
            'data' => Notice::orderBy('id', 'DESC')->get()
        ]);
    }

    public function save(NoticeSave $request)
    {
        $data = $request->only([
            'title',
            'content',
            'img_url',
            'tags'
        ]);
        try {
            if (!$request->input('id')) {
                Notice::create($data);
            } else {
                $notice = Notice::find($request->input('id'));
                if (!$notice) {
                    abort(500, '公告不存在');
                }
                $notice->update($data);
            }
        } catch (\Exception $e) {
            abort(500, '保存失败: ' . $e->getMessage());
        }
        return response([
            'data' => true
        ]);
    }



    public function show(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数有误');
        }
        $notice = Notice::find($request->input('id'));
        if (!$notice) {
            abort(500, '公告不存在');
        }
        $notice->show = $notice->show ? 0 : 1;
        if (!$notice->save()) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        $notice = Notice::find($request->input('id'));
        if (!$notice) {
            abort(500, '公告不存在');
        }
        if (!$notice->delete()) {
            abort(500, '删除失败');
        }
        return response([
            'data' => true
        ]);
    }
}
