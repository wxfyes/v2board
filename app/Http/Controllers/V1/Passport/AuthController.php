<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use ReCaptcha\ReCaptcha;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function register(AuthRegister $request)
    {
        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            if ((int)$registerCountByIP >= (int)config('v2board.register_limit_count', 3)) {
                abort(500, __('Register frequently, please try again after :minute minute', [
                    'minute' => config('v2board.register_limit_expire', 60)
                ]));
            }
        }
        if ((int)config('v2board.recaptcha_enable', 0)) {
            $recaptcha = new ReCaptcha(config('v2board.recaptcha_key'));
            $recaptchaResp = $recaptcha->verify($request->input('recaptcha_data'));
            if (!$recaptchaResp->isSuccess()) {
                abort(500, __('Invalid code is incorrect'));
            }
        }
        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $request->input('email'),
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, __('Email suffix is not in the Whitelist'));
            }
        }
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, __('Gmail alias is not supported'));
            }
        }
        if ((int)config('v2board.stop_register', 0)) {
            abort(500, __('Registration has closed'));
        }
        if ((int)config('v2board.invite_force', 0)) {
            if (empty($request->input('invite_code'))) {
                abort(500, __('You must use the invitation code to register'));
            }
        }
        $email = $request->input('email');
        $cacheKeyEmail = is_string($email) ? strtolower(trim($email)) : '';
        if ((int)config('v2board.email_verify', 0)) {
            $inputCode = $request->input('email_code');
            if (!is_string($inputCode) || !preg_match('/^\d{6}$/', $inputCode)) {
                abort(500, __('Incorrect email verification code'));
            }
            $cachedCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
            if ($cachedCode === null || $cachedCode === '' || !hash_equals((string)$cachedCode, $inputCode)) {
                abort(500, __('Incorrect email verification code'));
            }
        }
        $password = $request->input('password');
        $exist = User::where('email', $email)->first();
        if ($exist) {
            abort(500, __('Email already exists'));
        }
        $user = new User();
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if ($request->input('invite_code')) {
            $inviteCode = InviteCode::where('code', $request->input('invite_code'))
                ->where('status', 0)
                ->first();
            if (!$inviteCode) {
                if ((int)config('v2board.invite_force', 0)) {
                    abort(500, __('Invalid invitation code'));
                }
            } else {
                $user->invite_user_id = $inviteCode->user_id ? $inviteCode->user_id : null;
                if (!(int)config('v2board.invite_never_expire', 0)) {
                    $inviteCode->status = 1;
                    $inviteCode->save();
                }
            }
        }

        // try out
        if ((int)config('v2board.try_out_plan_id', 0)) {
            $plan = Plan::find(config('v2board.try_out_plan_id'));
            if ($plan) {
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->device_limit = $plan->device_limit;
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->expired_at = time() + (config('v2board.try_out_hour', 1) * 3600);
                $user->speed_limit = $plan->speed_limit;
            }
        }

        if (!$user->save()) {
            abort(500, __('Register failed'));
        }
        if ((int)config('v2board.email_verify', 0)) {
            Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
        }

        $user->last_login_at = time();
        $user->save();

        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            Cache::put(
                CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()),
                (int)$registerCountByIP + 1,
                (int)config('v2board.register_limit_expire', 60) * 60
            );
        }

        $authService = new AuthService($user);

        return response()->json([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function login(AuthLogin $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if ((int)config('v2board.password_limit_enable', 1)) {
            $passwordErrorCount = (int)Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int)config('v2board.password_limit_count', 5)) {
                abort(500, __('There are too many password errors, please try again after :minute minutes.', [
                    'minute' => config('v2board.password_limit_expire', 60)
                ]));
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, __('Incorrect email or password'));
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $password,
            $user->password)
        ) {
            if ((int)config('v2board.password_limit_enable')) {
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    (int)$passwordErrorCount + 1,
                    60 * (int)config('v2board.password_limit_expire', 60)
                );
            }
            abort(500, __('Incorrect email or password'));
        }

        if ($user->banned) {
            abort(500, __('Your account has been suspended'));
        }

        $authService = new AuthService($user);
        return response([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function token2Login(Request $request)
    {
        if ($request->input('token')) {
            $redirect = '/#/login?verify=' . $request->input('token') . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
            if (config('v2board.app_url')) {
                $location = config('v2board.app_url') . $redirect;
            } else {
                $location = url($redirect);
            }
            return redirect()->to($location)->send();
        }

        if ($request->input('verify')) {
            $key =  CacheKey::get('TEMP_TOKEN', $request->input('verify'));
            $userId = Cache::get($key);
            if (!$userId) {
                abort(500, __('Token error'));
            }
            $user = User::find($userId);
            if (!$user) {
                abort(500, __('The user does not '));
            }
            if ($user->banned) {
                abort(500, __('Your account has been suspended'));
            }
            Cache::forget($key);
            $authService = new AuthService($user);
            return response([
                'data' => $authService->generateAuthData($request)
            ]);
        }
    }

    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) abort(403, '未登录或登陆已过期');

        $user = AuthService::decryptAuthData($authorization);
        if (!$user) abort(403, '未登录或登陆已过期');

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user['id'], 60);
        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (config('v2board.app_url')) {
            $url = config('v2board.app_url') . $redirect;
        } else {
            $url = url($redirect);
        }
        return response([
            'data' => $url
        ]);
    }

    public function forget(AuthForget $request)
    {
        $email     = $request->input('email');
        $inputCode = $request->input('email_code');
        $password  = $request->input('password');

        if (!is_string($email) || !is_string($inputCode) || !is_string($password)) {
            abort(500, __('Incorrect email verification code'));
        }
        if (!preg_match('/^\d{6}$/', $inputCode)) {
            abort(500, __('Incorrect email verification code'));
        }

        $cacheKeyEmail         = strtolower(trim($email));
        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $cacheKeyEmail);
        $forgetRequestLimit    = (int)Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) {
            abort(500, __('Reset failed, Please try again later'));
        }

        $cachedCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
        if ($cachedCode === null || $cachedCode === '' || !hash_equals((string)$cachedCode, $inputCode)) {
            Cache::put($forgetRequestLimitKey, $forgetRequestLimit + 1, 300);
            abort(500, __('Incorrect email verification code'));
        }
        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, __('This email is not registered in the system'));
        }
        $user->password      = password_hash($password, PASSWORD_DEFAULT);
        $user->password_algo = null;
        $user->password_salt = null;
        if (!$user->save()) {
            abort(500, __('Reset failed'));
        }
        Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
        (new AuthService($user))->removeAllSession();
        return response([
            'data' => true
        ]);
    }

    public function socialRedirect(Request $request, $provider)
    {
        if (!in_array($provider, ['google', 'github'])) {
            abort(500, 'Unsupported provider');
        }

        // 动态获取发起登录请求的原始域名
        $referer = $request->header('referer');
        if ($referer) {
            $parsed = parse_url($referer);
            $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'go.tianquege.top') . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        } else {
            $origin = config('v2board.app_url', url('/'));
        }

        return Socialite::driver($provider)
            ->stateless()
            ->with(['state' => base64_encode($origin)])
            ->redirect();
    }

    public function socialCallback(Request $request, $provider)
    {
        if (!in_array($provider, ['google', 'github'])) {
            abort(500, 'Unsupported provider');
        }

        // 从 state 动态恢复发起登录请求的原始域名
        $state = $request->input('state');
        $originUrl = config('v2board.app_url', url('/'));
        if ($state) {
            $decoded = base64_decode($state);
            if (filter_var($decoded, FILTER_VALIDATE_URL)) {
                $parsed = parse_url($decoded);
                $originUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'go.tianquege.top') . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
            }
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Exception $e) {
            return redirect()->to($originUrl . '/#/login?error=' . urlencode('获取授权信息失败'));
        }

        $providerId = $socialUser->getId();
        $email = $socialUser->getEmail();

        if (empty($email)) {
            return redirect()->to($originUrl . '/#/login?error=' . urlencode('第三方账号未提供电子邮箱权限'));
        }

        $user = null;

        $socialRelation = DB::table('user_socials')
            ->where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        if ($socialRelation) {
            $user = User::find($socialRelation->user_id);
        }

        if (!$user) {
            $user = User::where('email', $email)->first();

            if (!$user) {
                if ((int)config('v2board.stop_register', 0)) {
                    return redirect()->to($originUrl . '/#/login?error=' . urlencode('系统已关闭注册'));
                }

                DB::beginTransaction();
                try {
                    $user = new User();
                    $user->email = $email;
                    $user->password = password_hash(Helper::guid(16), PASSWORD_DEFAULT);
                    $user->uuid = Helper::guid(true);
                    $user->token = Helper::guid();

                    // try out
                    if ((int)config('v2board.try_out_plan_id', 0)) {
                        $plan = Plan::find(config('v2board.try_out_plan_id'));
                        if ($plan) {
                            $user->transfer_enable = $plan->transfer_enable * 1073741824;
                            $user->device_limit = $plan->device_limit;
                            $user->plan_id = $plan->id;
                            $user->group_id = $plan->group_id;
                            $user->expired_at = time() + (config('v2board.try_out_hour', 1) * 3600);
                            $user->speed_limit = $plan->speed_limit;
                        }
                    }

                    if (!$user->save()) {
                        throw new \Exception('Register failed');
                    }

                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    return redirect()->to($originUrl . '/#/login?error=' . urlencode('创建本地账号失败'));
                }
            }

            DB::table('user_socials')->insert([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_id' => $providerId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }

        if ($user->banned) {
            return redirect()->to($originUrl . '/#/login?error=' . urlencode('您的账号已被封禁'));
        }

        $user->last_login_at = time();
        $user->save();

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 60);

        $redirectUrl = $originUrl . '/#/login?verify=' . $code;
        return redirect()->to($redirectUrl);
    }
}
