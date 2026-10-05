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

use Tomos\TemplateRenderer;
use Tomos\ThemeContextBuilder;

function themeContextCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function themeContextWrite(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('could not create fixture directory');
    }
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('could not write fixture');
    }
}

function themeContextRemove(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        themeContextRemove($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . '/tomos-theme-context-' . bin2hex(random_bytes(6));
$themes = $root . '/themes';
$theme = $themes . '/context-test';

try {
    themeContextWrite($root . '/assets/tomos-default-favicon.png', 'favicon');
    themeContextWrite($theme . '/theme.json', json_encode([
        'name' => 'context-test',
        'display_name' => 'Context Test',
        'version' => '1.2.3',
    ], JSON_UNESCAPED_SLASHES) . "\n");
    themeContextWrite($theme . '/assets/style.css', 'body{}');
    themeContextWrite($theme . '/templates/layout.html', <<<'HTML'
<!doctype html><html lang="{{ page.language }}"><head>{{{ page.seo_head_html }}}</head><body>
<a class="home" href="{{ site.home_url }}">home</a>
<a class="all" href="{{ nav.all_url }}">all</a>
<span class="asset">{{ theme.asset_url }}</span>
{{{ page.body }}}
</body></html>
HTML
    );
    themeContextWrite($theme . '/templates/page.html', '<article>{{ page.title }}|{{ page.absolute_url }}|{{ theme.version }}</article>');

    $config = [
        'site' => [
            'name' => 'Context Site',
            'description' => 'Context Description',
            'url' => 'https://example.test',
            'base_path' => '/tomos',
            'public_base_path' => '',
            'language' => 'ja',
        ],
        'paths' => [
            'content_dir' => $root . '/content',
            'theme_dir' => $themes,
        ],
        'theme' => ['name' => 'context-test'],
        'features' => [
            'rss' => true,
            'sitemap' => true,
        ],
        'security' => [
            'content_security_policy' => false,
        ],
        'analytics' => [
            'ga4_measurement_id' => '',
        ],
    ];

    mkdir($root . '/content', 0700, true);

    $page = [
        'title' => 'Article',
        'description' => 'Article description',
        'url' => '/article',
        'internal_url' => '/article',
        'page_type' => 'markdown_page',
        'path' => 'article.md',
        'language' => 'en',
        'date' => '2026-10-05',
        'published' => '2026-10-05',
        'updated' => '',
        'image' => '',
        'excerpt' => '',
        'nav' => [
            'tree' => '',
            'mobile_tree' => '',
            'sections' => '',
            'primary_links' => '',
            'primary_items' => [],
            'breadcrumbs' => '',
        ],
        'list' => [
            'pages' => '',
            'latest_pages' => '',
        ],
        'tag' => [
            'list' => '',
            'items' => [],
        ],
    ];

    $builder = new ThemeContextBuilder($config, 'context-test', '', $root);
    $context = $builder->build($page);

    themeContextCheck(($context['site']['language'] ?? null) === 'ja', 'site language mismatch');
    themeContextCheck(($context['page']['language'] ?? null) === 'en', 'page language mismatch');
    themeContextCheck(($context['site']['home_url'] ?? null) === '/tomos/', 'home URL mismatch');
    themeContextCheck(($context['nav']['all_url'] ?? null) === '/tomos/all/', 'all URL mismatch');
    themeContextCheck(($context['theme']['asset_url'] ?? null) === '/tomos/themes/context-test/assets', 'theme asset URL mismatch');
    themeContextCheck(($context['theme']['version'] ?? null) === '1.2.3', 'theme version mismatch');
    themeContextCheck(($context['page']['absolute_url'] ?? null) === 'https://example.test/tomos/article', 'canonical URL mismatch');
    themeContextCheck(strpos((string) ($context['page']['seo_head_html'] ?? ''), 'rel="canonical"') !== false, 'SEO head missing');

    $renderer = new TemplateRenderer($config, '', $root);
    $html = $renderer->renderPage($page);
    themeContextCheck(strpos($html, 'lang="en"') !== false, 'renderer did not consume shared page language');
    themeContextCheck(strpos($html, 'https://example.test/tomos/article') !== false, 'renderer canonical context mismatch');
    themeContextCheck(strpos($html, '/tomos/themes/context-test/assets') !== false, 'renderer theme context mismatch');
    themeContextCheck(strpos($html, 'Article|https://example.test/tomos/article|1.2.3') !== false, 'page template context mismatch');

    echo "theme_context_builder_check: OK\n";
} finally {
    themeContextRemove($root);
}
