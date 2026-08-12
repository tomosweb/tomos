<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostInbox.php';
require_once dirname(__DIR__) . '/core/PostUpload.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-issue-53-' . bin2hex(random_bytes(6));
$content = $root . DIRECTORY_SEPARATOR . 'content';
$cache = $root . DIRECTORY_SEPARATOR . 'cache';
$inboxPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'inbox';
foreach ([$content, $cache, $inboxPath] as $directory) {
    mkdir($directory, 0775, true);
}

$config = [
    'site' => ['url' => 'https://example.test/', 'timezone' => 'Asia/Tokyo'],
    'paths' => ['content_dir' => $content, 'cache_dir' => $cache, 'inbox_dir' => $inboxPath],
    'features' => ['html_cache' => false],
    'metadata' => ['include_drafts' => false],
];
$inbox = new Tomos\PostInbox($config, $root);

$draft = "---\ntitle: Draft\ndate:\nfolder: diary\ndraft: true\ntags:\n  - test\n---\n# Draft\n\n本文中の例:\n\n    draft: true\n";
$released = $inbox->contentForManualPublish($draft);
if (strpos($released, 'draft: false') === false || substr_count($released, 'draft: true') !== 1) {
    throw new RuntimeException('manual publish must clear only the Front Matter draft flag');
}
if ($inbox->contentForManualPublish(str_replace('draft: true', 'draft: false', $draft)) !== str_replace('draft: true', 'draft: false', $draft)) {
    throw new RuntimeException('draft false must remain unchanged');
}
$noDraft = "---\ntitle: Published\n---\n# Published\n";
if ($inbox->contentForManualPublish($noDraft) !== $noDraft) {
    throw new RuntimeException('missing draft must remain unchanged');
}

$upload = new Tomos\PostUpload($config, $root);
$success = $upload->handleContent($released, 'manual.md', 'diary', '', 'session', [], [], false, str_repeat('a', 64));
if (!$success->ok) {
    throw new RuntimeException('released draft could not be published: ' . implode('; ', $success->errors));
}
$saved = file_get_contents($content . DIRECTORY_SEPARATOR . 'diary' . DIRECTORY_SEPARATOR . 'manual.md');
if (!is_string($saved) || strpos($saved, 'draft: false') === false || !preg_match('/^date: \d{4}-\d{2}-\d{2}$/m', $saved)) {
    throw new RuntimeException('manual publish did not save draft false and completed date');
}

$failedDraft = "---\ntitle: Failed\ndraft: true\n---\n# Failed\n";
file_put_contents($inboxPath . DIRECTORY_SEPARATOR . 'failed.md', $failedDraft);
$failedRead = $inbox->read('failed.md');
$failedUpload = new Tomos\PostUpload($config, $root);
@unlink($content . DIRECTORY_SEPARATOR . 'diary' . DIRECTORY_SEPARATOR . 'manual.md');
@rmdir($content . DIRECTORY_SEPARATOR . 'diary');
@rmdir($content);
$failedResult = $failedUpload->handleContent(
    $inbox->contentForManualPublish($failedRead->content),
    'failed.md',
    '',
    '',
    'session',
    [],
    [],
    false,
    str_repeat('b', 64)
);
if ($failedResult->ok || file_get_contents($inboxPath . DIRECTORY_SEPARATOR . 'failed.md') !== $failedDraft) {
    throw new RuntimeException('failed manual publish must preserve original draft inbox content');
}

mkdir($content, 0775, true);
file_put_contents($content . DIRECTORY_SEPARATOR . 'conflict.md', "# Existing\n");
$conflictUpload = new Tomos\PostUpload($config, $root);
$conflictDraft = "---\ntitle: Conflict\ndraft: true\n---\n# Conflict\n";
file_put_contents($inboxPath . DIRECTORY_SEPARATOR . 'conflict.md', $conflictDraft);
$conflictRead = $inbox->read('conflict.md');
$conflictResult = $conflictUpload->handleContent(
    $inbox->contentForManualPublish($conflictRead->content),
    'conflict.md',
    '',
    '',
    'session',
    [],
    [],
    false,
    str_repeat('c', 64)
);
if (!$conflictResult->conflict || file_get_contents($inboxPath . DIRECTORY_SEPARATOR . 'conflict.md') !== $conflictDraft) {
    throw new RuntimeException('conflict must preserve original draft inbox content');
}

echo "issue_53_manual_inbox_draft_check: OK\n";
