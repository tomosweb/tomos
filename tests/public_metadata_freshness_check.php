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

use Tomos\FrontMatterParser;
use Tomos\MetadataIndex;
use Tomos\PublicMetadataFreshener;

function failCheck(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full) && !is_link($full)) {
            removeTree($full);
        } else {
            @unlink($full);
        }
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-public-metadata-' . bin2hex(random_bytes(6));
$contentDir = $root . DIRECTORY_SEPARATOR . 'content';
$cacheDir = $root . DIRECTORY_SEPARATOR . 'cache';
@mkdir($contentDir . DIRECTORY_SEPARATOR . 'news', 0775, true);
@mkdir($cacheDir, 0775, true);

$config = [
    'paths' => [
        'content_dir' => $contentDir,
        'cache_dir' => $cacheDir,
    ],
    'features' => [
        'metadata_cache' => true,
    ],
    'metadata' => [
        'include_drafts' => false,
    ],
];

try {
    file_put_contents(
        $contentDir . DIRECTORY_SEPARATOR . 'news' . DIRECTORY_SEPARATOR . 'a.md',
        "---\ntitle: A\ndate: 2026-08-18\ndraft: false\n---\n\nA\n"
    );

    $index = new MetadataIndex($contentDir, $cacheDir, new FrontMatterParser(), false);
    $initial = $index->rebuild();
    if (count($initial) !== 1) {
        failCheck('Initial metadata index must contain one page.');
    }

    $bFile = $contentDir . DIRECTORY_SEPARATOR . 'news' . DIRECTORY_SEPARATOR . 'b.md';
    file_put_contents(
        $bFile,
        "---\ntitle: B\ndate: 2026-08-19\ndraft: false\n---\n\nB\n"
    );

    if ($index->loadFresh() !== null) {
        failCheck('Adding Markdown outside Tomos must make the metadata index stale.');
    }

    PublicMetadataFreshener::ensure($config);

    $fresh = $index->loadFresh();
    if (!is_array($fresh) || count($fresh) !== 2) {
        failCheck('Public metadata refresh must rebuild a stale index.');
    }

    $titles = array_map(static fn(array $page): string => (string) ($page['title'] ?? ''), $fresh);
    sort($titles);
    if ($titles !== ['A', 'B']) {
        failCheck('Rebuilt metadata index must contain the newly deployed Markdown page.');
    }

    $schemaFile = $cacheDir . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'metadata-schema.txt';
    if (!is_file($schemaFile) || trim((string) file_get_contents($schemaFile)) !== '3') {
        failCheck('Public metadata refresh must persist the current cache schema marker.');
    }

    file_put_contents($schemaFile, "2\n");
    PublicMetadataFreshener::ensure($config);
    if (!is_file($schemaFile) || trim((string) file_get_contents($schemaFile)) !== '3') {
        failCheck('An older public metadata schema marker must force a refresh.');
    }

    $indexedMtime = filemtime($bFile);
    $indexedSize = filesize($bFile);
    if ($indexedMtime === false || $indexedSize === false) {
        failCheck('Metadata cache fixture source stats could not be read.');
    }

    $changed = "---\ntitle: C\ndate: 2026-08-19\ndraft: false\n---\n\nC\n";
    if (strlen($changed) !== $indexedSize) {
        failCheck('Metadata cache fixture must preserve source size.');
    }
    file_put_contents($bFile, $changed);
    if (!touch($bFile, $indexedMtime, $indexedMtime)) {
        failCheck('Metadata cache fixture source mtime could not be restored.');
    }
    clearstatcache(true, $bFile);
    @unlink($schemaFile);

    if ($index->loadFresh() !== null) {
        failCheck('Content hash changes must make metadata cache stale even when file stats are unchanged.');
    }

    PublicMetadataFreshener::ensure($config);
    $rebuiltForSchema = $index->loadFresh();
    $rebuiltTitles = is_array($rebuiltForSchema)
        ? array_map(static fn(array $page): string => (string) ($page['title'] ?? ''), $rebuiltForSchema)
        : [];
    if (in_array('B', $rebuiltTitles, true) || !in_array('C', $rebuiltTitles, true)) {
        failCheck('Missing cache schema marker must force rebuild even when file stats are unchanged.');
    }
    if (!is_file($schemaFile) || trim((string) file_get_contents($schemaFile)) !== '3') {
        failCheck('Schema-forced rebuild must restore the current cache schema marker.');
    }

    $indexFile = $index->indexFile();
    $before = is_file($indexFile) ? (string) file_get_contents($indexFile) : '';
    PublicMetadataFreshener::ensure([
        'paths' => [
            'content_dir' => $contentDir,
            'cache_dir' => $cacheDir,
        ],
        'features' => [
            'metadata_cache' => false,
        ],
    ]);
    $after = is_file($indexFile) ? (string) file_get_contents($indexFile) : '';
    if ($before !== $after) {
        failCheck('Disabled metadata cache must not be rewritten by the public freshener.');
    }

    echo "public_metadata_freshness_check: OK\n";
} finally {
    removeTree($root);
}
