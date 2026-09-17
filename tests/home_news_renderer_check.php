<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
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

function failRenderer(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function removeRendererTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeRendererTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . '/tomos-home-news-render-' . bin2hex(random_bytes(6));
@mkdir($root . '/themes/test-theme/templates', 0777, true);
@mkdir($root . '/themes/test-theme/assets', 0777, true);
@mkdir($root . '/cache/index', 0777, true);
@mkdir($root . '/content/news', 0777, true);

try {
    file_put_contents($root . '/theme-settings.php', <<<'PHP'
<?php
return [
    'news' => [
        'enabled' => true,
        'path' => '/news/',
        'limit' => 2,
        'heading' => 'Latest',
        'more_label' => 'All news',
    ],
];
PHP
    );
    file_put_contents($root . '/themes/test-theme/theme.json', json_encode([
        'name' => 'test-theme',
        'display_name' => 'Test Theme',
        'version' => '1.0.0',
    ], JSON_UNESCAPED_SLASHES));
    file_put_contents($root . '/themes/test-theme/templates/layout.html', '<html><body>{{{ page.body }}}</body></html>');
    file_put_contents($root . '/themes/test-theme/templates/page.html', '{{{ page.content }}}');
    file_put_contents($root . '/themes/test-theme/templates/list.html', '{{{ list.pages }}}');
    file_put_contents($root . '/themes/test-theme/templates/home.html', '{{# home.has_news }}<h2>{{ theme.news_heading }}</h2>{{# home.news_items }}<a href="{{ url }}"><time datetime="{{ date }}">{{ date_display }}</time>{{ title }}</a>{{/ home.news_items }}<a class="more" href="{{ home.news_url }}">{{ theme.news_more_label }}</a>{{/ home.has_news }}{{{ page.content }}}');
    file_put_contents($root . '/themes/test-theme/assets/style.css', 'body{}');

    file_put_contents($root . '/cache/index/pages.json', json_encode([
        ['path' => 'news/first.md', 'url' => '/news/first', 'title' => '<First>', 'date' => '2026-08-18', 'published' => '2026-08-18T08:00:00+09:00', 'draft' => false, 'search_text' => ''],
        ['path' => 'news/latest.md', 'url' => '/news/latest', 'title' => 'Latest', 'date' => '2026-08-19', 'published' => '2026-08-19T08:00:00+09:00', 'draft' => false, 'search_text' => ''],
        ['path' => 'news/index.md', 'url' => '/news/', 'title' => 'Index', 'date' => '2026-08-20', 'published' => '2026-08-20T08:00:00+09:00', 'draft' => false, 'search_text' => ''],
    ], JSON_UNESCAPED_SLASHES));

    $config = [
        'site' => [
            'name' => 'Test',
            'description' => '',
            'url' => 'https://example.test/tomos',
            'base_path' => '/tomos',
            'public_base_path' => '/tomos',
        ],
        'paths' => [
            'content_dir' => $root . '/content',
            'cache_dir' => $root . '/cache',
            'theme_dir' => $root . '/themes',
        ],
        'theme' => ['name' => 'test-theme'],
        'features' => ['rss' => false, 'metadata_cache' => true],
        'security' => ['content_security_policy' => false],
        'analytics' => ['ga4_measurement_id' => ''],
    ];

    $renderer = new TemplateRenderer($config, '', $root);
    $html = $renderer->renderPage([
        'title' => 'Home',
        'description' => '',
        'url' => '/tomos/',
        'internal_url' => '/',
        'content' => '<p>Body</p>',
        'nav' => [],
        'list' => [],
    ]);

    if (strpos($html, '<h2>Latest</h2>') === false || strpos($html, 'All news') === false) {
        failRenderer('Theme settings were not exposed with structured News.');
    }
    if (strpos($html, 'href="/tomos/news/latest"') === false || strpos($html, 'href="/tomos/news/"') === false) {
        failRenderer('News URLs must respect public_base_path.');
    }
    if (strpos($html, '&lt;First&gt;') === false || strpos($html, '<First>') !== false) {
        failRenderer('News item fields must be HTML escaped.');
    }
    if (strpos($html, '>Index<') !== false) {
        failRenderer('News source index must not be emitted as an item.');
    }
    if (strpos($html, '<p>Body</p>') === false) {
        failRenderer('Existing page.content rendering must remain intact.');
    }

    echo "home_news_renderer_check: OK\n";
} finally {
    removeRendererTree($root);
}
