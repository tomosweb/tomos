<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdateLock.php';
require_once dirname(__DIR__) . '/core/UpdaterSelfUpdate.php';

use Tomos\UpdaterSelfUpdate;

$root = sys_get_temp_dir() . '/tomos-missing-runtime-dir-' . bin2hex(random_bytes(8));

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
    foreach (['core/updater-pending', 'storage/update-tmp', 'storage/update-backups', 'storage/update-logs'] as $directory) {
        if (!mkdir($root . '/' . $directory, 0700, true) && !is_dir($root . '/' . $directory)) {
            throw new RuntimeException('fixture directory');
        }
    }
    $old = "<?php\nreturn 'old';\n";
    $new = "<?php\nreturn 'new';\n";
    file_put_contents($root . '/core/UpdateService.php', $old, LOCK_EX);
    file_put_contents($root . '/core/updater-pending/update-service.php', $new, LOCK_EX);
    file_put_contents($root . '/core/updater-pending/update-service.json', json_encode([
        'target' => 'core/UpdateService.php',
        'sha256' => hash('sha256', $new),
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    $rules = "{\"schema_version\":1}\n";
    file_put_contents($root . '/core/updater-pending/theme-rules.json', $rules, LOCK_EX);
    file_put_contents($root . '/core/updater-pending/theme-rules.meta.json', json_encode([
        'target' => 'docs/theme/theme-rules.json',
        'sha256' => hash('sha256', $rules),
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    $result = (new UpdaterSelfUpdate($root))->apply();
    if (empty($result['ok'])
        || !is_file($root . '/docs/theme/theme-rules.json')
        || file_get_contents($root . '/docs/theme/theme-rules.json') !== $rules
    ) {
        throw new RuntimeException('Updater did not create the missing runtime directory');
    }
    echo "update_missing_runtime_directory_check: PASS\n";
} finally {
    removeTree($root);
}
