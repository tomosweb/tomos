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

use Tomos\FrontMatterParser;
use Tomos\MetadataIndex;
use Tomos\PageCatalogBuilder;

function pageCatalogBuilderCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pageCatalogWrite(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('could not create fixture directory');
    }
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('could not write fixture');
    }
}

function pageCatalogRemoveTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        pageCatalogRemoveTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . '/tomos-page-catalog-' . bin2hex(random_bytes(6));
$content = $root . '/content';
$cache = $root . '/cache';

try {
    mkdir($content, 0700, true);
    mkdir($cache, 0700, true);

    pageCatalogWrite($content . '/index.md', "---\ntitle: Home\ndraft: false\n---\nHome\n");
    pageCatalogWrite($content . '/article.md', "---\ntitle: Article\ntags:\n  - alpha\ndraft: false\n---\nBody [About](/about)\n");
    pageCatalogWrite($content . '/about.md', "---\ntitle: About\nlanguage: en\ndraft: false\n---\nAbout body\n");
    pageCatalogWrite($content . '/draft.md', "---\ntitle: Draft\ndraft: true\n---\nHidden\n");
    pageCatalogWrite($content . '/日本語/記事.md', "---\ntitle: 日本語記事\ndraft: false\n---\n本文\n");

    $parser = new FrontMatterParser();

    $builder = new PageCatalogBuilder($content, $parser, false, 'ja');
    $built = $builder->build();

    $index = new MetadataIndex($content, $cache, $parser, false, 'ja');
    $indexed = $index->build();

    pageCatalogBuilderCheck($built === $indexed, 'MetadataIndex::build must delegate to PageCatalogBuilder without changing output');
    pageCatalogBuilderCheck(count($built) === 4, 'public catalog count mismatch');

    $byPath = [];
    foreach ($built as $page) {
        $byPath[(string) $page['path']] = $page;
    }

    pageCatalogBuilderCheck(isset($byPath['index.md']), 'home page missing');
    pageCatalogBuilderCheck(($byPath['index.md']['url'] ?? null) === '/', 'home URL mismatch');
    pageCatalogBuilderCheck(($byPath['about.md']['page_type'] ?? null) === 'fixed_page', 'about page type mismatch');
    pageCatalogBuilderCheck(($byPath['about.md']['language'] ?? null) === 'en', 'explicit language mismatch');
    pageCatalogBuilderCheck(($byPath['article.md']['language'] ?? null) === 'ja', 'default language fallback mismatch');
    pageCatalogBuilderCheck(isset($byPath['日本語/記事.md']), 'UTF-8 path missing');
    pageCatalogBuilderCheck(!isset($byPath['draft.md']), 'draft leaked into public catalog');
    pageCatalogBuilderCheck(strpos((string) ($byPath['article.md']['search_text'] ?? ''), 'Article') !== false, 'search text missing title');

    $managementBuilder = new PageCatalogBuilder($content, $parser, true, 'ja');
    $withDrafts = $managementBuilder->build();
    $withDraftPaths = array_map(static function (array $page): string {
        return (string) ($page['path'] ?? '');
    }, $withDrafts);
    pageCatalogBuilderCheck(in_array('draft.md', $withDraftPaths, true), 'includeDrafts catalog did not include draft');

    echo "page_catalog_builder_check: OK\n";
} finally {
    pageCatalogRemoveTree($root);
}
