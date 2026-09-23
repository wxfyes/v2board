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
        $url = 'https://github.com/lionsoul2014/ip2region/raw/master/data/ip2region_v4.xdb';
        $dest = app_path('Utils/ip2region.xdb');

        $this->info("Starting to download ip2region.xdb...");

        try {
            // 120 秒传输超时
            $response = Http::timeout(120)->get($url);

            if ($response->successful()) {
                file_put_contents($dest, $response->body());
                $this->info("Successfully downloaded and saved ip2region.xdb to {$dest}");
            } else {
                $this->error("Failed to download ip2region.xdb. Status code: " . $response->status());
            }
        } catch (\Throwable $e) {
            $this->error("Failed to download ip2region.xdb: " . $e->getMessage());
        }

        return 0;
    }
}
