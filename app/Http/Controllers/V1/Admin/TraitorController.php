<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\User;

class TraitorController extends Controller
{
    public function fetch(Request $request)
    {
        $traitorListPath = storage_path('traitor_list.json');
        if (!file_exists($traitorListPath)) {
            return response([
                'data' => [
                    'emails' => '',
                    'ips' => '',
                    'match_count' => 0
                ]
            ]);
        }
        
        $data = json_decode(@file_get_contents($traitorListPath), true);
        $emails = $data['emails'] ?? [];
        
        $matchCount = 0;
        $matchedEmails = [];
        if (!empty($emails)) {
            $matchedEmails = User::whereIn('email', $emails)->pluck('email')->toArray();
            $matchCount = count($matchedEmails);
        }

        return response([
            'data' => [
                'emails' => implode("\n", $emails),
                'ips' => implode("\n", $data['ips'] ?? []),
                'match_count' => $matchCount,
                'matched_emails' => $matchedEmails
            ]
        ]);
    }

    public function save(Request $request)
    {
        $emails = $request->input('emails', '');
        $ips = $request->input('ips', '');
        
        $emailsArray = array_filter(array_map('trim', explode("\n", $emails)));
        $ipsArray = array_filter(array_map('trim', explode("\n", $ips)));

        $traitorListPath = storage_path('traitor_list.json');
        
        $data = [
            'emails' => array_values(array_unique($emailsArray)),
            'ips' => array_values(array_unique($ipsArray))
        ];

        if (!@file_put_contents($traitorListPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
            abort(500, '保存失败，请检查 storage 目录权限');
        }

        return response([
            'data' => true
        ]);
    }
}
