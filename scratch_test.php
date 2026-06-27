<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;

$idMin = 10000;
$uaKeyword = 'clash-verge/733';

$query = User::where('id', '>', $idMin)->whereNotNull('client_type');

if (!empty($uaKeyword)) {
    $keywords = preg_split('/[\/\s]+/', $uaKeyword);
    echo "Split keywords: " . json_encode($keywords) . "\n";
    foreach ($keywords as $kw) {
        $kw = trim($kw);
        if (!empty($kw)) {
            $query->where('client_type', 'like', '%' . $kw . '%');
        }
    }
}

// 打印 SQL
echo "SQL: " . $query->toSql() . "\n";
echo "Bindings: " . json_encode($query->getBindings()) . "\n";

$users = $query->orderBy('id', 'desc')->limit(1000)->get(['id', 'email', 'client_type', 'banned']);
echo "Matched count: " . $users->count() . "\n";
foreach ($users as $u) {
    echo "ID: {$u->id}, Email: {$u->email}\n";
}
