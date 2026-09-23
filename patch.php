<?php
$file = 'E:\GitHub\v2board\app\Http\Controllers\V1\Passport\AuthController.php';
$content = file_get_contents($file);

$replacements = [
    [
        "if (!\) {\n            abort(500, __('Incorrect email or password'));\n        }",
        "if (!\) {\n            \->logLogin(\, 0, \, '失败(账号不存在)');\n            abort(500, __('Incorrect email or password'));\n        }"
    ],
    [
        "Cache::put(\n                    CacheKey::get('PASSWORD_ERROR_LIMIT', \),\n                    (int)\ + 1,\n                    60 * (int)config('v2board.password_limit_expire', 60)\n                );\n            }\n            abort(500, __('Incorrect email or password'));",
        "Cache::put(\n                    CacheKey::get('PASSWORD_ERROR_LIMIT', \),\n                    (int)\ + 1,\n                    60 * (int)config('v2board.password_limit_expire', 60)\n                );\n            }\n            \->logLogin(\, \->id, \->email, '失败(密码错误)');\n            abort(500, __('Incorrect email or password'));"
    ],
    [
        "if (\->banned) {\n            abort(500, __('Your account has been suspended'));\n        }",
        "if (\->banned) {\n            \->logLogin(\, \->id, \->email, '失败(账号封禁)');\n            abort(500, __('Your account has been suspended'));\n        }"
    ],
    [
        "\\App\\Utils\\TraitorDefense::checkAndHoneypot(\, IpHelper::getRealIp(\), \->userAgent() ?? 'unknown', '登录');\n\n        \ = new AuthService(\);",
        "\\App\\Utils\\TraitorDefense::checkAndHoneypot(\, IpHelper::getRealIp(\), \->userAgent() ?? 'unknown', '登录');\n\n        \->logLogin(\, \->id, \->email, '成功');\n\n        \ = new AuthService(\);"
    ],
    [
        "return redirect()->to(\);\n    }\n}",
        "    \->logLogin(\, \->id, \->email, '成功(快捷登录)');\n        return redirect()->to(\);\n    }\n\n    private function logLogin(Request \, \, \, \)\n    {\n        try {\n            if (!\\Illuminate\\Support\\Facades\\Schema::hasTable('v2_user_login_log')) {\n                \\Illuminate\\Support\\Facades\\Schema::create('v2_user_login_log', function (\) {\n                    \->increments('id');\n                    \->integer('user_id')->default(0);\n                    \->string('email', 128)->nullable();\n                    \->string('ip', 255)->nullable();\n                    \->string('type', 64)->nullable();\n                    \->text('ua')->nullable();\n                    \->integer('created_at')->nullable();\n                    \->integer('updated_at')->nullable();\n                });\n            }\n\n            \\Illuminate\\Support\\Facades\\DB::table('v2_user_login_log')->insert([\n                'user_id' => \ ?: 0,\n                'email' => \,\n                'ip' => IpHelper::getRealIp(\),\n                'type' => \,\n                'ua' => substr(\->userAgent() ?? '', 0, 500),\n                'created_at' => time(),\n                'updated_at' => time()\n            ]);\n        } catch (\\Exception \) {\n            \\Log::error('Login log insert error: ' . \->getMessage());\n        }\n    }\n}"
    ]
];

foreach ($replacements as $rep) {
    $content = str_replace(str_replace("\n", "\r\n", $rep[0]), str_replace("\n", "\r\n", $rep[1]), $content);
}

file_put_contents($file, $content);
echo "Patched\n";
?>
