<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class UpdateGeoip extends Command
{
    protected $signature = 'geoip:update';
    protected $description = 'Download and update local GeoLite2 City and ASN database files';

    public function handle()
    {
        $files = [
            'GeoLite2-City.mmdb' => 'https://mirror.ghproxy.com/https://github.com/P3TERX/GeoLite2-Rules/releases/download/latest/GeoLite2-City.mmdb',
            'GeoLite2-ASN.mmdb'  => 'https://mirror.ghproxy.com/https://github.com/P3TERX/GeoLite2-Rules/releases/download/latest/GeoLite2-ASN.mmdb',
        ];

        $storagePath = storage_path('app/');
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        foreach ($files as $filename => $url) {
            $this->info("Starting to download {$filename}...");
            $dest = $storagePath . $filename;

            try {
                // 15 秒连接超时，120 秒传输超时
                $response = Http::timeout(120)->connectTimeout(15)->get($url);

                if ($response->successful()) {
                    file_put_contents($dest, $response->body());
                    $this->info("Successfully downloaded and saved {$filename} to {$dest}");
                } else {
                    $this->error("Failed to download {$filename}. Status code: " . $response->status());
                }
            } catch (\Throwable $e) {
                // 如果 ghproxy 加速节点报错，尝试直接用原始 GitHub 链接下载
                $this->warn("Mirror download failed, trying direct github URL...");
                $directUrl = str_replace('https://mirror.ghproxy.com/', '', $url);
                try {
                    $response = Http::timeout(120)->connectTimeout(15)->get($directUrl);
                    if ($response->successful()) {
                        file_put_contents($dest, $response->body());
                        $this->info("Successfully downloaded {$filename} directly from GitHub!");
                    } else {
                        $this->error("Direct download failed too. Status code: " . $response->status());
                    }
                } catch (\Throwable $ex) {
                    $this->error("Failed to download {$filename}: " . $ex->getMessage());
                }
            }
        }

        return 0;
    }
}
