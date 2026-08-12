<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostInboxAutoPublisher.php';

use Tomos\PostInbox;
use Tomos\PostInboxAutoPublisher;
use Tomos\PostUpload;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-inbox-auto-' . bin2hex(random_bytes(6));
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
$draftPath = $inboxPath . DIRECTORY_SEPARATOR . 'draft.md';
$falsePath = $inboxPath . DIRECTORY_SEPARATOR . 'publish-false.md';
$missingPath = $inboxPath . DIRECTORY_SEPARATOR . 'publish-missing.md';
$conflictPath = $inboxPath . DIRECTORY_SEPARATOR . 'conflict.md';
file_put_contents($draftPath, "---\ntitle: draft test\ndraft: true\n---\n# Draft\n");
file_put_contents($falsePath, "---\ntitle: publish test\ndraft: false\n---\n# Publish false\n");
file_put_contents($missingPath, "---\ntitle: publish test\n---\n# Publish missing\n");
file_put_contents($conflictPath, "# Conflict\n");
file_put_contents($content . DIRECTORY_SEPARATOR . 'conflict.md', "# Existing\n");

$inbox = new PostInbox($config, $root);
$upload = new PostUpload($config, $root);
$processor = new PostInboxAutoPublisher($inbox, $upload);
$result = $processor->process('auto-session', str_repeat('a', 64));

if (!is_file($draftPath)) {
    throw new RuntimeException('draft true file must remain in inbox');
}
if (is_file($falsePath) || is_file($missingPath)) {
    throw new RuntimeException('draft false and missing draft files must be published and deleted');
}
if (!is_file($conflictPath) || is_file($content . DIRECTORY_SEPARATOR . 'conflict.md') && file_get_contents($content . DIRECTORY_SEPARATOR . 'conflict.md') !== "# Existing\n") {
    throw new RuntimeException('conflicting file must remain without overwriting existing content');
}
if (count($result['messages']) !== 2 || count($result['warnings']) !== 1) {
    throw new RuntimeException('auto publish result messages do not match expected outcomes');
}

$manual = $upload->handleContent(file_get_contents($draftPath), 'draft.md', '', '', 'manual-session', [], [], false, str_repeat('b', 64));
if (!$manual->ok || !$inbox->delete('draft.md') || is_file($draftPath)) {
    throw new RuntimeException('draft true file must remain manually publishable');
}

echo "post_inbox_auto_publish_check: OK\n";
