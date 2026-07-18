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
            'GeoLite2-City.mmdb' => 'https://github.com/P3TERX/GeoLite.mmdb/releases/latest/download/GeoLite2-City.mmdb',
            'GeoLite2-ASN.mmdb'  => 'https://github.com/P3TERX/GeoLite.mmdb/releases/latest/download/GeoLite2-ASN.mmdb',
        ];

        $storagePath = storage_path('app/');
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        foreach ($files as $filename => $url) {
            $this->info("Starting to download {$filename}...");
            $dest = $storagePath . $filename;

            try {
                // 120 秒传输超时
                $response = Http::timeout(120)->get($url);

                if ($response->successful()) {
                    file_put_contents($dest, $response->body());
                    $this->info("Successfully downloaded and saved {$filename} to {$dest}");
                } else {
                    $this->error("Failed to download {$filename}. Status code: " . $response->status());
                }
            } catch (\Throwable $e) {
                $this->error("Failed to download {$filename}: " . $e->getMessage());
            }
        }

        return 0;
    }
}
