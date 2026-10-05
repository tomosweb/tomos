<?php

$tomosRoot = getenv('TOMOS_ROOT');
if (!is_string($tomosRoot) || trim($tomosRoot) === '') {
    throw new RuntimeException('TOMOS_ROOT must point to the pinned Tomos checkout.');
}

return [
    'site' => [
        'name' => 'Tomos GitHub版',
        'description' => 'MarkdownからGitHub Pagesへ公開するTomosサイトです。',
        'url' => 'https://tomosweb.github.io',
        'base_path' => '/tomos-github',
        'language' => 'ja',
    ],
    'theme' => [
        'name' => 'tomos-minimal',
    ],
    'paths' => [
        'content_dir' => __DIR__ . '/content',
        'cache_dir' => __DIR__ . '/.tomos-cache',
        'theme_dir' => rtrim($tomosRoot, DIRECTORY_SEPARATOR) . '/themes',
    ],
    'features' => [
        'search' => true,
        'tags' => true,
        'rss' => true,
        'sitemap' => true,
    ],
];
