<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdateLock.php';
require_once dirname(__DIR__) . '/core/UpdaterSelfUpdate.php';

use Tomos\UpdaterSelfUpdate;
use Tomos\UpdaterSelfUpdateException;

$passes = 0;

function check(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

function removeTree(string $path): void
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
            removeTree($path . '/' . $item);
        }
    }
    @rmdir($path);
}

function makeRoot(): string
{
    $root = sys_get_temp_dir() . '/tomos-updater-bundle-' . bin2hex(random_bytes(8));
    foreach (['update', 'core/updater-pending', 'storage/update-tmp', 'storage/update-backups', 'storage/update-logs'] as $dir) {
        if (!mkdir($root . '/' . $dir, 0700, true) && !is_dir($root . '/' . $dir)) {
            throw new RuntimeException('fixture directory');
        }
    }
    file_put_contents($root . '/update/index.php', "<?php\nreturn 'old-index';\n", LOCK_EX);
    file_put_contents($root . '/core/UpdateService.php', "<?php\nreturn 'old-service';\n", LOCK_EX);
    chmod($root . '/update/index.php', 0640);
    chmod($root . '/core/UpdateService.php', 0600);
    return $root;
}

function putPending(string $root, ?string $index, ?string $service, array $options = []): void
{
    $dir = $root . '/core/updater-pending';
    removeTree($dir);
    mkdir($dir, 0700, true);
    $entries = [
        'update/index.php' => [$index, 'update-index.php', 'update-index.json'],
        'core/UpdateService.php' => [$service, 'update-service.php', 'update-service.json'],
    ];
    foreach ($entries as $target => [$contents, $file, $metadata]) {
        if ($contents === null) {
            continue;
        }
        $filePath = $dir . '/' . $file;
        if (($options['symlink_target'] ?? '') === $target) {
            $outside = $root . '/outside-' . basename($file);
            file_put_contents($outside, $contents, LOCK_EX);
            symlink($outside, $filePath);
            $hash = hash_file('sha256', $outside);
        } else {
            file_put_contents($filePath, $contents, LOCK_EX);
            $hash = hash_file('sha256', $filePath);
        }
        if (($options['bad_hash_target'] ?? '') === $target) {
            $hash = str_repeat('0', 64);
        }
        $metadataTarget = ($options['wrong_metadata_target'] ?? '') === $target
            ? 'core/UpdateException.php'
            : $target;
        $json = json_encode(['target' => $metadataTarget, 'sha256' => $hash], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($dir . '/' . $metadata, $json, LOCK_EX);
    }
    if (!empty($options['unknown'])) {
        file_put_contents($dir . '/unknown.txt', 'unexpected', LOCK_EX);
    }
    if (!empty($options['incomplete'])) {
        @unlink($dir . '/update-service.json');
    }
}

function expectFailure(string $root, string $message): UpdaterSelfUpdateException
{
    try {
        (new UpdaterSelfUpdate($root))->apply();
    } catch (UpdaterSelfUpdateException $exception) {
        check($exception->stage() !== '', $message . ' exposes stage');
        return $exception;
    }
    throw new RuntimeException($message . ' was accepted');
}

function hashes(string $root): array
{
    return [
        'index' => hash_file('sha256', $root . '/update/index.php'),
        'service' => hash_file('sha256', $root . '/core/UpdateService.php'),
    ];
}

$roots = [];
try {
    // Single target pending remains supported; a pair is required per target.
    $root = makeRoot(); $roots[] = $root;
    putPending($root, "<?php\nreturn 'new-index';\n", null);
    $result = (new UpdaterSelfUpdate($root))->apply();
    check($result['ok'] === true && $result['applied'] === true, 'index-only pending applies');
    check(isset($result['targets']['update/index.php']), 'index-only target result exists');
    check(!is_dir($root . '/core/updater-pending'), 'index-only pending cleanup succeeds');

    $root = makeRoot(); $roots[] = $root;
    putPending($root, null, "<?php\nreturn 'new-service';\n");
    $result = (new UpdaterSelfUpdate($root))->apply();
    check($result['applied'] === true && isset($result['targets']['core/UpdateService.php']), 'service-only pending applies');

    $root = makeRoot(); $roots[] = $root;
    putPending($root, "<?php\nreturn 'new-index';\n", "<?php\nreturn 'new-service';\n");
    $old = hashes($root);
    $result = (new UpdaterSelfUpdate($root))->apply();
    check($result['applied'] === true && count($result['targets']) === 2, 'two-target bundle applies atomically');
    check(hashes($root)['index'] !== $old['index'] && hashes($root)['service'] !== $old['service'], 'both targets changed');
    $backupDirs = glob($root . '/storage/update-backups/updater-*', GLOB_ONLYDIR);
    check(is_array($backupDirs) && count($backupDirs) === 1, 'bundle backup directory created');
    check(is_file($backupDirs[0] . '/files/update/index.php') && is_file($backupDirs[0] . '/files/core/UpdateService.php'), 'both targets backed up');
    check(($result['targets']['update/index.php']['previous_permissions'] ?? '') === '0640', 'index permissions recorded');
    check(($result['targets']['core/UpdateService.php']['previous_permissions'] ?? '') === '0600', 'service permissions recorded');
    check((fileperms($root . '/update/index.php') & 0777) === 0640, 'index permissions preserved');
    check((fileperms($root . '/core/UpdateService.php') & 0777) === 0600, 'service permissions preserved');

    $root = makeRoot(); $roots[] = $root;
    putPending($root, file_get_contents($root . '/update/index.php'), "<?php\nreturn 'new-service';\n");
    $result = (new UpdaterSelfUpdate($root))->apply();
    check($result['targets']['update/index.php']['no_change'] === true, 'unchanged target is no_change');
    check($result['targets']['core/UpdateService.php']['applied'] === true, 'changed target applies beside no_change');

    foreach ([
        'unknown pending file' => ['index' => "<?php\nreturn 'new-index';\n", 'service' => null, 'options' => ['unknown' => true]],
        'incomplete pair' => ['index' => "<?php\nreturn 'new-index';\n", 'service' => "<?php\nreturn 'new-service';\n", 'options' => ['incomplete' => true]],
        'pending symlink' => ['index' => "<?php\nreturn 'new-index';\n", 'service' => null, 'options' => ['symlink_target' => 'update/index.php']],
        'hash mismatch' => ['index' => "<?php\nreturn 'new-index';\n", 'service' => null, 'options' => ['bad_hash_target' => 'update/index.php']],
        'unknown metadata target' => ['index' => "<?php\nreturn 'new-index';\n", 'service' => null, 'options' => ['wrong_metadata_target' => 'update/index.php']],
        'invalid PHP' => ['index' => "<?php\nthis is invalid\n", 'service' => null, 'options' => []],
    ] as $label => $fixture) {
        $root = makeRoot(); $roots[] = $root;
        putPending($root, $fixture['index'], $fixture['service'], $fixture['options']);
        expectFailure($root, $label);
        check(hash_file('sha256', $root . '/update/index.php') === hash_file('sha256', $root . '/update/index.php'), $label . ' leaves target readable');
        check(is_dir($root . '/core/updater-pending'), $label . ' keeps pending data for diagnosis');
    }

    $root = makeRoot(); $roots[] = $root;
    putPending($root, "<?php\nreturn 'new-index';\n", "<?php\nreturn 'new-service';\n");
    $old = hashes($root);
    $failure = false;
    try {
        (new UpdaterSelfUpdate($root, static function (string $target): void {
            if ($target === 'core/UpdateService.php') {
                throw new RuntimeException('fixture replacement failure');
            }
        }))->apply();
    } catch (UpdaterSelfUpdateException $exception) {
        $failure = true;
        check($exception->rollbackFailed() === false, 'bundle rollback reports success');
    }
    check($failure, 'failure after first replacement is rejected');
    check(hashes($root) === $old, 'rollback restores both old hashes');
    check(is_dir($root . '/core/updater-pending'), 'rollback keeps pending bundle');

    $root = makeRoot(); $roots[] = $root;
    putPending($root, "<?php\nreturn 'new-index';\n", null);
    $lockPath = $root . '/storage/update-tmp/updater-self-update.lock';
    $lock = fopen($lockPath, 'c');
    flock($lock, LOCK_EX | LOCK_NB);
    expectFailure($root, 'operation lock conflict');
    flock($lock, LOCK_UN); fclose($lock);

    $root = makeRoot(); $roots[] = $root;
    putPending($root, "<?php\nreturn 'new-index';\n", null);
    file_put_contents($root . '/storage/update.lock', '{}', LOCK_EX);
    expectFailure($root, 'UpdateLock conflict');

    $root = makeRoot(); $roots[] = $root;
    putPending($root, "<?php\nreturn 'new-index';\n", null);
    removeTree($root . '/update');
    mkdir($root . '/outside-update', 0700);
    symlink($root . '/outside-update', $root . '/update');
    expectFailure($root, 'target path safety');

    $root = makeRoot(); $roots[] = $root;
    putPending($root, "<?php\nreturn 'new-index';\n", null);
    removeTree($root . '/storage/update-logs');
    file_put_contents($root . '/storage/update-logs', 'not a directory', LOCK_EX);
    $result = (new UpdaterSelfUpdate($root))->apply();
    check($result['recording_ok'] === false && $result['cleanup_ok'] === false, 'recording failure keeps pending data');
    check(is_dir($root . '/core/updater-pending'), 'recording failure does not cleanup pending');
} finally {
    foreach ($roots as $root) {
        removeTree($root);
    }
}

echo "update_self_update_bundle_check: {$passes} checks passed\n";
