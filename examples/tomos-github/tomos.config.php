<?php

$tomosRoot = getenv('TOMOS_ROOT');
if (!is_string($tomosRoot) || trim($tomosRoot) === '') {
    throw new RuntimeException('TOMOS_ROOT must point to the pinned Tomos checkout.');
}

$repository = getenv('GITHUB_REPOSITORY');
$repositoryParts = is_string($repository) ? explode('/', $repository, 2) : [];
$repositoryOwner = $repositoryParts[0] ?? '';
$repositoryName = $repositoryParts[1] ?? '';

$siteName = getenv('TOMOS_SITE_NAME');
if (!is_string($siteName) || trim($siteName) === '') {
    $siteName = $repositoryName !== '' ? $repositoryName : 'My Tomos Site';
}

$siteUrl = getenv('TOMOS_SITE_URL');
if (!is_string($siteUrl) || trim($siteUrl) === '') {
    $siteUrl = $repositoryOwner !== ''
        ? 'https://' . strtolower($repositoryOwner) . '.github.io'
        : 'https://example.github.io';
}

$basePath = getenv('TOMOS_BASE_PATH');
if (!is_string($basePath)) {
    $isAccountSite = $repositoryOwner !== ''
        && strcasecmp($repositoryName, $repositoryOwner . '.github.io') === 0;
    $basePath = $repositoryName !== ''
        ? ($isAccountSite ? '' : '/' . $repositoryName)
        : '/example-site';
}

return [
    'site' => [
        'name' => $siteName,
        'description' => 'A Tomos site published with GitHub Pages.',
        'url' => rtrim($siteUrl, '/'),
        'base_path' => $basePath,
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
