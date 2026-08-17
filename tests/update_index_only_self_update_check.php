<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdateLock.php';
require_once dirname(__DIR__) . '/core/UpdaterSelfUpdate.php';

use Tomos\UpdaterSelfUpdate;
use Tomos\UpdaterSelfUpdateException;

$root = sys_get_temp_dir() . '/tomos-updater-index-only-' . bin2hex(random_bytes(8));

function removeIndexOnlyTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') removeIndexOnlyTree($path . '/' . $item);
    }
    @rmdir($path);
}

try {
    foreach (['update', 'core/updater-pending', 'storage/update-tmp', 'storage/update-backups', 'storage/update-logs'] as $dir) {
        mkdir($root . '/' . $dir, 0700, true);
    }
    file_put_contents($root . '/update/index.php', "<?php\nreturn 'old-index';\n", LOCK_EX);
    file_put_contents($root . '/core/UpdateService.php', "<?php\nreturn 'old-service';\n", LOCK_EX);
    chmod($root . '/update/index.php', 0640);
    chmod($root . '/core/UpdateService.php', 0600);

    $pending = $root . '/core/updater-pending/update-index.php';
    file_put_contents($pending, "<?php\nreturn 'new-index';\n", LOCK_EX);
    file_put_contents(
        $root . '/core/updater-pending/update-index.json',
        json_encode(['target' => 'update/index.php', 'sha256' => hash_file('sha256', $pending)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    try {
        $result = (new UpdaterSelfUpdate($root))->apply();
    } catch (UpdaterSelfUpdateException $exception) {
        throw new RuntimeException('index-only self-update failed at stage=' . $exception->stage() . ': ' . $exception->getMessage());
    }

    if (empty($result['ok']) || empty($result['applied']) || !isset($result['targets']['update/index.php'])) {
        throw new RuntimeException('index-only self-update returned an invalid result');
    }
    echo "update_index_only_self_update_check: OK\n";
} finally {
    removeIndexOnlyTree($root);
}
