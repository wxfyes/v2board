<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Log as LogModel;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;

class SystemController extends Controller
{
    public function getSystemStatus()
    {
        return response([
            'data' => [
                'schedule' => $this->getScheduleStatus(),
                'horizon' => $this->getHorizonStatus(),
                'schedule_last_runtime' => Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null))
            ]
        ]);
    }

    public function getQueueWorkload(WorkloadRepository $workload)
    {
        return response([
            'data' => collect($workload->get())->sortBy('name')->values()->toArray()
        ]);
    }

    protected function getScheduleStatus():bool
    {
        return (time() - 120) < Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null));
    }

    protected function getHorizonStatus():bool
    {
        if (! $masters = app(MasterSupervisorRepository::class)->all()) {
            return false;
        }

        return collect($masters)->contains(function ($master) {
            return $master->status === 'paused';
        }) ? false : true;
    }

    public function getQueueStats()
    {
        return response([
            'data' => [
                'failedJobs' => app(JobRepository::class)->countRecentlyFailed(),
                'jobsPerMinute' => app(MetricsRepository::class)->jobsProcessedPerMinute(),
                'pausedMasters' => $this->totalPausedMasters(),
                'periods' => [
                    'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                    'recentJobs' => config('horizon.trim.recent'),
                ],
                'processes' => $this->totalProcessCount(),
                'queueWithMaxRuntime' => app(MetricsRepository::class)->queueWithMaximumRuntime(),
                'queueWithMaxThroughput' => app(MetricsRepository::class)->queueWithMaximumThroughput(),
                'recentJobs' => app(JobRepository::class)->countRecent(),
                'status' => $this->getHorizonStatus(),
                'wait' => collect(app(WaitTimeCalculator::class)->calculate())->take(1),
            ]
        ]);
    }

    /**
     * Get the total process count across all supervisors.
     *
     * @return int
     */
    protected function totalProcessCount()
    {
        $supervisors = app(SupervisorRepository::class)->all();

        return collect($supervisors)->reduce(function ($carry, $supervisor) {
            return $carry + collect($supervisor->processes)->sum();
        }, 0);
    }

    /**
     * Get the number of master supervisors that are currently paused.
     *
     * @return int
     */
    protected function totalPausedMasters()
    {
        if (! $masters = app(MasterSupervisorRepository::class)->all()) {
            return 0;
        }

        return collect($masters)->filter(function ($master) {
            return $master->status === 'paused';
        })->count();
    }

    public function getSubscribeLog(Request $request) {
        if (!\Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_log')) {
            return response(['data' => [], 'total' => 0]);
        }
        
        try {
            \Illuminate\Support\Facades\Schema::table('v2_subscribe_log', function ($table) {
                $table->index(['user_id', 'created_at'], 'idx_user_created');
            });
        } catch (\Exception $e) {}

        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('page_size') >= 10 ? $request->input('page_size') : 20;
        $builder = \App\Models\SubscribeLog::orderBy('created_at', 'DESC');
        if ($request->input('user_id')) $builder->where('user_id', $request->input('user_id'));
        if ($request->input('ip')) $builder->where('ip', 'LIKE', '%'.$request->input('ip').'%');
        if ($request->input('ua')) $builder->where('ua', 'LIKE', '%'.$request->input('ua').'%');
        $total = $builder->count();
        $res = $builder->forPage($current, $pageSize)->get();
        
        $userIds = $res->pluck('user_id')->unique()->toArray();
        $users = empty($userIds) ? collect([]) : \App\Models\User::whereIn('id', $userIds)->get()->keyBy('id');
        
        $today = strtotime('today');
        $todayCounts = empty($userIds) ? collect([]) : \App\Models\SubscribeLog::whereIn('user_id', $userIds)
            ->where('created_at', '>=', $today)
            ->selectRaw('user_id, count(*) as count')
            ->groupBy('user_id')
            ->pluck('count', 'user_id');

        foreach ($res as $log) {
            $u = $users->get($log->user_id);
            $log->email = $u ? $u->email : '未知用户';
            $log->location = $this->getIpLocation($log->ip);
            $log->today_count = $todayCounts->get($log->user_id) ?? 0;
        }
        return response(['data' => $res, 'total' => $total]);
    }

    public function getTopSubscribeUsers(Request $request) {
        if (!\Illuminate\Support\Facades\Schema::hasTable('v2_subscribe_log')) {
            return response(['data' => [], 'total' => 0]);
        }
        $today = strtotime('today');
        
        $counts = \App\Models\SubscribeLog::where('created_at', '>=', $today)
            ->selectRaw('user_id, count(*) as today_count')
            ->groupBy('user_id')
            ->orderBy('today_count', 'DESC')
            ->limit(50)
            ->get();
            
        $userIds = $counts->pluck('user_id')->toArray();
        $users = empty($userIds) ? collect([]) : \App\Models\User::whereIn('id', $userIds)->get()->keyBy('id');
        
        $res = [];
        foreach ($counts as $countRecord) {
            $userId = $countRecord->user_id;
            
            $latestLog = \App\Models\SubscribeLog::where('user_id', $userId)
                ->where('created_at', '>=', $today)
                ->orderBy('created_at', 'DESC')
                ->first();
                
            if (!$latestLog) continue;
            
            $latestLog->today_count = $countRecord->today_count;
            $u = $users->get($userId);
            $latestLog->email = $u ? $u->email : '未知用户';
            $latestLog->location = $this->getIpLocation($latestLog->ip);
            
            $res[] = $latestLog;
        }
        
        return response(['data' => $res, 'total' => count($res)]);
    }


    private function getIpLocation($ip)
    {
        if (empty($ip) || $ip === '127.0.0.1' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '本地局域网';
        }

        $cacheKey = "ip_loc_" . md5($ip);
        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return \Illuminate\Support\Facades\Cache::get($cacheKey);
        }

        try {
            static $xdbSearcher = null;
            $xdbPath = app_path('Utils/ip2region.xdb');
            if (file_exists($xdbPath)) {
                if (!class_exists('\App\Utils\Ip2RegionSearcher')) {
                    require_once app_path('Utils/Ip2RegionSearcher.php');
                }
                if ($xdbSearcher === null) {
                    $header = \App\Utils\Ip2RegionSearcher::loadHeaderFromFile($xdbPath);
                    $version = \App\Utils\Ip2RegionSearcher::versionFromHeader($header);
                    $cBuff = \App\Utils\Ip2RegionSearcher::loadContentFromFile($xdbPath);
                    $xdbSearcher = \App\Utils\Ip2RegionSearcher::newWithBuffer($version, $cBuff);
                }
                $region = $xdbSearcher->search($ip);
                if ($region) {
                    // ip2region format: 国家|区域|省份|城市|ISP
                    $parts = explode('|', $region);
                    $locationParts = [];
                    foreach ($parts as $part) {
                        if ($part !== '0' && !empty($part)) {
                            $locationParts[] = $part;
                        }
                    }
                    $location = implode('-', array_unique($locationParts));
                    if ($location) {
                        \Illuminate\Support\Facades\Cache::put($cacheKey, $location, 86400 * 30);
                        return $location;
                    }
                }
            }
        } catch (\Exception $e) {}

        try {
            static $reader = null;
            $mmdbPath = storage_path('app/GeoLite2-City.mmdb');
            if (file_exists($mmdbPath) && class_exists('\GeoIp2\Database\Reader')) {
                if ($reader === null) {
                    $reader = new \GeoIp2\Database\Reader($mmdbPath);
                }
                $record = $reader->city($ip);
                $country = $record->country->names['zh-CN'] ?? $record->country->names['en'] ?? '';
                $city = $record->city->names['zh-CN'] ?? $record->city->names['en'] ?? '';
                $location = trim($country . '-' . $city, '-');
                if ($location) {
                    \Illuminate\Support\Facades\Cache::put($cacheKey, $location, 86400 * 30);
                    return $location;
                }
            }
        } catch (\Exception $e) {}

        try {
            $ctx = stream_context_create(['http' => ['timeout' => 1]]);
            $res = @file_get_contents("http://ip-api.com/json/{$ip}?lang=zh-CN", false, $ctx);
            if ($res) {
                $data = json_decode($res, true);
                if (isset($data['status']) && $data['status'] === 'success') {
                    $country = $data['country'] ?? '';
                    $region = $data['regionName'] ?? '';
                    $city = $data['city'] ?? '';
                    $isp = $data['isp'] ?? '';
                    $org = $data['org'] ?? '';

                    $ispLower = strtolower($isp . ' ' . $org);
                    $ispCn = '';
                    if (strpos($ispLower, 'chinanet') !== false || strpos($ispLower, 'telecom') !== false) {
                        $ispCn = '电信';
                    } elseif (strpos($ispLower, 'unicom') !== false) {
                        $ispCn = '联通';
                    } elseif (strpos($ispLower, 'mobile') !== false || strpos($ispLower, 'cmnet') !== false) {
                        $ispCn = '移动';
                    } elseif (strpos($ispLower, 'amazon') !== false || strpos($ispLower, 'aws') !== false) {
                        $ispCn = 'AWS';
                    } elseif (strpos($ispLower, 'alibaba') !== false || strpos($ispLower, 'aliyun') !== false) {
                        $ispCn = '阿里云';
                    } elseif (strpos($ispLower, 'tencent') !== false) {
                        $ispCn = '腾讯云';
                    } elseif (strpos($ispLower, 'cloudflare') !== false) {
                        $ispCn = 'Cloudflare';
                    } else {
                        $ispCn = $isp;
                    }

                    if ($country === '中国') {
                        $loc = $region;
                        if ($city && $city !== $region) {
                            $loc .= '-' . $city;
                        }
                        $location = trim('中国-' . $loc . '-' . $ispCn);
                    } else {
                        $loc = $country;
                        if ($region && $region !== $country) {
                            $loc .= '-' . $region;
                        }
                        $location = trim($loc . '-' . $ispCn);
                    }

                    \Illuminate\Support\Facades\Cache::put($cacheKey, $location, 86400 * 30);
                    return $location;
                }
            }
        } catch (\Exception $e) {
        }
        return '未知位置';
    }

    public function getSystemLog(Request $request) {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('page_size') >= 10 ? $request->input('page_size') : 10;
        $builder = LogModel::orderBy('created_at', 'DESC')
            ->setFilterAllowKeys('level');
        $total = $builder->count();
        $res = $builder->forPage($current, $pageSize)
            ->get();
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }
    public function getLoginLog(\Illuminate\Http\Request $request) {
        if (!\Illuminate\Support\Facades\Schema::hasTable('v2_user_login_log')) {
            return response(['data' => [], 'total' => 0]);
        }
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('page_size') >= 10 ? $request->input('page_size') : 10;
        
        $builder = \Illuminate\Support\Facades\DB::table('v2_user_login_log')->orderBy('created_at', 'DESC');
        
        if ($request->input('email')) {
            $builder->where('email', $request->input('email'));
        }
        if ($request->input('ip')) {
            $builder->where('ip', $request->input('ip'));
        }
        if ($request->input('type')) {
            $builder->where('type', 'like', '%' . $request->input('type') . '%');
        }

        $total = $builder->count();
        $res = $builder->forPage($current, $pageSize)->get();

        foreach ($res as $log) {
            $log->location = $this->getIpLocation($log->ip);
        }

        return response([
            'data' => $res,
            'total' => $total
        ]);
    }
}
