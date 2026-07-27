<?php

return [
    'site' => [
        'name' => 'Tomos Site',
        'description' => 'Markdown で自分の Web をともす',
        'url' => 'http://localhost',
        // base_path is the URL path where Tomos is published.
        // Leave empty for a domain-root install such as https://tomoswords.org/.
        // Example for a subdirectory install such as https://example.com/tomos/: '/tomos'
        'base_path' => '',
        // public_base_path is an optional URL output override for unusual proxy setups.
        // Leave empty for normal installs; Tomos will use base_path for public URLs.
        // Do not put server filesystem paths such as /home/example/www here.
        'public_base_path' => '',
        'language' => 'ja',
        'timezone' => 'Asia/Tokyo',
    ],

    'paths' => [
        'content_dir' => __DIR__ . '/content',
        'cache_dir' => __DIR__ . '/cache',
        'theme_dir' => __DIR__ . '/themes',
    ],

    'theme' => [
        'name' => 'tomos-minimal',
    ],

    'analytics' => [
        'ga4_measurement_id' => '',
    ],

    'features' => [
        'search' => true,
        'tags' => true,
        'rss' => true,
        'sitemap' => true,
        'html_cache' => true,
        'post' => true,
        'metadata_cache' => true,
    ],

    'feed' => [
        // Leave empty to include every public page.
        // Set '/news' to include pages below /news/ while excluding /news/ itself.
        'path_prefix' => '',
    ],

    'metadata' => [
        'include_drafts' => false,
    ],

    'debug' => [
        'performance_log' => false,
    ],

    'security' => [
        'allow_raw_html' => false,
        'allow_external_scripts' => false,
        'allowed_file_extensions' => ['md', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
        'disable_setup_after_install' => true,
        'hide_error_detail' => true,
        'content_security_policy' => true,
        'post_password_hash' => '',
        'rate_limit_salt' => '',
    ],

    'setup_completed' => false,
];
