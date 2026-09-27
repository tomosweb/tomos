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

use Tomos\App;

function pageTocCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/tomos-page-toc-' . bin2hex(random_bytes(8));
$theme = $root . '/themes/toc-test';
mkdir($root . '/content', 0700, true);
mkdir($root . '/cache', 0700, true);
mkdir($root . '/assets', 0700, true);
mkdir($theme . '/templates', 0700, true);
mkdir($theme . '/assets', 0700, true);

try {
    file_put_contents($root . '/assets/tomos-default-favicon.png', file_get_contents(dirname(__DIR__) . '/assets/tomos-default-favicon.png'), LOCK_EX);
    file_put_contents($theme . '/theme.json', json_encode([
        'name' => 'toc-test',
        'display_name' => 'TOC Test',
        'version' => '1.0.0',
    ], JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    file_put_contents($theme . '/templates/layout.html', '<!doctype html><html><head>{{{ page.seo_head_html }}}</head><body>{{{ page.body }}}</body></html>', LOCK_EX);
    file_put_contents($theme . '/templates/page.html', '{{{ page.toc }}}<article>{{{ page.content }}}</article>', LOCK_EX);
    file_put_contents($theme . '/templates/list.html', '<main>{{{ list.pages }}}</main>', LOCK_EX);
    file_put_contents($theme . '/assets/style.css', 'body{}', LOCK_EX);
    file_put_contents($root . '/content/article.md', <<<'MARKDOWN'
---
title: Article
draft: false
---
導入文。

## 概要

本文。

### 詳細

#### 補足

```
## コードブロック内の見出し
```
MARKDOWN
    , LOCK_EX);

    $config = [
        'site' => [
            'name' => 'TOC Test',
            'description' => '',
            'url' => 'https://example.test',
            'base_path' => '',
            'public_base_path' => '',
        ],
        'paths' => [
            'content_dir' => $root . '/content',
            'cache_dir' => $root . '/cache',
            'theme_dir' => $root . '/themes',
            'inbox_dir' => $root . '/inbox',
        ],
        'theme' => ['name' => 'toc-test'],
        'features' => [
            'metadata_cache' => false,
            'html_cache' => false,
            'rss' => false,
            'sitemap' => false,
        ],
        'security' => [
            'allow_raw_html' => false,
            'content_security_policy' => false,
        ],
        'analytics' => ['ga4_measurement_id' => ''],
    ];

    [$status, $html] = runPageTocApp($config, '/article');
    pageTocCheck($status === 200, 'App article render did not return HTTP 200');
    pageTocCheck(strpos($html, '<nav class="toc" aria-label="目次">') !== false, 'App did not inject page.toc into the template');
    pageTocCheck(strpos($html, '<h2 id="概要">概要</h2>') !== false, 'App article is missing the heading anchor');
    pageTocCheck(strpos($html, '<h3 id="詳細">詳細</h3>') !== false, 'App article is missing the nested heading anchor');
    pageTocCheck(strpos($html, 'id="コードブロック内の見出し"') === false, 'App parsed a code block heading');

    $config['features']['html_cache'] = true;
    [$firstStatus, $firstHtml] = runPageTocApp($config, '/article');
    [$secondStatus, $secondHtml] = runPageTocApp($config, '/article');
    pageTocCheck($firstStatus === 200 && $secondStatus === 200, 'HTML cache App renders did not return HTTP 200');
    pageTocCheck(strpos($firstHtml, '<nav class="toc" aria-label="目次">') !== false, 'page.toc missing on cache creation render');
    pageTocCheck(strpos($secondHtml, '<nav class="toc" aria-label="目次">') !== false, 'page.toc missing on HTML cache hit');
    pageTocCheck(strpos($secondHtml, '<h3 id="詳細">詳細</h3>') !== false, 'heading anchor missing on HTML cache hit');

    echo "page_toc_app_check: OK\n";
} finally {
    removePageTocTree($root);
}

/** @return array{0: int, 1: string} */
function runPageTocApp(array $config, string $uri): array
{
    http_response_code(200);
    ob_start();
    try {
        (new App($config))->run($uri);
        $html = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }

    return [http_response_code(), $html];
}

function removePageTocTree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach (array_diff($items, ['.', '..']) as $item) {
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child)) {
            removePageTocTree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}
