<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/LinkAliasIndex.php';

use Tomos\LinkAliasIndex;

$passes = 0;

function check(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            removeTree($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
}

function writePages(string $file, array $pages): void
{
    $json = json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json) || file_put_contents($file, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('pages fixture write failed');
    }
}

$root = sys_get_temp_dir() . '/tomos-link-generation-' . bin2hex(random_bytes(8));
$cacheDir = $root . '/cache';
$indexDir = $cacheDir . '/index';
if (!mkdir($indexDir, 0700, true) && !is_dir($indexDir)) {
    throw new RuntimeException('fixture directory failed');
}

try {
    $oldPages = [[
        'path' => 'old.md',
        'url' => '/old/',
        'title' => 'Old page',
        'draft' => false,
    ]];
    $newPages = [[
        'path' => 'new.md',
        'url' => '/new/',
        'title' => 'New page',
        'draft' => false,
    ]];

    $pagesFile = $indexDir . '/pages.json';
    $index = new LinkAliasIndex($cacheDir);

    writePages($pagesFile, $oldPages);
    $oldAliases = $index->build($oldPages);
    check(isset($oldAliases['pages_fingerprint']), 'alias build records pages fingerprint');
    $index->save($oldAliases);
    check($index->exists(), 'matching pages and aliases are accepted');
    check(($index->load()['aliases']['Old page'] ?? '') === '/old/', 'matching alias data loads');

    // Simulates MetadataIndex committing pages.json and LinkAliasIndex::save failing.
    // The old alias file must not be consumed with the new pages generation.
    writePages($pagesFile, $newPages);
    check(!$index->exists(), 'stale alias generation is rejected');
    check($index->load() === ['aliases' => [], 'conflicts' => []], 'stale alias data fails closed');

    $index->save($index->build($newPages));
    check($index->exists(), 'rebuilt alias generation is accepted');
    check(($index->load()['aliases']['New page'] ?? '') === '/new/', 'rebuilt aliases load');

    $legacy = [
        'version' => 1,
        'aliases' => ['Old page' => '/old/'],
        'conflicts' => [],
    ];
    file_put_contents($index->indexFile(), json_encode($legacy, JSON_PRETTY_PRINT) . "\n", LOCK_EX);
    check(!$index->exists(), 'legacy alias without generation fingerprint rebuilds once');
    check($index->load() === ['aliases' => [], 'conflicts' => []], 'legacy alias fails closed until rebuilt');

    echo 'link_alias_generation_consistency_check: ' . $passes . " checks passed\n";
} finally {
    removeTree($root);
}
