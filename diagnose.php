<?php
$configPath = __DIR__ . '/storage/tianque_config.json';
if (!file_exists($configPath)) {
    die("Config file not found at: {$configPath}\n");
}
$config = json_decode(file_get_contents($configPath), true);
$url = $config['banned_redirect_url'] ?? '';
if (empty($url)) {
    die("banned_redirect_url is empty in config!\n");
}
echo "Configured URL: " . $url . "\n";

// 根据配置研判转换器
$subconverterEnable = isset($config['subconverter_enable']) ? (bool)$config['subconverter_enable'] : true;
$subconverterUrl = $config['subconverter_url'] ?? 'https://api.wcc.best/sub';

$targetFetchUrl = $url;
if ($subconverterEnable) {
    $apiBase = rtrim($subconverterUrl, '/');
    if (stripos($apiBase, 'bianyuan.xyz') !== false && stripos($apiBase, 'api.bianyuan.xyz') === false) {
        $apiBase = 'https://api.bianyuan.xyz';
    }
    if (strpos($apiBase, 'sub') === false && strpos($apiBase, '?') === false) {
        $apiBase .= '/sub';
    }
    $targetFetchUrl = $apiBase . '?target=clash&url=' . urlencode($url);
}

echo "Subconverter: " . ($subconverterEnable ? "ENABLED ({$subconverterUrl})" : "DISABLED") . "\n";
echo "Testing fetch URL: " . $targetFetchUrl . "\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $targetFetchUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_USERAGENT, 'ClashVerge/1.3.8 Mihomo/1.18.0');
$responseContent = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "\n--- Fetch Results ---\n";
echo "HTTP Code: " . $httpCode . "\n";
echo "CURL Error: " . $error . "\n";
echo "Response Length: " . strlen($responseContent) . "\n";
echo "Snippet (first 500 chars):\n";
echo substr($responseContent, 0, 500) . "\n";
