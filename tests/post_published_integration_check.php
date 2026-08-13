<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) return;
    $file = dirname(__DIR__) . '/core/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($file)) require_once $file;
});

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-published-integration-' . bin2hex(random_bytes(6));
$content = $root . '/content';
$cache = $root . '/cache';
mkdir($content . '/images', 0775, true);
mkdir($cache . '/html', 0775, true);
$config = [
    'paths' => ['content_dir' => $content, 'cache_dir' => $cache],
    'features' => ['html_cache' => false],
    'metadata' => ['include_drafts' => false],
    'site' => ['url' => 'https://example.test'],
];

$shared = 'tms-aaaaaaaaaaaaaaaa.png';
$unique = 'tms-bbbbbbbbbbbbbbbb.png';
file_put_contents($content . '/images/' . $shared, 'shared');
file_put_contents($content . '/images/' . $unique, 'unique');
file_put_contents($content . '/article.md', "---\ntitle: Article\ndate: 2026-08-13\n---\n![shared](images/{$shared})\n![unique](images/{$unique})\n");
file_put_contents($content . '/other.md', "---\ntitle: Other\ndate: 2026-08-12\n---\n![shared](images/{$shared})\n");
file_put_contents($content . '/draft.md', "---\ndraft: true\n---\nDraft\n");

$download = (new Tomos\PostEditableMarkdown($config, $root))->download('article.md');
assertTrue(!empty($download['ok']), 'published markdown must download');
assertSame('article.md', $download['source_path'] ?? '', 'download source path must be preserved');
assertSame(true, strpos((string) ($download['content'] ?? ''), 'tomos_source_hash:') !== false, 'download helpers must remain present');
assertTrue(empty((new Tomos\PostEditableMarkdown($config, $root))->download('../article.md')['ok']), 'dot-dot download must fail');
assertTrue(empty((new Tomos\PostEditableMarkdown($config, $root))->download('article.txt')['ok']), 'non-markdown download must fail');

$withdraw = (new Tomos\PostWithdraw($config, $root))->withdraw('article.md');
assertTrue($withdraw->ok, 'normal article withdrawal must succeed');
assertSame(true, is_file($root . '/trash/content/article.md'), 'withdrawn markdown must move to trash');
assertSame(false, is_file($content . '/images/' . $unique), 'unreferenced image must be removed');
assertSame(true, is_file($content . '/images/' . $shared), 'shared image must be kept');
assertSame(false, is_file($cache . '/index/pages.json'), 'pages index must be invalidated');
assertTrue(empty((new Tomos\PostWithdraw($config, $root))->withdraw('article.md')->ok), 'second withdrawal must not move twice');
assertTrue(empty((new Tomos\PostWithdraw($config, $root))->withdraw('index.md')->ok), 'protected page withdrawal must fail');
assertTrue(empty((new Tomos\PostWithdraw($config, $root))->withdraw('../other.md')->ok), 'unsafe path withdrawal must fail');
assertSame(false, (new Tomos\PostPublished($config, $root))->isPublishedPath('article.md'), 'withdrawn article must leave published index');

rrmdir($root);
echo "post_published_integration_check: OK\n";

function assertTrue(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException($message);
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nexpected: " . var_export($expected, true) . "\nactual: " . var_export($actual, true));
    }
}

function rrmdir(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) rrmdir($child); else @unlink($child);
    }
    @rmdir($path);
}
