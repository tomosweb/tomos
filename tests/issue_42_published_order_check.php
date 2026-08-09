<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) return;
    $file = dirname(__DIR__) . '/core/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($file)) require_once $file;
});

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nexpected: ' . var_export($expected, true) . '\nactual: ' . var_export($actual, true));
    }
}

function paths(array $pages): array
{
    return array_column($pages, 'path');
}

$pages = [
    ['path' => 'legacy-b.md', 'title' => 'Legacy B', 'date' => '2026-08-09', 'published' => null, 'url' => '/legacy-b/'],
    ['path' => 'older.md', 'title' => 'Older', 'date' => '2026-08-08', 'published' => '2026-08-10T23:00:00+09:00', 'url' => '/older/'],
    ['path' => 'first.md', 'title' => 'First', 'date' => '2026-08-09', 'published' => '2026-08-09T10:00:00+09:00', 'url' => '/first/'],
    ['path' => 'legacy-a.md', 'title' => 'Legacy A', 'date' => '2026-08-09', 'published' => null, 'url' => '/legacy-a/'],
    ['path' => 'second.md', 'title' => 'Second', 'date' => '2026-08-09', 'published' => '2026-08-09T11:00:00+09:00', 'url' => '/second/'],
];
$expected = ['second.md', 'first.md', 'legacy-a.md', 'legacy-b.md', 'older.md'];
assertSameValue($expected, paths(Tomos\PageSorter::sort($pages)), 'date/published/path order');
assertSameValue($expected, paths(Tomos\PageSorter::sort(array_reverse($pages))), 'order must not depend on filesystem input order');

$firstMarkdown = "---\ndate: 2026-08-09\npublished: 2026-08-09T10:00:00+09:00\nupdated: 2026-08-09\n---\n# First\n";
$edited = Tomos\PublishedMetadata::addIfMissing($firstMarkdown, '2026-08-09T12:00:00+09:00');
assertSameValue($firstMarkdown, $edited, 'editing must preserve the initial published value');
$legacy = Tomos\PublishedMetadata::addIfMissing("---\ndate: 2026-08-09\n---\n# Legacy\n", '2026-08-09T12:00:00+09:00');
if (strpos($legacy, "date: 2026-08-09\npublished: 2026-08-09T12:00:00+09:00") === false) {
    throw new RuntimeException('published must be inserted after date');
}

$parser = new Tomos\FrontMatterParser();
$parsed = $parser->parse($firstMarkdown);
$metadata = $parser->buildPageMetadata($parsed['metadata'], $parsed['body'], 'first.md');
assertSameValue('2026-08-09T10:00:00+09:00', $metadata['published'], 'front matter parser must retain published');

$navigation = new Tomos\NavigationBuilder('');
foreach (['pageList', 'latestPageList'] as $method) {
    $html = $navigation->{$method}($pages);
    assertSameValue(true, strpos($html, 'Second') < strpos($html, 'First'), $method . ' must use common order');
}
$folderPages = array_map(static function (array $page): array {
    $page['path'] = 'notes/' . $page['path'];
    $page['url'] = '/notes/' . basename($page['path'], '.md') . '/';
    return $page;
}, $pages);
$folderHtml = $navigation->folderPageList($folderPages, 'notes');
assertSameValue(true, strpos($folderHtml, 'Second') < strpos($folderHtml, 'First'), 'folder list must use common order');

$root = sys_get_temp_dir() . '/tomos-issue-42-' . bin2hex(random_bytes(6));
mkdir($root . '/content', 0777, true);
mkdir($root . '/cache', 0777, true);
$upload = new Tomos\PostUpload([
    'paths' => ['content_dir' => $root . '/content', 'cache_dir' => $root . '/cache'],
    'site' => ['timezone' => 'Asia/Tokyo'],
], $root);
$method = new ReflectionMethod($upload, 'withInitialPublishedMetadata');
$newMarkdown = $method->invoke($upload, "---\ndate: 2026-08-09\n---\n# New\n");
if (preg_match('/^published: 2026-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+09:00$/m', $newMarkdown) !== 1) {
    throw new RuntimeException('a new publication must receive an ISO 8601 timestamp in the configured timezone');
}
$draftMarkdown = "---\ndate: 2026-08-09\ndraft: true\n---\n# Draft\n";
assertSameValue($draftMarkdown, $method->invoke($upload, $draftMarkdown), 'saving a draft must not set published');

foreach ($pages as $page) {
    file_put_contents($root . '/content/' . $page['path'], "---\ntitle: {$page['title']}\ndate: {$page['date']}\n"
        . (!empty($page['published']) ? "published: {$page['published']}\n" : '') . "---\nBody\n");
}
$indexed = (new Tomos\MetadataIndex($root . '/content', $root . '/cache'))->build();
assertSameValue($expected, paths($indexed), 'MetadataIndex must establish the shared article order');

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
rmdir($root);

echo "Issue #42 published ordering checks passed.\n";
