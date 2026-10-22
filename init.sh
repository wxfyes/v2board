#!/bin/bash

rm -rf composer.phar
wget https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
php composer.phar install -vvv

# --- 🚀 PHP 性能自检与 OPcache/JIT 智能激活引擎 ---
echo "正在检测 PHP 运行环境与性能引擎状态..."
target_ini=$(php -r "echo php_ini_loaded_file();")
has_opcache=$(php -r "echo extension_loaded('Zend OPcache') ? 'yes' : 'no';")
php_version=$(php -r "echo PHP_VERSION;")

if [ "$has_opcache" = "yes" ]; then
    cli_enabled=$(php -r "echo ini_get('opcache.enable_cli') ? 'yes' : 'no';")
    if [ "$cli_enabled" = "yes" ]; then
        echo "✅ [性能检测] PHP ${php_version} 的 Zend OPcache (CLI) 与 JIT 已经处于满血就绪状态！"
    else
        if [ -n "$target_ini" ] && [ -f "$target_ini" ]; then
            echo "⚡ [智能激活] 检测到 PHP ${php_version} 内置/已装 OPcache，但 CLI 模式尚未激活。"
            echo "⚡ 正在向配置文件 (${target_ini}) 注入常驻内存加速与 128M JIT 配置..."
            
            # 确保不重复添加
            if ! grep -q "opcache.enable_cli=1" "$target_ini"; then
                printf "\n[opcache]\nopcache.enable=1\nopcache.enable_cli=1\nopcache.jit=tracing\nopcache.jit_buffer_size=128M\n" >> "$target_ini"
                echo "✅ [智能激活] 成功为 PHP ${php_version} 激活 CLI 模式 OPcache 与 128M JIT 极致加速！"
            fi
        fi
    fi
else
    echo "ℹ️  [性能提示] 当前 PHP ${php_version} 尚未加载 Zend OPcache 扩展。"
    echo "ℹ️  提示：PHP 8.5+ 已原生内置；若使用 PHP 8.3 及以下版本，可在宝塔面板中为该 PHP 版本安装 opcache 扩展以享受 JIT 极致加速。"
fi

php artisan v2board:install
php artisan geoip:update

if [ -f "/etc/init.d/bt" ]; then
  chown -R www $(pwd);
fi
