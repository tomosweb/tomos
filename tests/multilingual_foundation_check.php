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
use Tomos\ConfigWriter;
use Tomos\FrontMatterParser;
use Tomos\LanguageTag;
use Tomos\MetadataIndex;
use Tomos\PageRepository;
use Tomos\Router;

function failMultilingual(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function writeMultilingual(string $path, string $contents): void
{
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        failMultilingual('could not write fixture: ' . $path);
    }
}

function removeMultilingual(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeMultilingual($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

function runMultilingual(App $app, string $uri): string
{
    http_response_code(200);
    ob_start();
    try {
        $app->run($uri);
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

foreach (['ja', 'en', 'en-US', 'pt-BR', 'zh-Hans'] as $tag) {
    if (LanguageTag::normalizeOrNull($tag) === null) {
        failMultilingual('valid language tag rejected: ' . $tag);
    }
}
foreach (['', 'en us', '" onload=x', '../en', "en\n"] as $tag) {
    if (LanguageTag::normalizeOrNull($tag) !== null) {
        failMultilingual('unsafe language tag accepted: ' . var_export($tag, true));
    }
}

[$validSiteSettings, $siteErrors] = ConfigWriter::validateSiteSettings([
    'site_name' => 'Example',
    'site_description' => '',
    'timezone' => 'Asia/Tokyo',
    'rss_path_prefix' => '',
    'language' => 'zh-Hans',
]);
if ($siteErrors !== [] || ($validSiteSettings['language'] ?? '') !== 'zh-Hans') {
    failMultilingual('valid site language was rejected by configuration validation.');
}
[, $invalidSiteErrors] = ConfigWriter::validateSiteSettings([
    'site_name' => 'Example',
    'site_description' => '',
    'timezone' => 'Asia/Tokyo',
    'rss_path_prefix' => '',
    'language' => '" onload=x',
]);
if ($invalidSiteErrors === []) {
    failMultilingual('invalid site language was accepted by configuration validation.');
}

$parser = new FrontMatterParser();
$metadata = $parser->buildPageMetadata([
    'language' => 'en',
], 'Body', 'en/about.md');
if ($metadata['language'] !== 'en') {
    failMultilingual('frontmatter language metadata was not normalized.');
}

$root = sys_get_temp_dir() . '/tomos-multilingual-' . bin2hex(random_bytes(6));
$contentDir = $root . '/content';
$cacheDir = $root . '/cache';
$themesDir = $root . '/themes';
$themeDir = $themesDir . '/test-theme';
@mkdir($contentDir . '/en', 0777, true);
@mkdir($cacheDir, 0777, true);
@mkdir($themeDir . '/templates', 0777, true);
@mkdir($themeDir . '/assets', 0777, true);

try {
    writeMultilingual($themeDir . '/theme.json', json_encode([
        'name' => 'test-theme',
        'display_name' => 'Multilingual test theme',
        'version' => '1.0.0',
    ], JSON_UNESCAPED_SLASHES));
    writeMultilingual($themeDir . '/templates/layout.html', '<html lang="{{ page.language }}"><body>{{{ page.content }}}</body></html>');
    writeMultilingual($themeDir . '/templates/page.html', '<article>{{ page.title }}: {{{ page.content }}}</article>');
    writeMultilingual($themeDir . '/templates/list.html', '<main>{{ page.title }}</main>');
    writeMultilingual($themeDir . '/assets/style.css', 'body{}');
    writeMultilingual($themeDir . '/assets/favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    writeMultilingual($themeDir . '/assets/apple-touch-icon.png', 'icon');
    writeMultilingual($themeDir . '/assets/ogp.png', 'ogp');

    writeMultilingual($contentDir . '/about.md', "---\ntitle: Tomosとは\nlanguage: ja\n---\n日本語本文\n");
    writeMultilingual($contentDir . '/en/about.md', "---\ntitle: What is Tomos?\nlanguage: en\n---\nEnglish body\n");
    writeMultilingual($contentDir . '/default.md', "---\ntitle: Default language page\n---\nFallback body\n");
    writeMultilingual($contentDir . '/en/draft.md', "---\ntitle: Draft\nlanguage: en\ndraft: true\n---\nDraft\n");
    writeMultilingual($contentDir . '/en/unsafe.md', "---\ntitle: Unsafe\nlanguage: \" onload=x\n---\nUnsafe\n");

    $index = new MetadataIndex($contentDir, $cacheDir, null, false, 'ja');
    $pages = $index->rebuild();
    $byPath = [];
    foreach ($pages as $page) {
        $byPath[$page['path']] = $page;
    }
    if (($byPath['about.md']['language'] ?? '') !== 'ja' || ($byPath['en/about.md']['language'] ?? '') !== 'en') {
        failMultilingual('effective page language was not stored in metadata.');
    }
    if (($byPath['default.md']['language'] ?? '') !== 'ja') {
        failMultilingual('missing page language did not fall back to site language.');
    }
    $englishIndex = new MetadataIndex($contentDir, $root . '/cache-en', null, false, 'en');
    $englishPages = $englishIndex->build();
    foreach ($englishPages as $englishPage) {
        if (($englishPage['path'] ?? '') === 'default.md' && ($englishPage['language'] ?? '') !== 'en') {
            failMultilingual('site language en did not become the effective page language.');
        }
    }
    if (($byPath['en/unsafe.md']['language'] ?? '') !== 'ja') {
        failMultilingual('invalid page language did not fall back safely.');
    }
    $directDefault = (new PageRepository($contentDir))->findByRoute((new Router(''))->resolve('/default'));
    if (($directDefault->page['language'] ?? null) !== null) {
        failMultilingual('direct page lookup must leave missing language for site fallback.');
    }
    if ($index->loadFresh() === null) {
        failMultilingual('language metadata cache did not remain fresh.');
    }

    $config = [
        'site' => [
            'name' => 'Multilingual site',
            'description' => '',
            'url' => 'https://example.test',
            'base_path' => '',
            'public_base_path' => '',
            'language' => 'ja',
        ],
        'paths' => [
            'content_dir' => $contentDir,
            'cache_dir' => $cacheDir,
            'theme_dir' => $themesDir,
            'inbox_dir' => $root . '/inbox',
        ],
        'theme' => ['name' => 'test-theme'],
        'features' => ['metadata_cache' => true, 'html_cache' => false, 'rss' => false, 'sitemap' => true],
        'security' => ['allow_raw_html' => false, 'content_security_policy' => false],
        'analytics' => ['ga4_measurement_id' => ''],
    ];
    $app = new App($config);
    $english = runMultilingual($app, '/en/about');
    if (strpos($english, '<html lang="en">') === false) {
        failMultilingual('English page did not render the minimal language contract.');
    }
    $japanese = runMultilingual($app, '/about');
    if (strpos($japanese, '<html lang="ja">') === false) {
        failMultilingual('Japanese page did not render the minimal language contract.');
    }
    $fallback = runMultilingual($app, '/default');
    if (strpos($fallback, '<html lang="ja">') === false) {
        failMultilingual('page without language did not use site language in HTML.');
    }
    $sitemap = runMultilingual($app, '/sitemap.xml');
    if (strpos($sitemap, 'https://example.test/about') === false || strpos($sitemap, 'https://example.test/en/about') === false) {
        failMultilingual('both language variants were not included in sitemap.');
    }

    echo "multilingual_foundation_check: OK\n";
} finally {
    removeMultilingual($root);
}
