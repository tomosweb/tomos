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
use Tomos\MetadataIndex;
use Tomos\SeoMetadata;
use Tomos\SitemapGenerator;
use Tomos\ThemeValidator;

function seoCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function seoWrite(string $path, string $contents): void
{
    seoCheck(file_put_contents($path, $contents, LOCK_EX) !== false, 'could not write ' . $path);
}

function seoRemove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        seoRemove($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

function seoRun(App $app, string $uri): string
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

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-seo-' . bin2hex(random_bytes(6));
$content = $root . '/content';
$cache = $root . '/cache';
$themes = $root . '/themes';
$theme = $themes . '/test-theme';

try {
    @mkdir($content . '/articles/images', 0777, true);
    @mkdir($root . '/images', 0777, true);
    @mkdir($cache, 0777, true);
    @mkdir($theme . '/templates', 0777, true);
    @mkdir($theme . '/assets', 0777, true);
    seoWrite($content . '/articles/images/social.jpg', 'image');
    seoWrite($root . '/images/root.jpg', 'image');
    seoWrite($theme . '/theme.json', '{"name":"test-theme","display_name":"SEO test","version":"1.0.0"}');
    seoWrite($theme . '/templates/layout.html', '<!doctype html><html lang="{{ page.language }}"><head>{{{ page.seo_head_html }}}<link rel="icon" href="{{ theme.favicon_url }}"></head><body><main>{{{ page.body }}}</main></body></html>');
    seoWrite($theme . '/templates/page.html', '<article><h1>{{ page.title }}</h1>{{{ page.content }}}</article>');
    seoWrite($theme . '/templates/list.html', '<main><h1>{{ page.title }}</h1></main>');
    seoWrite($theme . '/assets/style.css', 'body{}');
    seoWrite($theme . '/assets/favicon.png', 'icon');
    seoWrite($theme . '/assets/apple-touch-icon.png', 'icon');
    seoWrite($theme . '/assets/ogp.png', 'ogp');

    seoWrite($content . '/index.md', "---\ntitle: Home title\n---\nHome body\n");
    seoWrite($content . '/about.md', "---\ntitle: About\ndescription: About description\n---\nAbout body\n");
    seoWrite($content . '/articles/entry.md', "---\ntitle: Entry\ndescription:\nimage: images/social.jpg\ndate: 2026-08-30\nupdated: 2026-08-31\n---\nEntry body\n");
    seoWrite($content . '/articles/root-image.md', "---\ntitle: Root image\nimage: /images/root.jpg\n---\nRoot body\n");
    seoWrite($content . '/articles/unsafe.md', "---\ntitle: Unsafe image\nimage: javascript:alert(1)\n---\nUnsafe body\n");
    seoWrite($content . '/articles/published-only.md', "---\ntitle: Published only\npublished: 2026-08-29T10:00:00+09:00\n---\nPublished body\n");
    seoWrite($content . '/articles/draft.md', "---\ntitle: Draft\ndraft: true\n---\nDraft body\n");

    $parser = new FrontMatterParser();
    $parsed = $parser->parse("---\ntitle: Blank description\ndescription:\n---\nBody excerpt\n");
    $metadata = $parser->buildPageMetadata($parsed['metadata'], $parsed['body'], 'articles/blank.md');
    seoCheck($metadata['description'] === 'Body excerpt', 'blank description must use excerpt');
    seoCheck($metadata['image'] === '', 'missing image must normalize to empty');

    $site = [
        'name' => 'Example Site',
        'description' => 'Example description',
        'url' => 'https://example.test',
        'base_path' => '/tomos',
        'public_base_path' => '/tomos',
        'language' => 'ja',
    ];
    $index = new MetadataIndex($content, $cache, $parser, false, 'ja');
    $pages = $index->rebuild();
    $byPath = [];
    foreach ($pages as $page) {
        $byPath[$page['path']] = $page;
    }
    seoCheck(($byPath['index.md']['page_type'] ?? '') === 'home', 'home page type missing');
    seoCheck(($byPath['about.md']['page_type'] ?? '') === 'fixed_page', 'fixed page type missing');
    seoCheck(($byPath['articles/entry.md']['image'] ?? '') === 'images/social.jpg', 'front matter image missing from index');
    seoCheck(!isset($byPath['articles/draft.md']), 'draft must not enter public index');

    $defaultImage = 'https://example.test/tomos/themes/test-theme/assets/ogp.png';
    $entrySeo = SeoMetadata::build($byPath['articles/entry.md'], $site, '/tomos', $defaultImage, $content);
    seoCheck($entrySeo['page_type'] === 'article', 'article page type missing');
    seoCheck($entrySeo['canonical_url'] === 'https://example.test/tomos/articles/entry', 'canonical path mismatch');
    seoCheck($entrySeo['social_image_url'] === 'https://example.test/tomos/content/articles/images/social.jpg', 'relative social image mismatch');
    seoCheck(strpos(SeoMetadata::headHtml($entrySeo), 'og:url" content="' . $entrySeo['canonical_url']) !== false, 'OG URL must use canonical');
    $indexSeo = SeoMetadata::build($byPath['index.md'], $site, '/tomos', $defaultImage, $content);
    seoCheck($indexSeo['page_type'] === 'website', 'home must be website');
    seoCheck($indexSeo['document_title'] === 'Home title - Example Site', 'home title mismatch');
    $fallbackHomeSeo = SeoMetadata::build(['title' => 'index', 'title_explicit' => false, 'internal_url' => '/', 'page_type' => 'home'], $site, '/tomos', $defaultImage, $content);
    seoCheck($fallbackHomeSeo['document_title'] === 'Example Site', 'home title fallback mismatch');
    $headingHomeSeo = SeoMetadata::build(['title' => 'Derived home heading', 'title_explicit' => false, 'internal_url' => '/', 'page_type' => 'home'], $site, '/tomos', $defaultImage, $content);
    seoCheck($headingHomeSeo['document_title'] === 'Derived home heading - Example Site', 'derived home title must be preserved');
    $querySeo = SeoMetadata::build(['title' => 'Entry', 'internal_url' => '/index.php/articles/entry?utm=1#top', 'page_type' => 'markdown_page'], $site, '/tomos', $defaultImage, $content);
    seoCheck($querySeo['canonical_url'] === 'https://example.test/tomos/articles/entry', 'canonical must omit index.php, query, and fragment');
    $unsafeSeo = SeoMetadata::build($byPath['articles/unsafe.md'], $site, '/tomos', $defaultImage, $content);
    seoCheck($unsafeSeo['social_image_url'] === $defaultImage, 'unsafe image must use default');
    $rootSeo = SeoMetadata::build($byPath['articles/root-image.md'], $site, '/tomos', $defaultImage, $content);
    seoCheck($rootSeo['social_image_url'] === 'https://example.test/tomos/images/root.jpg', 'root-relative image mismatch');
    $externalSeo = SeoMetadata::build(['title' => 'External image', 'image' => 'https://cdn.example/social.jpg', 'internal_url' => '/articles/external', 'page_type' => 'markdown_page'], $site, '/tomos', $defaultImage, $content);
    seoCheck($externalSeo['social_image_url'] === 'https://cdn.example/social.jpg', 'external image URL must be accepted');

    $sitemap = (new SitemapGenerator($pages, $site['url'], '/tomos'))->xml();
    seoCheck(strpos($sitemap, 'https://example.test/tomos/articles/entry') !== false, 'sitemap URL mismatch');
    seoCheck(strpos($sitemap, 'Draft') === false, 'draft must not enter sitemap');
    seoCheck(strpos($sitemap, '2026-08-31') !== false, 'sitemap updated lastmod missing');
    seoCheck(strpos($sitemap, 'published-only') !== false && strpos($sitemap, '2026-08-29') !== false, 'sitemap published lastmod missing');
    seoCheck(substr_count($sitemap, '<lastmod>') < substr_count($sitemap, '<url>'), 'metadata-less pages must be allowed to omit lastmod');
    $mtimeOnlySitemap = (new SitemapGenerator([['url' => '/mtime-only', 'mtime' => time()]], $site['url'], '/tomos'))->xml();
    seoCheck(strpos($mtimeOnlySitemap, '<lastmod>') === false, 'sitemap must not use filesystem mtime');

    $feed = (new FeedGenerator($pages, array_merge($site, ['public_base_path' => '/tomos']), 20))->xml();
    seoCheck(strpos($feed, 'https://example.test/tomos/articles/entry') !== false, 'RSS URL mismatch');
    seoCheck(strpos($feed, 'Draft') === false, 'draft must not enter RSS');

    $config = [
        'site' => $site,
        'paths' => ['content_dir' => $content, 'cache_dir' => $cache, 'theme_dir' => $themes, 'inbox_dir' => $root . '/inbox'],
        'theme' => ['name' => 'test-theme'],
        'features' => ['metadata_cache' => true, 'html_cache' => false, 'rss' => true, 'sitemap' => true],
        'security' => ['allow_raw_html' => false, 'content_security_policy' => false],
        'analytics' => ['ga4_measurement_id' => ''],
    ];
    $app = new App($config);
    $articleHtml = seoRun($app, '/tomos/index.php/articles/entry?utm=1');
    seoCheck(strpos($articleHtml, '<link rel="canonical" href="https://example.test/tomos/articles/entry">') !== false, 'HTTP article canonical missing');
    seoCheck(strpos($articleHtml, '<meta property="og:type" content="article">') !== false, 'HTTP article type missing');
    seoCheck(strpos($articleHtml, '<meta property="og:site_name" content="Example Site">') !== false, 'HTTP site name missing');
    seoCheck(strpos($articleHtml, 'https://example.test/tomos/content/articles/images/social.jpg') !== false, 'HTTP front matter image missing');
    seoCheck(strpos($articleHtml, '<meta name="description" content="Entry body"') !== false, 'HTTP excerpt fallback missing');
    seoCheck(strpos($articleHtml, '<meta name="robots"') === false, 'public article must not receive robots override');

    $robots = seoRun($app, '/tomos/robots.txt');
    seoCheck($robots === "User-agent: *\nAllow: /\n\nSitemap: https://example.test/tomos/sitemap.xml\n", 'robots output mismatch');
    $noSitemapConfig = $config;
    $noSitemapConfig['features']['sitemap'] = false;
    $noSitemapConfig['site']['url'] = '';
    $robotsWithoutSitemap = seoRun(new App($noSitemapConfig), '/tomos/robots.txt');
    seoCheck($robotsWithoutSitemap === "User-agent: *\nAllow: /\n", 'robots must omit sitemap when unavailable');
    $sitemapHttp = seoRun($app, '/tomos/sitemap.xml');
    seoCheck(strpos($sitemapHttp, 'https://example.test/tomos/articles/entry') !== false, 'HTTP sitemap missing article');
    $homeHtml = seoRun($app, '/tomos/');
    seoCheck(strpos($homeHtml, '<meta property="og:type" content="website">') !== false, 'HTTP home type missing');
    seoCheck(substr_count($homeHtml, '<title>') === 1, 'SEO head must have one title');
    $notFoundHtml = seoRun($app, '/tomos/not-found');
    seoCheck(strpos($notFoundHtml, '<meta property="og:type" content="website">') !== false, '404 page must not be an article');
    seoCheck(strpos($notFoundHtml, '<link rel="canonical"') === false, '404 page must not expose a canonical URL');
    seoCheck(strpos($notFoundHtml, '<meta property="og:url"') === false, '404 page must not expose og:url');

    $validator = new ThemeValidator($themes);
    $valid = $validator->validate('test-theme');
    seoCheck(!empty($valid['valid']) && $valid['warnings'] === [], 'SEO placeholder theme must validate cleanly');
    $legacy = $themes . '/legacy-theme';
    @mkdir($legacy . '/templates', 0777, true);
    @mkdir($legacy . '/assets', 0777, true);
    seoWrite($legacy . '/theme.json', '{"name":"legacy-theme","display_name":"Legacy","version":"1.0.0"}');
    seoWrite($legacy . '/templates/layout.html', '<html><head></head><body></body></html>');
    seoWrite($legacy . '/templates/page.html', '<article></article>');
    seoWrite($legacy . '/templates/list.html', '<main></main>');
    seoWrite($legacy . '/assets/style.css', 'body{}');
    $legacyResult = $validator->validate('legacy-theme');
    $legacyWarnings = implode('\n', $legacyResult['warnings']);
    seoCheck(!empty($legacyResult['valid']) && strpos($legacyWarnings, 'SEO head placeholder') !== false, 'legacy theme must warn, not fail');

    echo "seo_foundation_check: OK\n";
} finally {
    seoRemove($root);
}
