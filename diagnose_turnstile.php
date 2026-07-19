<?php
// 载入 Laravel 环境
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$secret = config('v2board.recaptcha_key');
$sitekey = config('v2board.recaptcha_site_key');

echo "V2board Recaptcha/Turnstile 配置诊断工具\n";
echo "==========================================\n";
echo "Secret Key 长度: " . strlen($secret) . " 字符\n";
echo "Secret Key 预览: " . substr($secret, 0, 10) . "...\n";
echo "Site Key 长度: " . strlen($sitekey) . " 字符\n";
echo "Site Key 预览: " . substr($sitekey, 0, 10) . "...\n";

// 如果有传入 Token
$token = isset($argv[1]) ? $argv[1] : '';
if (empty($token)) {
    echo "\n提示：你可以通过命令行运行 `php diagnose_turnstile.php <TOKEN>` 来测试校验 Token。\n";
    exit;
}

echo "\n开始校验 Token: " . substr($token, 0, 15) . "...\n";

try {
    $response = \Illuminate\Support\Facades\Http::withoutVerifying()->asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'secret' => $secret,
        'response' => $token
    ]);
    $resJson = $response->json();
    echo "Cloudflare 返回状态码: " . $response->status() . "\n";
    echo "Cloudflare 返回内容: " . json_encode($resJson) . "\n";
} catch (\Exception $e) {
    echo "校验发生异常: " . $e->getMessage() . "\n";
}
