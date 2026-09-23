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
        
        $today = strtotime('today');
        foreach ($res as $log) {
            $u = \App\Models\User::find($log->user_id);
            $log->email = $u ? $u->email : '未知用户';
            $log->location = $this->getIpLocation($log->ip);
            
            $log->today_count = \App\Models\SubscribeLog::where('user_id', $log->user_id)
                                ->where('created_at', '>=', $today)
                                ->count();
        }
        return response(['data' => $res, 'total' => $total]);
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
