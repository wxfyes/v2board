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

        // 第一优先级：从数据库持久化保险箱读取（绝对抗 Git 覆盖的底座）
        $dbData = \App\Utils\TraitorDefense::getBackupFromDb('traitor_list');

        $emails = [];
        $ips = [];

        if (!empty($dbData) && is_array($dbData) && (!empty($dbData['emails']) || !empty($dbData['ips']))) {
            $emails = array_values(array_unique(array_filter(array_map('strtolower', $dbData['emails'] ?? []))));
            $ips = array_values(array_unique(array_filter($dbData['ips'] ?? [])));

            // 检查本地磁盘文件是否需要同步恢复
            $fileData = file_exists($traitorListPath) ? json_decode(@file_get_contents($traitorListPath), true) : null;
            $fileEmails = is_array($fileData) ? ($fileData['emails'] ?? []) : [];

            // 若本地文件缺失、或仅为初始占位模板、或与数据库不一致，自动执行自愈回写
            if (!file_exists($traitorListPath) || $fileEmails === ['example_traitor@gmail.com'] || $fileData !== $dbData) {
                @file_put_contents(
                    $traitorListPath,
                    json_encode(['emails' => $emails, 'ips' => $ips], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    LOCK_EX
                );
                \App\Utils\TraitorDefense::clearCache();
            }
        } elseif (file_exists($traitorListPath)) {
            // 第二优先级：数据库暂未生成备份（如初次迁移），读取本地文件并自动双写持久化
            $data = json_decode(@file_get_contents($traitorListPath), true) ?: [];
            $rawEmails = array_values(array_unique(array_filter(array_map('strtolower', $data['emails'] ?? []))));
            $rawIps = array_values(array_unique(array_filter($data['ips'] ?? [])));

            if ($rawEmails !== ['example_traitor@gmail.com']) {
                $emails = $rawEmails;
                $ips = $rawIps;
                \App\Utils\TraitorDefense::saveBackupToDb('traitor_list', ['emails' => $emails, 'ips' => $ips]);
            }
        }
        
        $matchCount = 0;
        $matchedEmails = [];
        if (!empty($emails)) {
            $matchedEmails = User::whereIn('email', $emails)->pluck('email')->toArray();
            $matchCount = count($matchedEmails);
        }

        return response([
            'data' => [
                'emails' => implode("\n", $emails),
                'ips' => implode("\n", $ips),
                'match_count' => $matchCount,
                'matched_emails' => $matchedEmails
            ]
        ]);
    }

    public function save(Request $request)
    {
        $emails = $request->input('emails', '');
        $ips = $request->input('ips', '');
        
        $emailsArray = array_filter(array_map('trim', explode("\n", strtolower($emails))));
        $ipsArray = array_filter(array_map('trim', explode("\n", $ips)));

        $data = [
            'emails' => array_values(array_unique($emailsArray)),
            'ips' => array_values(array_unique($ipsArray))
        ];

        if (!\App\Utils\TraitorDefense::saveTraitorData($data)) {
            abort(500, '保存失败，请检查 storage 目录权限');
        }

        return response([
            'data' => true
        ]);
    }
}
