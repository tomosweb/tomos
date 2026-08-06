<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostSubmissionGuard.php';

use Tomos\PostSubmissionGuard;

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-submission-test-' . bin2hex(random_bytes(6));
$config = ['paths' => ['cache_dir' => $tmp . DIRECTORY_SEPARATOR . 'cache']];
$now = 1785900000;

try {
    $idA = PostSubmissionGuard::issueId();
    $idB = PostSubmissionGuard::issueId();
    assertTrue($idA !== $idB, 'submission IDs must be unique');

    $first = new PostSubmissionGuard($config, $tmp, $now);
    assertTrue($first->acquire($idA)->allowed, 'first submission must be allowed');
    $parallel = new PostSubmissionGuard($config, $tmp, $now);
    assertSame(PostSubmissionGuard::PROCESSING_MESSAGE, $parallel->acquire($idA)->message, 'parallel submission must report processing');
    assertTrue($first->markCompleted(), 'completed submission must be recorded');
    $first->release();

    $duplicate = new PostSubmissionGuard($config, $tmp, $now + 1);
    assertSame(PostSubmissionGuard::DUPLICATE_MESSAGE, $duplicate->acquire($idA)->message, 'completed submission must be rejected while its record exists');

    $different = new PostSubmissionGuard($config, $tmp, $now + 1);
    assertTrue($different->acquire($idB)->allowed, 'a new post with a different submission ID must be allowed immediately');
    $different->release();

    $failedId = PostSubmissionGuard::issueId();
    $failed = new PostSubmissionGuard($config, $tmp, $now + 2);
    assertTrue($failed->acquire($failedId)->allowed, 'failed attempt must start');
    $failed->release();
    $retry = new PostSubmissionGuard($config, $tmp, $now + 3);
    assertTrue($retry->acquire($failedId)->allowed, 'failed attempt must be retryable with the same ID');
    $retry->release();

    $afterTenMinutes = new PostSubmissionGuard($config, $tmp, $now + 601);
    assertSame(PostSubmissionGuard::DUPLICATE_MESSAGE, $afterTenMinutes->acquire($idA)->message, 'same ID must still be rejected after 10 minutes and within 24 hours');

    $beforeRetentionEnds = new PostSubmissionGuard($config, $tmp, $now + 86399);
    assertSame(PostSubmissionGuard::DUPLICATE_MESSAGE, $beforeRetentionEnds->acquire($idA)->message, 'same ID must be rejected throughout the 24 hour retention period');

    $records = glob($first->storageDirectory() . DIRECTORY_SEPARATOR . '*.json') ?: [];
    assertSame(1, count($records), 'one completed record must be retained');
    assertTrue(strpos(basename($records[0]), $idA) === false, 'raw submission ID must not be used as the file name');
    $record = json_decode((string) file_get_contents($records[0]), true);
    assertSame(['completed_at', 'expires_at'], array_keys($record), 'completed record must contain only timestamps');
    assertSame($now + 86400, $record['expires_at'], 'completed record must expire after 24 hours');
    $completedLock = $first->storageDirectory() . DIRECTORY_SEPARATOR . hash('sha256', $idA) . '.lock';
    assertTrue(is_file($completedLock), 'completed submission lock must be retained with its record');

    $cleanup = new PostSubmissionGuard($config, $tmp, $now + 86401);
    $cleanup->cleanupExpired();
    assertSame([], glob($cleanup->storageDirectory() . DIRECTORY_SEPARATOR . '*.json') ?: [], 'completed records must be removed after about 24 hours');
    assertTrue(!is_file($completedLock), 'completed submission lock must be removed with its expired record');
    assertTrue($cleanup->acquire($idA)->allowed, 'same ID may be accepted again only after the 24 hour record has been cleaned up');
    $cleanup->release();

    echo "post_submission_guard_check: OK\n";
} finally {
    removeTree($tmp);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException($message);
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
