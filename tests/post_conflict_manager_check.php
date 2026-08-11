<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostConflictManager.php';

use Tomos\FrontMatterParser;
use Tomos\PostConflictManager;
use Tomos\PostEditableMarkdown;
use Tomos\PostSubmissionPreparer;
use Tomos\PostUploadTempStore;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-conflict-' . bin2hex(random_bytes(6));
$content = $root . DIRECTORY_SEPARATOR . 'content';
$cache = $root . DIRECTORY_SEPARATOR . 'cache';
if (!mkdir($content, 0775, true) || !mkdir($cache, 0775, true)) {
    throw new RuntimeException('test directories could not be created');
}

$config = [
    'paths' => ['content_dir' => $content, 'cache_dir' => $cache],
    'site' => ['url' => 'https://example.test/'],
];
$editable = new PostEditableMarkdown($config, $root);
$preparer = new PostSubmissionPreparer($editable);
$manager = new PostConflictManager(
    $content,
    $config['site'],
    new FrontMatterParser(),
    new PostUploadTempStore($cache),
    $editable,
    $preparer
);

$submissionId = str_repeat('a', 64);
$target = $content . DIRECTORY_SEPARATOR . 'article.md';
$decision = $manager->inspectNew(
    $target,
    'article.md',
    "# New\n",
    '',
    'article.md',
    'article.md',
    [],
    [],
    'session-a',
    $submissionId
);
if (!$decision->ok || $decision->requiresConfirmation || $decision->action !== 'create') {
    throw new RuntimeException('non-conflicting post must be publishable');
}

file_put_contents($target, "# Existing\n");
$conflict = $manager->inspectNew(
    $target,
    'article.md',
    "# Replacement\n",
    '',
    'article.md',
    'article.md',
    [],
    [],
    'session-a',
    $submissionId
);
if (!$conflict->requiresConfirmation || $conflict->tempId === null || $conflict->action !== 'conflict') {
    throw new RuntimeException('existing post must require confirmation and create temp data');
}

$wrongSubmission = $manager->loadForAction($conflict->tempId, 'session-a', str_repeat('b', 64));
if ($wrongSubmission->ok || $wrongSubmission->errors !== ['投稿の送信情報と確認用データを照合できませんでした。もう一度投稿してください。']) {
    throw new RuntimeException('submission ID mismatch must be rejected');
}

$loaded = $manager->loadForAction($conflict->tempId, 'session-a', $submissionId);
if (!$loaded->ok || $loaded->record === null) {
    throw new RuntimeException('matching submission ID must load the temp record');
}

file_put_contents($target, "# Changed after confirmation\n");
$hashMismatch = $manager->validateExistingUpdate($loaded->record);
if ($hashMismatch->ok || $hashMismatch->errors !== ['確認後に更新対象の内容が変わりました。もう一度投稿内容を確認してください。']) {
    throw new RuntimeException('changed article must be rejected by hash validation');
}
if ($manager->loadTemp($conflict->tempId, 'session-a') !== null) {
    throw new RuntimeException('hash mismatch must preserve existing temp cleanup timing');
}

file_put_contents($target, "# Existing again\n");
$renameConflict = $manager->inspectNew(
    $target,
    'article.md',
    "# Another\n",
    '',
    'article.md',
    'article.md',
    [],
    [],
    'session-a',
    $submissionId
);
if ($renameConflict->record === null) {
    throw new RuntimeException('rename fixture temp record was not created');
}
file_put_contents($content . DIRECTORY_SEPARATOR . 'renamed.md', "# Occupied\n");
$rename = $manager->prepareRename($renameConflict->record, 'renamed.md');
if ($rename->ok || $rename->errors !== ['このファイル名もすでに使われています。別のファイル名を指定してください。']) {
    throw new RuntimeException('rename destination conflict must be rejected');
}
$manager->delete($renameConflict->tempId ?? '');

echo "post_conflict_manager_check: OK\n";
