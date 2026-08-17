<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdateLock.php';
require_once dirname(__DIR__) . '/core/UpdaterSelfUpdate.php';

use Tomos\UpdaterSelfUpdate;
use Tomos\UpdaterSelfUpdateException;

$root = sys_get_temp_dir() . '/tomos-update-lock-self-' . bin2hex(random_bytes(8));
$passes = 0;

function checkLock(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

function removeLockTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            removeLockTree($path . '/' . $item);
        }
    }
    @rmdir($path);
}

function writePendingLockTarget(string $root, string $target, string $pendingFile, string $metadataFile, string $contents): void
{
    $dir = $root . '/core/updater-pending';
    $source = $dir . '/' . $pendingFile;
    file_put_contents($source, $contents, LOCK_EX);
    $metadata = json_encode([
        'target' => $target,
        'sha256' => hash_file('sha256', $source),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($dir . '/' . $metadataFile, $metadata, LOCK_EX);
}

try {
    foreach (['update', 'core/updater-pending', 'storage/update-tmp', 'storage/update-backups', 'storage/update-logs'] as $dir) {
        if (!mkdir($root . '/' . $dir, 0700, true) && !is_dir($root . '/' . $dir)) {
            throw new RuntimeException('fixture directory');
        }
    }

    file_put_contents($root . '/update/index.php', "<?php\nreturn 'old-index';\n", LOCK_EX);
    file_put_contents($root . '/core/UpdateService.php', "<?php\nreturn 'old-service';\n", LOCK_EX);
    file_put_contents($root . '/core/UpdateLock.php', "<?php\nreturn 'old-lock';\n", LOCK_EX);
    chmod($root . '/update/index.php', 0640);
    chmod($root . '/core/UpdateService.php', 0600);
    chmod($root . '/core/UpdateLock.php', 0644);

    writePendingLockTarget($root, 'core/UpdateLock.php', 'update-lock.php', 'update-lock.json', "<?php\nreturn 'new-lock';\n");
    $result = (new UpdaterSelfUpdate($root))->apply();
    checkLock($result['ok'] === true && $result['applied'] === true, 'UpdateLock-only pending applies');
    checkLock(isset($result['targets']['core/UpdateLock.php']), 'UpdateLock target is reported');
    checkLock((fileperms($root . '/core/UpdateLock.php') & 0777) === 0644, 'UpdateLock permissions are preserved');
    checkLock(strpos((string) file_get_contents($root . '/core/UpdateLock.php'), 'new-lock') !== false, 'UpdateLock contents are replaced');
    checkLock(!is_dir($root . '/core/updater-pending'), 'UpdateLock pending pair is cleaned up');

    mkdir($root . '/core/updater-pending', 0700, true);
    writePendingLockTarget($root, 'update/index.php', 'update-index.php', 'update-index.json', "<?php\nreturn 'new-index';\n");
    writePendingLockTarget($root, 'core/UpdateService.php', 'update-service.php', 'update-service.json', "<?php\nreturn 'new-service';\n");
    writePendingLockTarget($root, 'core/UpdateLock.php', 'update-lock.php', 'update-lock.json', "<?php\nreturn 'newer-lock';\n");

    $before = [
        'index' => hash_file('sha256', $root . '/update/index.php'),
        'service' => hash_file('sha256', $root . '/core/UpdateService.php'),
        'lock' => hash_file('sha256', $root . '/core/UpdateLock.php'),
    ];
    $failed = false;
    try {
        (new UpdaterSelfUpdate($root, static function (string $target): void {
            if ($target === 'core/UpdateLock.php') {
                throw new RuntimeException('fixture failure before third replacement');
            }
        }))->apply();
    } catch (UpdaterSelfUpdateException $exception) {
        $failed = true;
        checkLock($exception->rollbackFailed() === false, 'three-target rollback succeeds');
    }
    checkLock($failed, 'failure before UpdateLock replacement is rejected');
    $after = [
        'index' => hash_file('sha256', $root . '/update/index.php'),
        'service' => hash_file('sha256', $root . '/core/UpdateService.php'),
        'lock' => hash_file('sha256', $root . '/core/UpdateLock.php'),
    ];
    checkLock($after === $before, 'three-target rollback restores all updater components');
    checkLock(is_dir($root . '/core/updater-pending'), 'failed three-target bundle keeps pending data');
} finally {
    removeLockTree($root);
}

echo "update_lock_self_update_check: {$passes} checks passed\n";
