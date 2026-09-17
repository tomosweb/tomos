<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdateService.php';
require_once dirname(__DIR__) . '/core/InstalledIntegrityVerifier.php';

use Tomos\InstalledIntegrityVerifier;
use Tomos\UpdateException;

$root = sys_get_temp_dir() . '/tomos-pending-runtime-rollback-' . bin2hex(random_bytes(6));
foreach (['core/updater-pending', 'docs/theme', 'storage/update-backups/20260901-120000-deadbeef', 'storage/update-logs'] as $directory) {
    if (!mkdir($root . '/' . $directory, 0700, true)) {
        throw new RuntimeException('could not create ' . $directory);
    }
}

try {
    file_put_contents($root . '/VERSION', "0.6.2\n", LOCK_EX);
    file_put_contents($root . '/core/required-installed-files.txt', "VERSION\ncore/required-installed-files.txt\ndocs/theme/theme-rules.json\ncore/missing.php\n", LOCK_EX);
    $payload = "{\n    \"schema\": 1\n}\n";
    file_put_contents($root . '/core/updater-pending/theme-rules.json', $payload, LOCK_EX);
    file_put_contents($root . '/core/updater-pending/theme-rules.meta.json', json_encode([
        'target' => 'docs/theme/theme-rules.json',
        'sha256' => hash('sha256', $payload),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    file_put_contents($root . '/storage/update-backups/20260901-120000-deadbeef/update-meta.json', json_encode([
        'files' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

    $caught = null;
    try {
        (new InstalledIntegrityVerifier($root))->verifyAfterUpdate([
            'backup_id' => '20260901-120000-deadbeef',
        ]);
    } catch (UpdateException $exception) {
        $caught = $exception;
    }
    if (!$caught instanceof UpdateException || $caught->stage() !== 'verify_required_files') {
        throw new RuntimeException('missing required file did not fail verification');
    }
    if (is_file($root . '/docs/theme/theme-rules.json')) {
        throw new RuntimeException('materialized pending runtime file was not removed by rollback');
    }

    echo "update_pending_runtime_rollback_check: FAIL -> rollback PASS\n";
} finally {
    removePendingRuntimeTree($root);
}

function removePendingRuntimeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removePendingRuntimeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
