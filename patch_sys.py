import sys
import os

filepath = 'E:\\GitHub\\v2board\\app\\Http\\Controllers\\V1\\Admin\\SystemController.php'

try:
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    new_method = """    public function getLoginLog(\Illuminate\Http\Request $request) {
        if (!\Illuminate\Support\Facades\Schema::hasTable('v2_user_login_log')) {
            return response(['data' => [], 'total' => 0]);
        }
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('page_size') >= 10 ? $request->input('page_size') : 10;
        
        $builder = \Illuminate\Support\Facades\DB::table('v2_user_login_log')->orderBy('created_at', 'DESC');
        
        if ($request->input('email')) {
            $builder->where('email', $request->input('email'));
        }
        if ($request->input('ip')) {
            $builder->where('ip', $request->input('ip'));
        }
        if ($request->input('type')) {
            $builder->where('type', 'like', '%' . $request->input('type') . '%');
        }

        $total = $builder->count();
        $res = $builder->forPage($current, $pageSize)->get();

        foreach ($res as $log) {
            $log->location = $this->getIpLocation($log->ip);
        }

        return response([
            'data' => $res,
            'total' => $total
        ]);
    }
}"""

    if 'getLoginLog(' not in content:
        content = content.strip()
        if content.endswith('}'):
            content = content[:-1] + new_method + '\n'
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        print('Patched successfully')
    else:
        print('Already exists')
except Exception as e:
    print('Error:', e)
