<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Tomos\StaticSiteBuilder;

function staticBuildCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function staticBuildWrite(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('could not create fixture directory');
    }

    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('could not write fixture');
    }
}

function staticBuildCopyTree(string $source, string $destination): void
{
    if (!is_dir($destination) && !mkdir($destination, 0700, true) && !is_dir($destination)) {
        throw new RuntimeException('could not create copied theme directory');
    }

    foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $item) {
        $from = $source . DIRECTORY_SEPARATOR . $item;
        $to = $destination . DIRECTORY_SEPARATOR . $item;

        if (is_dir($from) && !is_link($from)) {
            staticBuildCopyTree($from, $to);
        } elseif (is_file($from)) {
            if (!copy($from, $to)) {
                throw new RuntimeException('could not copy theme fixture');
            }
        }
    }
}

function staticBuildRemoveTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        staticBuildRemoveTree($path . DIRECTORY_SEPARATOR . $item);
    }

    @rmdir($path);
}

$root = sys_get_temp_dir() . '/tomos-static-build-' . bin2hex(random_bytes(6));
$content = $root . '/content';
$cache = $root . '/cache';
$themes = $root . '/themes';
$output = $root . '/dist';

try {
    mkdir($content, 0700, true);
    mkdir($cache, 0700, true);

    staticBuildCopyTree(
        dirname(__DIR__) . '/themes/tomos-minimal',
        $themes . '/tomos-minimal'
    );

    staticBuildWrite(
        $content . '/index.md',
        "---\ntitle: Home\ndraft: false\n---\n# Home\n\nWelcome.\n"
    );
    staticBuildWrite(
        $content . '/about.md',
        "---\ntitle: About\ndraft: false\n---\nAbout page.\n"
    );
    staticBuildWrite(
        $content . '/posts/entry.md',
        "---\ntitle: Entry\ndescription: Entry description\ntags:\n  - alpha\ndraft: false\n---\n## Section\n\nEntry body.\n"
    );
    staticBuildWrite(
        $content . '/docs/guide.md',
        "---\ntitle: Guide\ndraft: false\n---\nGuide body.\n"
    );
    for ($i = 1; $i <= 31; $i++) {
        staticBuildWrite(
            $content . '/archive/item-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '.md',
            "---\ntitle: Archive {$i}\ndraft: false\n---\nArchive body {$i}.\n"
        );
    }

    staticBuildWrite(
        $content . '/draft.md',
        "---\ntitle: Draft\ndraft: true\n---\nHidden body.\n"
    );
    staticBuildWrite(
        $content . '/日本語/記事.md',
        "---\ntitle: 日本語記事\nlanguage: ja\ndraft: false\n---\n日本語本文。\n"
    );
    staticBuildWrite(
        $content . '/posts/images/pic.png',
        "static-image-fixture"
    );

    $config = [
        'site' => [
            'name' => 'Static Test',
            'description' => 'Static build test',
            'url' => 'https://example.test/repo',
            'base_path' => '/repo',
            'public_base_path' => '/repo',
            'language' => 'ja',
            'timezone' => 'Asia/Tokyo',
        ],
        'paths' => [
            'content_dir' => $content,
            'cache_dir' => $cache,
            'theme_dir' => $themes,
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
            'html_cache' => false,
            'post' => false,
            'metadata_cache' => false,
        ],
        'feed' => [
            'path_prefix' => '',
        ],
        'metadata' => [
            'include_drafts' => false,
        ],
        'security' => [
            'allow_raw_html' => false,
            'content_security_policy' => false,
        ],
    ];

    $builder = new StaticSiteBuilder($config, $root);
    $result = $builder->build($output);

    staticBuildCheck(($result['pages'] ?? 0) === 36, 'public page count mismatch');
    staticBuildCheck(($result['virtual_folders'] ?? 0) >= 3, 'virtual folder count mismatch');
    staticBuildCheck(($result['tags'] ?? 0) === 1, 'tag count mismatch');

    foreach ([
        '/index.html',
        '/about/index.html',
        '/posts/entry/index.html',
        '/docs/index.html',
        '/archive/index.html',
        '/archive/page/2/index.html',
        '/tags/index.html',
        '/tags/alpha/index.html',
        '/all/index.html',
        '/search/index.html',
        '/search-index.json',
        '/assets/tomos-static-search.js',
        '/feed.xml',
        '/sitemap.xml',
        '/robots.txt',
        '/404.html',
        '/content/posts/images/pic.png',
        '/themes/tomos-minimal/assets/style.css',
    ] as $relative) {
        staticBuildCheck(is_file($output . $relative), 'missing static artifact: ' . $relative);
    }

    staticBuildCheck(!is_file($output . '/draft/index.html'), 'draft page must not be generated');

    $entryHtml = (string) file_get_contents($output . '/posts/entry/index.html');
    staticBuildCheck(strpos($entryHtml, 'Entry body.') !== false, 'Markdown body missing from static page');
    staticBuildCheck(strpos($entryHtml, 'https://example.test/repo/posts/entry') !== false, 'canonical URL mismatch');
    staticBuildCheck(strpos($entryHtml, '/repo/tags/alpha') !== false, 'tag URL mismatch');

    $virtualHtml = (string) file_get_contents($output . '/docs/index.html');
    staticBuildCheck(strpos($virtualHtml, 'Guide') !== false, 'virtual folder page list missing');

    $archivePage1 = (string) file_get_contents($output . '/archive/index.html');
    staticBuildCheck(strpos($archivePage1, '/repo/archive/page/2/') !== false, 'static pagination next URL mismatch');

    $archivePage2 = (string) file_get_contents($output . '/archive/page/2/index.html');
    staticBuildCheck(strpos($archivePage2, '31–31件を表示') !== false, 'static pagination second page range mismatch');
    staticBuildCheck(strpos($archivePage2, '/repo/archive/') !== false, 'static pagination first-page URL mismatch');
    staticBuildCheck(strpos($archivePage2, '?page=') === false, 'static pagination leaked Core query URLs');

    $searchHtml = (string) file_get_contents($output . '/search/index.html');
    staticBuildCheck(strpos($searchHtml, 'data-static-search') !== false, 'static search container missing');
    staticBuildCheck(strpos($searchHtml, '/repo/search-index.json') !== false, 'static search index URL mismatch');
    staticBuildCheck(strpos($searchHtml, '/repo/assets/tomos-static-search.js') !== false, 'static search script URL mismatch');

    $searchDocuments = json_decode((string) file_get_contents($output . '/search-index.json'), true);
    staticBuildCheck(is_array($searchDocuments), 'static search index JSON invalid');
    staticBuildCheck(count($searchDocuments) === 36, 'static search document count mismatch');
    $searchUrls = array_column($searchDocuments, 'url');
    staticBuildCheck(in_array('/repo/posts/entry', $searchUrls, true), 'static search public URL missing');
    staticBuildCheck(!in_array('/repo/draft', $searchUrls, true), 'draft leaked into static search index');

    $feed = (string) file_get_contents($output . '/feed.xml');
    staticBuildCheck(strpos($feed, 'https://example.test/repo/posts/entry') !== false, 'feed public URL mismatch');
    staticBuildCheck(strpos($feed, 'Draft') === false, 'draft leaked into feed');

    $sitemap = (string) file_get_contents($output . '/sitemap.xml');
    staticBuildCheck(strpos($sitemap, 'https://example.test/repo/docs/') !== false, 'virtual folder missing from sitemap');
    staticBuildCheck(strpos($sitemap, '/draft') === false, 'draft leaked into sitemap');

    $robots = (string) file_get_contents($output . '/robots.txt');
    staticBuildCheck(strpos($robots, 'Sitemap: https://example.test/repo/sitemap.xml') !== false, 'robots sitemap URL mismatch');

    staticBuildCheck(
        (string) file_get_contents($output . '/content/posts/images/pic.png') === 'static-image-fixture',
        'content asset copy mismatch'
    );

    echo "static_site_builder_check: OK\n";
} finally {
    staticBuildRemoveTree($root);
}
