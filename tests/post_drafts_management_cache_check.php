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

function assertDraft(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/tomos-draft-cache-' . bin2hex(random_bytes(6));
$content = $root . '/content';
$cache = $root . '/cache';
$inbox = $root . '/storage/inbox';
mkdir($content, 0777, true);
mkdir($cache, 0777, true);
mkdir($inbox, 0777, true);

$config = [
    'paths' => [
        'content_dir' => $content,
        'cache_dir' => $cache,
        'inbox_dir' => $inbox,
    ],
    'site' => ['base_path' => ''],
];

file_put_contents($content . '/draft.md', "---\ntitle: Draft One\ndraft: true\n---\nDraft body\n");
file_put_contents($content . '/published.md', "---\ntitle: Published\ndraft: false\n---\nPublished body\n");

$drafts = new Tomos\PostDrafts($config, $root);
$first = $drafts->list();
assertDraft(count($first) === 1, 'first list must contain one draft');
assertDraft($first[0]->title === 'Draft One', 'first list must use draft metadata');

$managementIndex = $cache . '/index/post-articles.json';
assertDraft(is_file($managementIndex), 'first list must create management index fallback');
$firstHash = hash_file('sha256', $managementIndex);
assertDraft(is_string($firstHash), 'management index hash must be readable');

$second = $drafts->list();
$secondHash = hash_file('sha256', $managementIndex);
assertDraft(count($second) === 1, 'second list must contain one draft');
assertDraft($second[0]->title === 'Draft One', 'second list must preserve draft metadata');
assertDraft($firstHash === $secondHash, 'fresh management index must not be rewritten on repeated list');

sleep(1);
file_put_contents($content . '/draft.md', "---\ntitle: Draft Two\ndraft: true\n---\nChanged draft body\n");
clearstatcache(true, $content . '/draft.md');
$third = $drafts->list();
assertDraft(count($third) === 1, 'stale cache rebuild must still contain one draft');
assertDraft($third[0]->title === 'Draft Two', 'stale management index must rebuild from Markdown source');
$thirdHash = hash_file('sha256', $managementIndex);
assertDraft(is_string($thirdHash) && $thirdHash !== $secondHash, 'stale management index must be replaced');

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($root);

echo "Post drafts management cache checks passed.\n";
