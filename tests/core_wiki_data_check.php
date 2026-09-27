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
use Tomos\Router;
use Tomos\TagIndex;

function wikiDataCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function runWikiDataApp(App $app, string $uri): array
{
    http_response_code(200);
    ob_start();
    try {
        $app->run($uri);
        return [http_response_code(), (string) ob_get_contents()];
    } finally {
        ob_end_clean();
    }
}

function writeWikiDataFile(string $path, string $contents): void
{
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
        throw new RuntimeException('could not create fixture directory: ' . $parent);
    }
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('could not write fixture: ' . $path);
    }
}

function removeWikiDataTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeWikiDataTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . '/tomos-core-wiki-data-' . bin2hex(random_bytes(6));
$content = $root . '/content';
$theme = $root . '/themes/context-test';

try {
    mkdir($content, 0700, true);
    mkdir($root . '/cache', 0700, true);
    mkdir($root . '/assets', 0700, true);
    mkdir($theme . '/templates', 0700, true);
    mkdir($theme . '/assets', 0700, true);

    copy(dirname(__DIR__) . '/assets/tomos-default-favicon.png', $root . '/assets/tomos-default-favicon.png');
    writeWikiDataFile($theme . '/theme.json', json_encode([
        'name' => 'context-test',
        'display_name' => 'Context Test',
        'version' => '1.0.0',
    ], JSON_UNESCAPED_SLASHES) . "\n");
    writeWikiDataFile($theme . '/assets/style.css', 'body{}');
    writeWikiDataFile($theme . '/templates/layout.html', <<<'HTML'
<!doctype html><html><head>{{{ page.seo_head_html }}}</head><body>
<a class="all-url" href="{{ nav.all_url }}">all</a>
<div class="tag-items">{{# tag.items }}<span class="tag-item">{{ name }}|{{ url }}|{{ count }}</span>{{/ tag.items }}</div>
<div class="tag-list">{{{ tag.list }}}</div>
{{{ page.body }}}
</body></html>
HTML
    );
    writeWikiDataFile($theme . '/templates/page.html', <<<'HTML'
<article><ul class="related-items">{{# page.related_items }}<li class="related"><a href="{{ url }}">{{ title }}</a></li>{{/ page.related_items }}</ul>{{{ page.toc }}}{{{ page.content }}}</article>
HTML
    );
    writeWikiDataFile($theme . '/templates/list.html', '{{{ page.folder_pages_html }}}{{{ list.pages }}}');

    writeWikiDataFile($content . '/index.md', "---\ntitle: Home\ndraft: false\n---\nHome\n");
    writeWikiDataFile($content . '/about.md', "---\ntitle: About\ndraft: false\n---\nAbout\n");
    writeWikiDataFile($content . '/article.md', <<<'MARKDOWN'
---
title: 起点記事
draft: false
tags:
  - 哲学
  - 日本語
---
本文。

## 関連

[第二記事](/second#概要)
[第二記事](/second)
[第三項目](/folder/第三)
[自己リンク](/article#関連)
[存在しない](/missing)
[外部](https://example.com/)

[![画像](/image.png)](/about)

[見出し](#関連)

```
[コード内リンク](/about)
```
MARKDOWN
    );
    writeWikiDataFile($content . '/second.md', <<<'MARKDOWN'
---
title: 第二記事
draft: false
tags:
  - 哲学
---
## 概要
第二記事本文。
MARKDOWN
    );
    writeWikiDataFile($content . '/folder/第三.md', <<<'MARKDOWN'
---
title: 第三項目
draft: false
tags:
  - 日本語
---
第三項目本文。
MARKDOWN
    );
    writeWikiDataFile($content . '/draft.md', <<<'MARKDOWN'
---
title: 下書き
draft: true
tags:
  - 哲学
  - 下書き
---
非公開。
MARKDOWN
    );
    writeWikiDataFile($content . '/image.png', 'fixture image');

    $config = [
        'site' => [
            'name' => 'Core Wiki Data Test',
            'description' => '',
            'url' => 'https://example.test',
            'base_path' => '/wiki',
            'public_base_path' => '',
        ],
        'paths' => [
            'content_dir' => $content,
            'cache_dir' => $root . '/cache',
            'theme_dir' => $root . '/themes',
            'inbox_dir' => $root . '/inbox',
        ],
        'theme' => ['name' => 'context-test'],
        'features' => [
            'metadata_cache' => false,
            'html_cache' => true,
            'rss' => false,
            'sitemap' => true,
        ],
        'security' => [
            'allow_raw_html' => false,
            'content_security_policy' => false,
        ],
        'analytics' => ['ga4_measurement_id' => ''],
    ];

    $app = new App($config);
    [$status, $article] = runWikiDataApp($app, '/wiki/article');
    wikiDataCheck($status === 200, 'article did not render');
    wikiDataCheck(strpos($article, 'class="all-url" href="/wiki/all/"') !== false, 'nav.all_url was not injected');
    wikiDataCheck(strpos($article, 'tag-item">哲学|/wiki/tags/%E5%93%B2%E5%AD%A6|2') !== false, 'Japanese tag or count was not exposed');
    wikiDataCheck(strpos($article, 'tag-item">日本語|/wiki/tags/%E6%97%A5%E6%9C%AC%E8%AA%9E|2') !== false, 'second Japanese tag or count was not exposed');
    wikiDataCheck(strpos($article, '下書き') === false, 'draft tag leaked into global tag context');
    wikiDataCheck(strpos($article, '<li class="related"><a href="/wiki/second">第二記事</a></li>') !== false, 'related second article missing');
    wikiDataCheck(strpos($article, '<li class="related"><a href="/wiki/folder/%E7%AC%AC%E4%B8%89">第三項目</a></li>') !== false, 'Japanese related page missing');
    wikiDataCheck(substr_count($article, 'class="related"') === 2, 'related items were not deduplicated or filtered');
    wikiDataCheck(strpos($article, 'href="/wiki/about">About</a></li>') === false, 'image/code link was incorrectly treated as related');
    wikiDataCheck(strpos($article, 'example.com') !== false, 'external link fixture missing');
    wikiDataCheck(strpos($article, '<nav class="toc"') !== false, 'existing page.toc injection regressed');
    wikiDataCheck(strpos($article, 'rel="canonical" href="https://example.test/wiki/article"') !== false, 'article SEO canonical regressed');

    [$cachedStatus, $cachedArticle] = runWikiDataApp($app, '/wiki/article');
    wikiDataCheck($cachedStatus === 200, 'cached article did not render');
    wikiDataCheck(substr_count($cachedArticle, 'class="related"') === 2, 'related items were lost on HTML cache hit');
    wikiDataCheck(strpos($cachedArticle, '<nav class="toc"') !== false, 'TOC was lost on HTML cache hit');

    [$allStatus, $all] = runWikiDataApp($app, '/wiki/all/');
    wikiDataCheck($allStatus === 200, '/all/ did not render');
    wikiDataCheck(strpos($all, 'すべての項目') !== false, '/all/ title missing');
    wikiDataCheck(strpos($all, '起点記事') !== false && strpos($all, '第二記事') !== false, 'public pages missing from /all/');
    wikiDataCheck(strpos($all, '第三項目') !== false, 'subfolder page missing from /all/');
    wikiDataCheck(strpos($all, '下書き') === false, 'draft page leaked into /all/');
    wikiDataCheck(strpos($all, 'rel="canonical" href="https://example.test/wiki/all/"') !== false, '/all/ SEO canonical missing');

    [$folderStatus, $folder] = runWikiDataApp($app, '/wiki/folder/');
    wikiDataCheck($folderStatus === 200, 'virtual folder route regressed');
    wikiDataCheck(strpos($folder, '第三項目') !== false, 'virtual folder page list missing');

    $route = (new Router('/wiki'))->resolve('/wiki/all/');
    wikiDataCheck($route->isValid && $route->urlPath === '/all/', '/all/ did not resolve through the base-path router');

    $draftOnlyTags = (new TagIndex([
        ['draft' => true, 'tags' => ['下書き'], 'url' => '/draft'],
    ], '/wiki'))->items();
    wikiDataCheck($draftOnlyTags === [], 'draft-only tag index should be empty');
    $noTags = (new TagIndex([
        ['draft' => false, 'tags' => [], 'url' => '/empty'],
    ], '/wiki'))->items();
    wikiDataCheck($noTags === [], 'tag-less site should expose an empty structured tag list');

    echo "core_wiki_data_check: OK\n";
} finally {
    removeWikiDataTree($root);
}
