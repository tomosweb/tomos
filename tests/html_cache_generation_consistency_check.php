<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/HtmlCache.php';

use Tomos\HtmlCache;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-html-cache-generation-' . bin2hex(random_bytes(6));
$cacheDir = $root . DIRECTORY_SEPARATOR . 'cache';
$source = $root . DIRECTORY_SEPARATOR . 'content.md';
mkdir($root, 0700, true);
file_put_contents($source, "# source\n");
$cache = new HtmlCache($cacheDir, true);

try {
    assertSame(true, $cache->write('content.md', $source, '<p>generation-a</p>'), 'initial cache write must succeed');
    assertSame(true, $cache->isFresh('content.md', $source), 'matching HTML/meta generation must be fresh');
    assertSame('<p>generation-a</p>', $cache->read('content.md', $source), 'matching cache must be readable');

    $htmlPath = $cache->getPath('content.md');
    $key = $cache->makeKey('content.md');
    $metaPath = $cacheDir . DIRECTORY_SEPARATOR . 'html' . DIRECTORY_SEPARATOR . $key . '.json';
    $metaA = (string) file_get_contents($metaPath);

    file_put_contents($htmlPath, '<p>tampered-generation</p>');
    assertSame(false, $cache->isFresh('content.md', $source), 'HTML/meta hash mismatch must be rejected');
    assertSame(null, $cache->read('content.md', $source), 'mismatched cache must fail closed');

    assertSame(true, $cache->write('content.md', $source, '<p>generation-b</p>'), 'second cache write must succeed');
    file_put_contents($metaPath, $metaA);
    assertSame(false, $cache->isFresh('content.md', $source), 'old metadata with new HTML must be rejected');
    assertSame(null, $cache->read('content.md', $source), 'cross-generation pair must not be consumed');

    assertSame(true, $cache->write('content.md', $source, '<p>generation-c</p>'), 'cache must recover through normal rewrite');
    assertSame(true, $cache->isFresh('content.md', $source), 'rewritten cache must become fresh again');
    assertSame('<p>generation-c</p>', $cache->read('content.md', $source), 'rewritten cache must be readable');

    $fixedTemps = glob($cacheDir . DIRECTORY_SEPARATOR . 'html' . DIRECTORY_SEPARATOR . '*.tmp') ?: [];
    assertSame([], $fixedTemps, 'fixed .tmp cache files must not be used');

    echo "html_cache_generation_consistency_check: OK\n";
} finally {
    removeTree($root);
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
