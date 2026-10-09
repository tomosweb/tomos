<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/StaticSiteConfig.php';

use Tomos\StaticSiteConfig;

$root = sys_get_temp_dir() . '/tomos-local-theme-' . bin2hex(random_bytes(6));
$site = $root . '/site';
$core = $root . '/core';
mkdir($site . '/themes/custom-theme', 0700, true);
mkdir($core . '/themes/tomos-minimal', 0700, true);

try {
    $base = ['site' => ['name' => 'Test site', 'url' => 'https://example.test']];
    $custom = StaticSiteConfig::normalize(
        array_replace_recursive($base, ['theme' => ['name' => 'custom-theme']]),
        $site,
        $core
    );
    if ($custom['paths']['theme_dir'] !== $site . '/themes') {
        throw new RuntimeException('Site-local theme not selected');
    }

    $standard = StaticSiteConfig::normalize(
        array_replace_recursive($base, ['theme' => ['name' => 'tomos-minimal']]),
        $site,
        $core
    );
    if ($standard['paths']['theme_dir'] !== $core . '/themes') {
        throw new RuntimeException('Bundled theme path changed');
    }

    try {
        StaticSiteConfig::normalize(
            array_replace_recursive($base, ['theme' => ['name' => '../unsafe']]),
            $site,
            $core
        );
        throw new RuntimeException('Unsafe name accepted');
    } catch (InvalidArgumentException $expected) {
    }
    echo "Static site-local theme resolution: PASS\n";
} finally {
    rmdir($site . '/themes/custom-theme');
    rmdir($site . '/themes');
    rmdir($site);
    rmdir($core . '/themes/tomos-minimal');
    rmdir($core . '/themes');
    rmdir($core);
    rmdir($root);
}
