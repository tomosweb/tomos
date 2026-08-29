<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostInboxAutoPublisher.php';
require_once dirname(__DIR__) . '/core/PostUpload.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-inbox-resubmit-' . bin2hex(random_bytes(6));
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

$original = "---\ntitle: Resubmission\ndate: 2026-08-22\n---\n# Resubmission\n\nOriginal body.\n";
$inbox = new \Tomos\PostInbox($config, $root);
$processor = new \Tomos\PostInboxAutoPublisher($inbox, $config, $root);
$sourcePath = $inboxPath . DIRECTORY_SEPARATOR . 'resubmission.md';
$publishedPath = $content . DIRECTORY_SEPARATOR . 'resubmission.md';

file_put_contents($sourcePath, $original);
$first = $processor->process('session-a', str_repeat('a', 64));
if (!is_file($publishedPath) || is_file($sourcePath) || count($first['messages']) !== 1) {
    throw new RuntimeException('initial Inbox article was not published');
}
$published = (string) file_get_contents($publishedPath);
preg_match('/^published: .*$/m', $published, $publishedMatch);
$publishedValue = $publishedMatch[0] ?? '';

file_put_contents($sourcePath, $original);
$same = $processor->process('session-b', str_repeat('b', 64));
if (is_file($sourcePath) || count($same['messages']) !== 1 || $same['warnings'] !== []) {
    throw new RuntimeException('identical resubmission was not idempotent');
}

$corrected = "---\ntitle: Resubmission\ndate: 2026-08-25\n---\n# Resubmission\n\nCorrected body.\n";
file_put_contents($sourcePath, $corrected);
$candidate = $processor->process('session-c', str_repeat('c', 64));
if (!is_file($sourcePath) || count($candidate['pending']) !== 1 || $candidate['warnings'] !== []) {
    throw new RuntimeException('changed resubmission was not retained as an update candidate');
}

$upload = new \Tomos\PostUpload($config, $root);
$tempId = $candidate['pending'][0]['temp_id'];
$updated = $upload->updateFromTemp($tempId, 'session-c', str_repeat('c', 64));
if (!$updated->ok || !$inbox->delete('resubmission.md')) {
    throw new RuntimeException('update candidate could not be confirmed and finalized');
}
$final = (string) file_get_contents($publishedPath);
if (strpos($final, 'date: 2026-08-25') === false || strpos($final, 'Corrected body.') === false || strpos($final, $publishedValue) === false) {
    throw new RuntimeException('confirmed update did not change date/body while preserving published metadata');
}

$draftPath = $inboxPath . DIRECTORY_SEPARATOR . 'draft.md';
file_put_contents($draftPath, "---\ntitle: Draft\ndraft: true\n---\n# Draft\n");
$draft = $processor->process('session-d', str_repeat('d', 64));
if (!is_file($draftPath) || $draft['pending'] !== []) {
    throw new RuntimeException('draft was incorrectly converted to an update candidate');
}

echo "post_inbox_resubmission_check: OK\n";
removeTree($root);

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $candidate = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($candidate) ? removeTree($candidate) : @unlink($candidate);
    }
    @rmdir($path);
}
