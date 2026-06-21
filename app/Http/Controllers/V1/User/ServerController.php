<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ServerController extends Controller
{
    public function fetch(Request $request)
    {
        $user = User::find($request->user['id']);
        $servers = [];
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);

            foreach ($servers as &$server) {

                $server['host'] = '已加密保护';

                $server['port'] = 8888;

                if (isset($server['tls_settings'])) {

                    $server['tls_settings'] = [];

                }

                if (isset($server['encryption_settings'])) {

                    $server['encryption_settings'] = [];

                }

                if (isset($server['obfs_settings'])) {

                    $server['obfs_settings'] = [];

                }

            }

            unset($server);
        }
        $eTag = sha1(json_encode(array_column($servers, 'cache_key')));
        if (strpos($request->header('If-None-Match'), $eTag) !== false ) {
            abort(304);
        }

        return response([
            'data' => $servers
        ])->header('ETag', "\"{$eTag}\"");
    }
}
