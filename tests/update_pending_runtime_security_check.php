<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/InstalledIntegrityVerifier.php';

use Tomos\InstalledIntegrityVerifier;
use Tomos\UpdateException;

$cases = [
    'corrupt_payload' => static function (string $root, string $payload, string $hash): void {
        file_put_contents($root . '/core/updater-pending/theme-rules.json', "{broken\n", LOCK_EX);
        writePendingMetadata($root, $hash);
    },
    'pending_symlink' => static function (string $root, string $payload, string $hash): void {
        $outside = $root . '/outside.json';
        file_put_contents($outside, $payload, LOCK_EX);
        if (!symlink($outside, $root . '/core/updater-pending/theme-rules.json')) {
            throw new RuntimeException('could not create pending symlink');
        }
        writePendingMetadata($root, $hash);
    },
    'target_conflict' => static function (string $root, string $payload, string $hash): void {
        file_put_contents($root . '/core/updater-pending/theme-rules.json', $payload, LOCK_EX);
        writePendingMetadata($root, $hash);
        file_put_contents($root . '/docs/theme/theme-rules.json', "{\"conflict\":true}\n", LOCK_EX);
    },
    'target_symlink' => static function (string $root, string $payload, string $hash): void {
        file_put_contents($root . '/core/updater-pending/theme-rules.json', $payload, LOCK_EX);
        writePendingMetadata($root, $hash);
        $outside = $root . '/outside-target.json';
        file_put_contents($outside, "{\"outside\":true}\n", LOCK_EX);
        if (!symlink($outside, $root . '/docs/theme/theme-rules.json')) {
            throw new RuntimeException('could not create target symlink');
        }
    },
];

foreach ($cases as $name => $prepare) {
    $root = sys_get_temp_dir() . '/tomos-pending-runtime-security-' . bin2hex(random_bytes(6));
    foreach (['core/updater-pending', 'docs/theme', 'storage/update-backups/20260901-120000-deadbeef', 'storage/update-logs'] as $directory) {
        if (!mkdir($root . '/' . $directory, 0700, true)) {
            throw new RuntimeException('could not create ' . $directory);
        }
    }
    try {
        $payload = "{\n    \"schema\": 1\n}\n";
        $hash = hash('sha256', $payload);
        file_put_contents($root . '/VERSION', "0.6.2\n", LOCK_EX);
        file_put_contents($root . '/core/required-installed-files.txt', "VERSION\ncore/required-installed-files.txt\ndocs/theme/theme-rules.json\n", LOCK_EX);
        file_put_contents($root . '/storage/update-backups/20260901-120000-deadbeef/update-meta.json', json_encode([
            'files' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $prepare($root, $payload, $hash);

        $caught = null;
        try {
            (new InstalledIntegrityVerifier($root))->verifyAfterUpdate([
                'backup_id' => '20260901-120000-deadbeef',
            ]);
        } catch (UpdateException $exception) {
            $caught = $exception;
        }
        if (!$caught instanceof UpdateException || $caught->stage() !== 'verify_required_files') {
            throw new RuntimeException($name . ' was not rejected fail-closed');
        }
    } finally {
        removeSecurityTree($root);
    }
}

echo "update_pending_runtime_security_check: corrupt payload, symlink, and path conflict fail-closed checks passed\n";

function writePendingMetadata(string $root, string $hash): void
{
    file_put_contents($root . '/core/updater-pending/theme-rules.meta.json', json_encode([
        'target' => 'docs/theme/theme-rules.json',
        'sha256' => $hash,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function removeSecurityTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeSecurityTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
