<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostUploadCapabilities.php';
require_once dirname(__DIR__) . '/core/PostImageUploadSessionStore.php';

use Tomos\PostImageUploadSessionStore;
use Tomos\PostSubmissionGuard;

require_once dirname(__DIR__) . '/core/PostSubmissionGuard.php';

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-image-session-test-' . bin2hex(random_bytes(6));
$submissionId = PostSubmissionGuard::issueId();

try {
    $store = new PostImageUploadSessionStore($tmp . DIRECTORY_SEPARATOR . 'cache');
    $record = $store->create('session-a', [], $submissionId);
    if (!is_array($record)) throw new RuntimeException('image upload session must be created');
    $files = glob($tmp . DIRECTORY_SEPARATOR . 'cache/post-upload-sessions/*.json') ?: [];
    $json = (string) file_get_contents($files[0]);
    if (strpos($json, $submissionId) !== false) throw new RuntimeException('raw submission ID must not be stored in image session');
    if ($store->readyImages((string) $record['id'], 'session-a', $submissionId) !== []) throw new RuntimeException('matching submission ID must own the image session');
    if ($store->readyImages((string) $record['id'], 'session-a', PostSubmissionGuard::issueId()) !== null) throw new RuntimeException('different submission ID must not own the image session');
    if (!$store->deleteOwned((string) $record['id'], 'session-a', $submissionId)) throw new RuntimeException('matching submission ID must delete the image session');
    echo "post_image_submission_check: OK\n";
} finally {
    removeTree($tmp);
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
