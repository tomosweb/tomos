<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdateLock.php';
require_once dirname(__DIR__) . '/core/UpdaterSelfUpdate.php';

use Tomos\UpdaterSelfUpdate;
use Tomos\UpdaterSelfUpdateException;

$sourceRoot = dirname(__DIR__);
$guard = "Order allow,deny\nDeny from all\nRequire all denied\n";
$passes = 0;
$roots = [];

function checkMigration(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

function removeMigrationTree(string $path): void
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
            removeMigrationTree($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
}

function makeMigrationRoot(string $sourceRoot, bool $withGuards = false, string $legacy = 'absent'): string
{
    global $guard;
    $root = sys_get_temp_dir() . '/tomos-v090-migration-' . bin2hex(random_bytes(8));
    foreach ([
        'core/updater-pending',
        'core/webauthn/vendor/lbuchs/webauthn',
        'cache',
        'storage/update-tmp',
        'storage/update-backups',
        'storage/update-logs',
        'trash',
    ] as $directory) {
        if (!mkdir($root . '/' . $directory, 0700, true) && !is_dir($root . '/' . $directory)) {
            throw new RuntimeException('could not create migration fixture directory');
        }
    }
    file_put_contents($root . '/VERSION', "0.9.0\n", LOCK_EX);
    if ($withGuards) {
        $oldGuard = "Deny from all\nOrder allow,deny\nRequire all denied\n";
        foreach (['cache', 'storage', 'trash'] as $directory) {
            file_put_contents($root . '/' . $directory . '/.htaccess', $oldGuard, LOCK_EX);
        }
    }
    $legacyPath = $root . '/core/webauthn/vendor/lbuchs/webauthn/_test';
    if ($legacy === 'dir') {
        mkdir($legacyPath, 0700, true);
        file_put_contents($legacyPath . '/server.php', "<?php echo 'legacy';\n", LOCK_EX);
    } elseif ($legacy === 'file') {
        file_put_contents($legacyPath, 'unexpected', LOCK_EX);
    } elseif ($legacy === 'symlink') {
        $outside = $root . '/outside-test';
        mkdir($outside, 0700);
        file_put_contents($outside . '/server.php', 'outside', LOCK_EX);
        symlink($outside, $legacyPath);
    }
    return $root;
}

function putMigrationPending(string $root, string $guard): void
{
    $pending = $root . '/core/updater-pending';
    foreach (['cache', 'storage', 'trash'] as $directory) {
        $name = $directory . '-htaccess';
        $payload = $pending . '/' . $name;
        file_put_contents($payload, $guard, LOCK_EX);
        file_put_contents($pending . '/' . $name . '.meta.json', json_encode([
            'target' => $directory . '/.htaccess',
            'sha256' => hash_file('sha256', $payload),
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}

function runMigration(string $root, ?callable $hook = null): array
{
    return (new UpdaterSelfUpdate($root, $hook))->apply();
}

function expectMigrationFailure(string $root, ?callable $hook = null): UpdaterSelfUpdateException
{
    try {
        runMigration($root, $hook);
    } catch (UpdaterSelfUpdateException $exception) {
        checkMigration($exception->stage() !== '', 'migration failure exposes a stage');
        return $exception;
    }
    throw new RuntimeException('unsafe or injected migration was accepted');
}

function pendingExists(string $root): bool
{
    return is_dir($root . '/core/updater-pending');
}

try {
    $root = makeMigrationRoot($sourceRoot, false, 'dir');
    $roots[] = $root;
    putMigrationPending($root, $guard);
    $result = runMigration($root);
    checkMigration(!empty($result['ok']) && !empty($result['applied']), 'v0.9.0 migration succeeds');
    checkMigration(!is_dir($root . '/core/webauthn/vendor/lbuchs/webauthn/_test'), 'legacy _test directory is absent after success');
    checkMigration(!is_dir($root . '/core/updater-pending'), 'migration pending pairs are cleaned up');
    checkMigration(is_file($root . '/cache/.htaccess') && is_file($root . '/storage/.htaccess') && is_file($root . '/trash/.htaccess'), 'three protected guards are installed');
    $backups = glob($root . '/storage/update-backups/updater-*/files/core/webauthn/vendor/lbuchs/webauthn/_test/server.php');
    checkMigration(is_array($backups) && count($backups) === 1, 'legacy _test is retained in the update backup');

    $root = makeMigrationRoot($sourceRoot, false, 'absent');
    $roots[] = $root;
    putMigrationPending($root, $guard);
    $result = runMigration($root);
    checkMigration(!empty($result['ok']) && empty($result['legacy_cleanup']['moved']), 'absent legacy _test is idempotent PASS');

    $root = makeMigrationRoot($sourceRoot, false, 'dir');
    $roots[] = $root;
    putMigrationPending($root, $guard);
    expectMigrationFailure($root, static function (string $target): void {
        if ($target === 'cache/.htaccess') {
            throw new RuntimeException('failure after legacy move');
        }
    });
    checkMigration(is_file($root . '/core/webauthn/vendor/lbuchs/webauthn/_test/server.php'), 'failure after legacy move restores _test');
    checkMigration(!is_file($root . '/cache/.htaccess'), 'failure before guard replacement leaves new guard absent');
    checkMigration(pendingExists($root), 'failure keeps pending pairs for diagnosis');

    $root = makeMigrationRoot($sourceRoot, true, 'absent');
    $roots[] = $root;
    $before = [];
    foreach (['cache', 'storage', 'trash'] as $directory) {
        $before[$directory] = hash_file('sha256', $root . '/' . $directory . '/.htaccess');
    }
    putMigrationPending($root, $guard);
    expectMigrationFailure($root, static function (string $target): void {
        if ($target === 'storage/.htaccess') {
            throw new RuntimeException('failure after existing guard replacement');
        }
    });
    foreach ($before as $directory => $hash) {
        checkMigration(hash_file('sha256', $root . '/' . $directory . '/.htaccess') === $hash, 'existing guard rollback restores ' . $directory);
    }

    $root = makeMigrationRoot($sourceRoot, false, 'absent');
    $roots[] = $root;
    putMigrationPending($root, $guard);
    expectMigrationFailure($root, static function (string $target): void {
        if ($target === 'storage/.htaccess') {
            throw new RuntimeException('failure after new guard replacement');
        }
    });
    foreach (['cache', 'storage', 'trash'] as $directory) {
        checkMigration(!file_exists($root . '/' . $directory . '/.htaccess'), 'new guard rollback removes ' . $directory);
    }

    foreach (['symlink', 'file'] as $legacyType) {
        $root = makeMigrationRoot($sourceRoot, false, $legacyType);
        $roots[] = $root;
        putMigrationPending($root, $guard);
        expectMigrationFailure($root);
        checkMigration($legacyType === 'symlink' ? is_link($root . '/core/webauthn/vendor/lbuchs/webauthn/_test') : is_file($root . '/core/webauthn/vendor/lbuchs/webauthn/_test'), $legacyType . ' _test target remains untouched');
        checkMigration(!is_file($root . '/cache/.htaccess'), $legacyType . ' failure does not install guards');
    }
} finally {
    foreach ($roots as $root) {
        removeMigrationTree($root);
    }
}

echo "update_v090_migration_check: {$passes} checks passed\n";
