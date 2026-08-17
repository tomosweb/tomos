<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostInboxAutoPublisher.php';
require_once dirname(__DIR__) . '/core/PostUpload.php';

use Tomos\PostInbox;
use Tomos\PostInboxAutoPublisher;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-inbox-delete-failure-' . bin2hex(random_bytes(6));
$content = $root . DIRECTORY_SEPARATOR . 'content';
$cache = $root . DIRECTORY_SEPARATOR . 'cache';
$inboxPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'inbox';
foreach ([$content, $cache, $inboxPath] as $directory) {
    if (!mkdir($directory, 0775, true)) {
        throw new RuntimeException('test directory could not be created');
    }
}

$config = [
    'site' => ['url' => 'https://example.test/', 'timezone' => 'Asia/Tokyo'],
    'paths' => ['content_dir' => $content, 'cache_dir' => $cache, 'inbox_dir' => $inboxPath],
    'features' => ['html_cache' => false],
    'metadata' => ['include_drafts' => false],
];

$inbox = new PostInbox($config, $root);
$processor = new PostInboxAutoPublisher($inbox, $config, $root);
$sourcePath = $inboxPath . DIRECTORY_SEPARATOR . 'retry.md';
file_put_contents($sourcePath, "---\ntitle: Retry publish\n---\n# Retry publish\n\nBody.\n");

// The auto-publish lock must already exist so the processor can open it while
// the inbox directory is intentionally non-writable for the delete-failure test.
file_put_contents($inbox->autoPublishLockPath(), '');
chmod($inbox->autoPublishLockPath(), 0600);
chmod($inboxPath, 0555);

try {
    $first = $processor->process('auto-session', str_repeat('a', 64));
    $publishedPath = $content . DIRECTORY_SEPARATOR . 'retry.md';
    if (!is_file($publishedPath)) {
        throw new RuntimeException('first pass must publish the inbox item');
    }
    if (!is_file($sourcePath)) {
        throw new RuntimeException('inbox item must remain when deletion fails');
    }
    if (count($first['messages']) !== 0 || count($first['warnings']) !== 1) {
        throw new RuntimeException('delete failure must be reported as one warning');
    }
    if (strpos((string) $first['warnings'][0], '公開されましたが、受信箱から削除できませんでした') === false) {
        throw new RuntimeException('delete-failure warning must distinguish publish success from cleanup failure');
    }

    $publishedBeforeRetry = (string) file_get_contents($publishedPath);
    $hashBeforeRetry = hash('sha256', $publishedBeforeRetry);

    chmod($inboxPath, 0755);
    $second = $processor->process('auto-session-2', str_repeat('b', 64));

    if (!is_file($sourcePath)) {
        throw new RuntimeException('retry conflict must leave the inbox source for manual resolution');
    }
    $publishedAfterRetry = (string) file_get_contents($publishedPath);
    if (!hash_equals($hashBeforeRetry, hash('sha256', $publishedAfterRetry))) {
        throw new RuntimeException('retry after delete failure must not overwrite already-published content');
    }
    if (count($second['messages']) !== 0 || count($second['warnings']) !== 1) {
        throw new RuntimeException('retry must stop at conflict without reporting a second publication');
    }

    echo "post_inbox_delete_failure_idempotency_check: OK\n";
} finally {
    @chmod($inboxPath, 0755);
    removeTree($root);
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
