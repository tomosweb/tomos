<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdateLock.php';
require_once dirname(__DIR__) . '/core/UpdaterSelfUpdate.php';

use Tomos\UpdaterSelfUpdate;

$root = sys_get_temp_dir() . '/tomos-updater-no-proc-open-' . bin2hex(random_bytes(8));

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

try {
    foreach (['update', 'core/updater-pending', 'storage/update-tmp', 'storage/update-backups', 'storage/update-logs'] as $directory) {
        if (!mkdir($root . '/' . $directory, 0700, true) && !is_dir($root . '/' . $directory)) {
            throw new RuntimeException('fixture directory');
        }
    }

    $old = "<?php\nreturn 'old';\n";
    $new = "<?php\nreturn 'new';\n";
    file_put_contents($root . '/update/index.php', $old, LOCK_EX);
    file_put_contents($root . '/core/updater-pending/update-index.php', $new, LOCK_EX);
    file_put_contents($root . '/core/updater-pending/update-index.json', json_encode([
        'target' => 'update/index.php',
        'sha256' => hash('sha256', $new),
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);

    if (function_exists('proc_open')) {
        throw new RuntimeException('run this check with proc_open disabled');
    }
    $result = (new UpdaterSelfUpdate($root))->apply();
    if (empty($result['ok']) || file_get_contents($root . '/update/index.php') !== $new) {
        throw new RuntimeException('Updater self-update failed without proc_open');
    }
    echo "update_self_update_no_proc_open_check: PASS\n";
} finally {
    removeTree($root);
}
