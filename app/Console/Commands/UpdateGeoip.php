<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class UpdateGeoip extends Command
{
    protected $signature = 'geoip:update';
    protected $description = 'Download and update local ip2region.xdb database file';

    public function handle()
    {
        $files = [
            'ip2region_v4.xdb' => 'https://github.com/lionsoul2014/ip2region/raw/master/data/ip2region_v4.xdb',
            'ip2region_v6.xdb' => 'https://github.com/lionsoul2014/ip2region/raw/master/data/ip2region_v6.xdb',
        ];

        foreach ($files as $filename => $url) {
            $dest = app_path('Utils/' . $filename);
            $this->info("Starting to download {$filename}...");

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
