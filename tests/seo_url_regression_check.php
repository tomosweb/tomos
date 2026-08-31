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

use Tomos\App;
use Tomos\FeedGenerator;
use Tomos\FrontMatterParser;
use Tomos\SeoMetadata;
use Tomos\Security;
use Tomos\SitemapGenerator;
use Tomos\TagIndex;

function hotfixCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function hotfixWrite(string $path, string $contents): void
{
    hotfixCheck(file_put_contents($path, $contents, LOCK_EX) !== false, 'could not write ' . $path);
}

function hotfixRemove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        hotfixRemove($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

function hotfixRun(App $app, string $uri): array
{
    http_response_code(200);
    ob_start();
    try {
        $app->run($uri);
        $html = (string) ob_get_contents();
        $status = (int) http_response_code();
    } finally {
        ob_end_clean();
    }

    return [$status, $html];
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-seo-url-' . bin2hex(random_bytes(6));
$contentDir = $root . DIRECTORY_SEPARATOR . 'content';
$cacheDir = $root . DIRECTORY_SEPARATOR . 'cache';
$themesDir = $root . DIRECTORY_SEPARATOR . 'themes';
$themeDir = $themesDir . DIRECTORY_SEPARATOR . 'test-theme';
$fixturePath = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'seo-url-japanese.md';
$fixture = file_get_contents($fixturePath);
hotfixCheck($fixture !== false, 'Japanese URL fixture could not be read');

@mkdir($contentDir . DIRECTORY_SEPARATOR . 'diary' . DIRECTORY_SEPARATOR . 'images', 0777, true);
@mkdir($cacheDir, 0777, true);
@mkdir($themeDir . DIRECTORY_SEPARATOR . 'templates', 0777, true);
@mkdir($themeDir . DIRECTORY_SEPARATOR . 'assets', 0777, true);

try {
    hotfixWrite($contentDir . DIRECTORY_SEPARATOR . 'index.md', "---\ntitle: Home\n---\nHome\n");
    hotfixWrite($contentDir . DIRECTORY_SEPARATOR . 'diary' . DIRECTORY_SEPARATOR . '日本語タイトル.md', $fixture);
    hotfixWrite($contentDir . DIRECTORY_SEPARATOR . 'diary' . DIRECTORY_SEPARATOR . 'old.md', "---\ntitle: Old\ndate: 2026-01-01\n---\nOld\n");
    hotfixWrite($contentDir . DIRECTORY_SEPARATOR . 'diary' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . '日本語画像.jpg', 'image');
    hotfixWrite($themeDir . DIRECTORY_SEPARATOR . 'theme.json', '{"name":"test-theme","display_name":"SEO URL test","version":"1.0.0"}');
    hotfixWrite($themeDir . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'layout.html', '<html lang="{{ page.language }}"><head>{{{ page.seo_head_html }}}</head><body><main>{{{ page.body }}}</main></body></html>');
    hotfixWrite($themeDir . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'page.html', '<article><h1>{{ page.title }}</h1>{{{ page.content }}}</article>');
    hotfixWrite($themeDir . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'list.html', '<main><h1>{{ page.title }}</h1>{{{ list.pages }}}</main>');
    hotfixWrite($themeDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'style.css', 'body{}');
    hotfixWrite($themeDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'favicon.png', 'icon');
    hotfixWrite($themeDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'apple-touch-icon.png', 'icon');
    hotfixWrite($themeDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'ogp.png', 'ogp');

    $parser = new FrontMatterParser();
    $parsed = $parser->parse($fixture);
    $metadata = $parser->buildPageMetadata($parsed['metadata'], $parsed['body'], 'diary/日本語タイトル.md');
    hotfixCheck($metadata['title'] === '日本語タイトル', 'Japanese fixture title was not parsed');
    hotfixCheck($metadata['image'] === 'images/日本語画像.jpg', 'Japanese image metadata was not parsed');

    $rawPath = '/diary/日本語タイトル';
    $encodedPath = '/diary/%E6%97%A5%E6%9C%AC%E8%AA%9E%E3%82%BF%E3%82%A4%E3%83%88%E3%83%AB';
    $lowerEncodedPath = '/diary/%e6%97%a5%e6%9c%ac%e8%aa%9e%e3%82%bf%e3%82%a4%e3%83%88%e3%83%ab';
    $expectedPath = '/tomos' . $encodedPath;
    hotfixCheck(Security::publicUrl($rawPath, '/tomos') === $expectedPath, 'raw Japanese path encoding mismatch');
    hotfixCheck(Security::publicUrl($encodedPath, '/tomos') === $expectedPath, 'pre-encoded Japanese path was encoded twice');
    hotfixCheck(Security::publicUrl($lowerEncodedPath, '/tomos') === $expectedPath, 'lowercase pre-encoded Japanese path was changed incorrectly');
    hotfixCheck(Security::publicUrl('/diary/a b', '/tomos') === '/tomos/diary/a%20b', 'space path encoding mismatch');
    hotfixCheck(Security::publicUrl('/diary/a+b', '/tomos') === '/tomos/diary/a%2Bb', 'plus path encoding mismatch');
    hotfixCheck(Security::publicUrl('/diary/100%', '/tomos') === '/tomos/diary/100%25', 'percent path encoding mismatch');
    hotfixCheck(Security::publicUrl('/diary/x?utm=1#top', '/tomos') === '/tomos/diary/x?utm=1#top', 'path query/fragment handling changed');

    $site = [
        'name' => 'Example Site',
        'description' => 'Example description',
        'url' => 'https://example.test',
        'public_base_path' => '/tomos',
        'language' => 'ja',
    ];
    $defaultImage = 'https://example.test/tomos/themes/test-theme/assets/ogp.png';
    $page = [
        'path' => 'diary/日本語タイトル.md',
        'url' => $rawPath,
        'internal_url' => $rawPath,
        'page_type' => 'markdown_page',
        'title' => '日本語タイトル',
        'description' => '日本語URLと画像の回帰fixture',
        'excerpt' => '日本語URLと画像の回帰fixture',
        'image' => 'images/日本語画像.jpg',
        'date' => '2026-08-31',
        'published' => '',
        'updated' => '',
        'tags' => ['日記', '読書・思想', 'C++'],
        'draft' => false,
        'language' => 'ja',
    ];
    $rawSeo = SeoMetadata::build($page, $site, '/tomos', $defaultImage, $contentDir);
    $encodedSeo = SeoMetadata::build(array_merge($page, ['url' => $encodedPath, 'internal_url' => $encodedPath]), $site, '/tomos', $defaultImage, $contentDir);
    hotfixCheck($rawSeo['canonical_url'] === 'https://example.test' . $expectedPath, 'raw Japanese canonical mismatch');
    hotfixCheck($encodedSeo['canonical_url'] === $rawSeo['canonical_url'], 'pre-encoded canonical differs from raw canonical');
    $indexSeo = SeoMetadata::build(array_merge($page, ['url' => '/index.php/日本語タイトル', 'internal_url' => '/index.php/日本語タイトル']), $site, '/tomos', $defaultImage, $contentDir);
    hotfixCheck($indexSeo['canonical_url'] === 'https://example.test/tomos/%E6%97%A5%E6%9C%AC%E8%AA%9E%E3%82%BF%E3%82%A4%E3%83%88%E3%83%AB', 'index.php Japanese canonical was not normalized');
    hotfixCheck(strpos($rawSeo['canonical_url'], '%25') === false, 'canonical contains double-encoded percent');
    hotfixCheck($rawSeo['social_image_url'] === 'https://example.test/tomos/content/diary/images/%E6%97%A5%E6%9C%AC%E8%AA%9E%E7%94%BB%E5%83%8F.jpg', 'Japanese Front Matter image URL mismatch');

    $sitePath = array_merge($site, ['url' => 'https://example.test/tomos']);
    $sitePathSeo = SeoMetadata::build($page, $sitePath, '/tomos', $defaultImage, $contentDir);
    hotfixCheck($sitePathSeo['canonical_url'] === 'https://example.test' . $expectedPath, 'site URL path was duplicated or omitted');

    $longJapanese = str_repeat('日本語', 100);
    $longExcerpt = $parser->excerptFromMarkdown($longJapanese, 140);
    $characters = [];
    hotfixCheck(preg_match('//u', $longExcerpt) === 1, 'fallback truncate produced invalid UTF-8');
    hotfixCheck(preg_match_all('/./us', $longExcerpt, $characters) === 140, 'fallback truncate did not use the character limit');
    hotfixCheck(strpos($longExcerpt, "�") === false, 'fallback truncate produced replacement characters');
    $longHead = SeoMetadata::headHtml(array_merge($rawSeo, ['description' => $longExcerpt]));
    hotfixCheck(strpos($longHead, 'name="description" content="' . htmlspecialchars($longExcerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"') !== false, 'long description meta is invalid');
    hotfixCheck(strpos($longHead, 'og:description" content="' . htmlspecialchars($longExcerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"') !== false, 'long OGP description is invalid');
    hotfixCheck(strpos($longHead, 'twitter:description" content="' . htmlspecialchars($longExcerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"') !== false, 'long Twitter description is invalid');

    $pages = [
        $page,
        [
            'path' => 'diary/old.md',
            'url' => '/diary/old',
            'title' => 'Old',
            'description' => 'Old',
            'excerpt' => 'Old',
            'date' => '2026-01-01',
            'published' => '',
            'updated' => '',
            'tags' => [],
            'draft' => false,
        ],
    ];
    $sitemap = (new SitemapGenerator($pages, 'https://example.test', '/tomos'))->xml();
    hotfixCheck(strpos($sitemap, '<loc>https://example.test' . $expectedPath . '</loc>') !== false, 'sitemap Japanese URL mismatch');
    hotfixCheck(strpos($sitemap, '%25') === false, 'sitemap contains double-encoded percent');
    hotfixCheck(preg_match('/<url>\s*<loc>https:\/\/example\.test\/tomos\/diary\/<\/loc>(.*?)<\/url>/s', $sitemap, $folderMatch) === 1, 'virtual folder sitemap entry missing');
    hotfixCheck(strpos($folderMatch[1], '<lastmod>') === false, 'virtual folder inherited child lastmod');

    $feed = (new FeedGenerator($pages, array_merge($site, ['public_base_path' => '/tomos'])))->xml();
    hotfixCheck(substr_count($feed, 'https://example.test' . $expectedPath) >= 2, 'RSS Japanese URL missing from link/guid');
    hotfixCheck(strpos($feed, '%25') === false, 'RSS contains double-encoded percent');

    $tagIndex = new TagIndex($pages, '/tomos');
    hotfixCheck($tagIndex->tagUrl('日記') === '/tomos/tags/%E6%97%A5%E8%A8%98', 'Japanese tag URL mismatch');
    hotfixCheck($tagIndex->tagUrl('読書・思想') === '/tomos/tags/%E8%AA%AD%E6%9B%B8%E3%83%BB%E6%80%9D%E6%83%B3', 'punctuated Japanese tag URL mismatch');
    hotfixCheck($tagIndex->tagUrl('C++') === '/tomos/tags/C%2B%2B', 'plus tag URL mismatch');

    foreach (['/../secret', '/%2e%2e/secret', '/%252e%252e/secret'] as $unsafePath) {
        hotfixCheck(!Security::validateUrlPath($unsafePath)['is_valid'], 'path traversal was accepted: ' . $unsafePath);
    }
    foreach (['javascript:alert(1)', 'data:text/html,x', 'vbscript:alert(1)', 'file:///tmp/x', '//example.test/x', "https://example.test/\x01"] as $unsafeHref) {
        hotfixCheck(Security::safeHref($unsafeHref) === '#', 'unsafe href was accepted: ' . $unsafeHref);
    }

    $config = [
        'site' => $site,
        'paths' => [
            'content_dir' => $contentDir,
            'cache_dir' => $cacheDir,
            'theme_dir' => $themesDir,
            'inbox_dir' => $root . DIRECTORY_SEPARATOR . 'inbox',
        ],
        'theme' => ['name' => 'test-theme'],
        'features' => ['metadata_cache' => false, 'html_cache' => false, 'rss' => false, 'sitemap' => false],
        'security' => ['allow_raw_html' => false, 'content_security_policy' => false],
        'analytics' => ['ga4_measurement_id' => ''],
    ];
    $app = new App($config);
    foreach ([$rawPath, $encodedPath, '/index.php' . $rawPath, '/index.php' . $encodedPath] as $requestPath) {
        [$status, $html] = hotfixRun($app, $requestPath);
        hotfixCheck($status === 200, 'Japanese article status mismatch: ' . $status);
        hotfixCheck(substr_count($html, '<link rel="canonical"') === 1, 'Japanese article canonical count mismatch');
        hotfixCheck(strpos($html, 'https://example.test' . $expectedPath) !== false, 'Japanese article canonical missing');
        hotfixCheck(strpos($html, '%25') === false, 'Japanese article HTML contains double-encoded percent');
    }

    foreach (['/not-found', '/日本語で存在しないページ'] as $requestPath) {
        [$status, $html] = hotfixRun($app, $requestPath);
        hotfixCheck($status === 404, '404 status changed for ' . $requestPath);
        hotfixCheck(strpos($html, '<link rel="canonical"') === false, '404 canonical must be absent: ' . $requestPath);
        hotfixCheck(strpos($html, '<meta property="og:url"') === false, '404 og:url must be absent: ' . $requestPath);
        hotfixCheck(strpos($html, '<meta property="og:type" content="website">') !== false, '404 og:type changed: ' . $requestPath);
        hotfixCheck(strpos($html, '<title>ページが見つかりません - Example Site</title>') !== false, '404 title missing: ' . $requestPath);
        hotfixCheck(strpos($html, '<meta name="description"') !== false, '404 description missing: ' . $requestPath);
    }

    [$tagStatus, $tagHtml] = hotfixRun($app, '/tags/%E6%97%A5%E8%A8%98');
    hotfixCheck($tagStatus === 200, 'Japanese tag route status mismatch');
    hotfixCheck(strpos($tagHtml, 'https://example.test/tomos/tags/%E6%97%A5%E8%A8%98') !== false, 'Japanese tag canonical missing');

    echo "seo_url_regression_check: OK\n";
} finally {
    hotfixRemove($root);
}
