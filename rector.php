<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/config',
        __DIR__ . '/routes',
    ])
    ->withSkip([
        // 跳过编译缓存与特定第三方内嵌资源
        __DIR__ . '/storage',
        __DIR__ . '/bootstrap/cache',
    ])
    ->withPhpSets(
        php84: true
    )
    ->withRules([
        ExplicitNullableParamTypeRector::class,
    ]);
