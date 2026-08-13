<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/core/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-published-' . bin2hex(random_bytes(6));
$content = $root . DIRECTORY_SEPARATOR . 'content';
$cache = $root . DIRECTORY_SEPARATOR . 'cache';
mkdir($content . DIRECTORY_SEPARATOR . 'notes', 0775, true);
mkdir($content . DIRECTORY_SEPARATOR . 'images', 0775, true);
mkdir($cache, 0775, true);

$write = static function (string $path, string $contents): void {
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('could not write fixture: ' . $path);
    }
};

$write($content . DIRECTORY_SEPARATOR . 'index.md', "---\ntitle: Home\ndate: 2026-01-01\n---\n# Home\n");
$write($content . DIRECTORY_SEPARATOR . 'about.md', "---\ntitle: About\n---\n# About\n");
$write($content . DIRECTORY_SEPARATOR . 'new.md', "---\ntitle: 新しい記事\ndate: 2026-08-10\nupdated: 2026-08-12\ntags:\n- 京都\n---\n京都の本文検索語\n");
$write($content . DIRECTORY_SEPARATOR . 'notes' . DIRECTORY_SEPARATOR . 'old.md', "---\ntitle: Old Article\ndate: 2025-05-02\n---\n過去の記事\n");
$write($content . DIRECTORY_SEPARATOR . 'draft.md', "---\ntitle: Draft\ndate: 2026-08-11\ndraft: true\n---\nDraft only\n");
$write($content . DIRECTORY_SEPARATOR . 'invalid-date.md', "---\ntitle: Invalid Date\ndate: 2026-99-99\n---\nNo year\n");

$config = [
    'paths' => ['content_dir' => $content, 'cache_dir' => $cache],
];
$published = new Tomos\PostPublished($config, $root);

$result = $published->list('', '', 1, 50);
assertTrue($result['ok'], 'published list must load');
assertSame(5, $result['total'], 'draft must be excluded while protected pages remain visible');
assertSame(['invalid-date.md', 'new.md', 'index.md', 'notes/old.md', 'about.md'], array_column($result['items'], 'path'), 'PageSorter order must be retained');
assertSame(['2026', '2025'], $result['years'], 'years must be valid and descending');
assertSame(true, (bool) $result['items'][2]['protected'], 'home must be marked protected for display');
assertSame(false, (bool) $result['items'][1]['protected'], 'ordinary article must not be protected');
assertSame(['京都'], $result['items'][1]['tags'], 'tags must be exposed');

$search = $published->list('京都', '', 1, 50);
assertSame(['new.md'], array_column($search['items'], 'path'), 'search_text must be searchable');
$titleSearch = $published->list('Old Article', '', 1, 50);
assertSame(['notes/old.md'], array_column($titleSearch['items'], 'path'), 'title must be searchable');
$pathSearch = $published->list('notes/old', '', 1, 50);
assertSame(['notes/old.md'], array_column($pathSearch['items'], 'path'), 'path must be searchable');
$filenameSearch = $published->list('old.md', '', 1, 50);
assertSame(['notes/old.md'], array_column($filenameSearch['items'], 'path'), 'filename must be searchable');

$year = $published->list('', '2026', 1, 50);
assertSame(['new.md', 'index.md'], array_column($year['items'], 'path'), 'year filter must exclude invalid and other years');
$combined = $published->list('京都', '2026', 1, 50);
assertSame(['new.md'], array_column($combined['items'], 'path'), 'search and year filter must combine');
$empty = $published->list('not-found', '', 99, 50);
assertSame(1, $empty['page'], 'empty result page must be one');
assertSame([], $empty['items'], 'empty result must have no items');

for ($index = 0; $index < 105; $index++) {
    $write($content . DIRECTORY_SEPARATOR . 'notes' . DIRECTORY_SEPARATOR . 'bulk-' . $index . '.md', "---\ntitle: Bulk {$index}\ndate: 2024-01-01\n---\nBulk\n");
}
$first = $published->list('', '2024', 0, 50);
assertSame(1, $first['page'], 'page below one must be one');
assertSame(50, count($first['items']), 'page size must be fifty');
$last = $published->list('', '2024', 999, 50);
assertSame(3, $last['page'], 'page over the end must be the last page');
assertSame(5, count($last['items']), 'last page must contain remaining items');
assertSame(true, $published->isPublishedPath('new.md'), 'published path must be accepted');
assertSame(false, $published->isPublishedPath('draft.md'), 'draft path must not be accepted');
assertSame(false, $published->isPublishedPath('../new.md'), 'unsafe path must not be accepted by index lookup');

rrmdir($root);
echo "post_published_check: OK\n";

function assertTrue(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nexpected: " . var_export($expected, true) . "\nactual: " . var_export($actual, true));
    }
}

function rrmdir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) {
            rrmdir($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}
