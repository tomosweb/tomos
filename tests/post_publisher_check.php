<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostUpload.php';
require_once dirname(__DIR__) . '/core/PageSorter.php';
require_once dirname(__DIR__) . '/core/PublishedMetadata.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-publisher-' . bin2hex(random_bytes(6));
mkdir($root . '/content', 0777, true);
mkdir($root . '/cache', 0777, true);
$contentDir = $root . '/content';
$cacheDir = $root . '/cache';
$publisher = new Tomos\PostPublisher($contentDir, $cacheDir, new Tomos\FrontMatterParser(), false, false, ['timezone' => 'Asia/Tokyo']);

try {
    $target = $contentDir . '/article.md';
    $result = $publisher->publishNew($target, "---\ndate: 2026-08-11\n---\n# New\n", [], '', false);
    assertTrue($result->ok, 'new Markdown must be saved');
    assertSame("---\ndate: 2026-08-11\n---\n# New\n", file_get_contents($target), 'new Markdown content must be preserved');

    $result = $publisher->updateExisting($target, "---\ndate: 2026-08-11\n---\n# Updated\n", [], '');
    assertTrue($result->ok, 'existing Markdown must be safely replaced');
    assertContains('# Updated', (string) file_get_contents($target), 'updated Markdown must be present');

    $publishedTarget = $contentDir . '/published.md';
    $result = $publisher->publishNew($publishedTarget, '# Published\n', [], '');
    assertTrue($result->ok, 'new publication must succeed');
    assertContains('published:', (string) file_get_contents($publishedTarget), 'new publication must add published metadata');

    $result = $publisher->publishNew($target, '# Must not overwrite\n', [], '');
    assertError($result, 'ファイルを保存できませんでした。保存先の権限を確認してください。', 'new save conflict');
    assertContains('# Updated', (string) file_get_contents($target), 'failed new save must preserve existing content');

    $rollbackDir = $contentDir . '/new-rollback';
    mkdir($rollbackDir, 0777, true);
    $rollbackTarget = $rollbackDir . '/article.md';
    file_put_contents($rollbackTarget, '# Existing\n');
    $validGif = $root . '/valid.gif';
    file_put_contents($validGif, base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='));
    $result = $publisher->publishNew(
        $rollbackTarget,
        '# Must not overwrite\n',
        [
            'tms-aaaaaaaaaaaaaaaa.gif' => $validGif,
            'tms-bbbbbbbbbbbbbbbb.gif' => $root . '/missing.gif',
        ],
        'new-rollback'
    );
    assertError($result, '選択された画像を確認できませんでした。', 'image failure during new save');
    assertSame([], glob($rollbackDir . '/images/*') ?: [], 'image failure must roll back previously saved images');
    assertSame('# Existing\n', file_get_contents($rollbackTarget), 'image failure must preserve existing Markdown');

    $updateRollbackDir = $contentDir . '/update-rollback';
    mkdir($updateRollbackDir . '/blocked', 0777, true);
    $result = $publisher->updateExisting(
        $updateRollbackDir . '/blocked',
        '# Must not replace directory\n',
        ['tms-cccccccccccccccc.gif' => $validGif],
        'update-rollback'
    );
    assertError($result, 'ページを更新できませんでした。既存ページは変更していません。', 'safe replace failure');
    assertSame([], glob($updateRollbackDir . '/images/*') ?: [], 'safe replace failure must roll back saved images');

    $warnings = $publisher->rebuildIndexes('article.md');
    assertSame([], $warnings, 'successful publication must rebuild indexes without warnings');
    assertTrue(is_file($cacheDir . '/index/pages.json'), 'metadata index must be rebuilt');
    assertTrue(is_file($cacheDir . '/index/image-references.json'), 'image reference index must be rebuilt');

    echo "post_publisher_check: OK\n";
} finally {
    removeTree($root);
}

function assertTrue(bool $actual, string $message): void
{
    if (!$actual) throw new RuntimeException($message);
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException($message);
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) throw new RuntimeException($message);
}

function assertError(Tomos\PostPublishResult $result, string $expected, string $label): void
{
    if ($result->ok || !in_array($expected, $result->errors, true)) {
        throw new RuntimeException($label . ' must return the expected error');
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($child) ? removeTree($child) : unlink($child);
    }
    rmdir($path);
}
