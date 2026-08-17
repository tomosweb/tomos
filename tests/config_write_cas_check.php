<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/ConfigWriteLock.php';
require_once dirname(__DIR__) . '/core/ConfigWriter.php';
require_once dirname(__DIR__) . '/core/Ga4.php';
require_once dirname(__DIR__) . '/core/AnalyticsConfigWriter.php';
require_once dirname(__DIR__) . '/core/PostPasswordHashUpdater.php';

use Tomos\AnalyticsConfigWriter;
use Tomos\ConfigWriteLock;
use Tomos\ConfigWriter;
use Tomos\PostPasswordHashUpdater;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-config-cas-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
mkdir($root . '/storage', 0700, true);
$configPath = $root . '/config.php';

$initial = [
    'site' => ['name' => 'CAS test'],
    'paths' => [
        'content_dir' => $root . '/content',
        'cache_dir' => $root . '/cache',
        'theme_dir' => $root . '/themes',
    ],
    'analytics' => ['ga4_measurement_id' => ''],
    'security' => [
        'post_password_hash' => 'old-password-hash',
        'inbox_api_token_hash' => 'keep-token',
    ],
];

try {
    assertSame(true, ConfigWriter::write($configPath, $initial, $root), 'initial config write must succeed');
    $baseline = require $configPath;

    [$analyticsUpdate, $errors] = AnalyticsConfigWriter::update($baseline, 'G-ABCDEF1234');
    assertSame([], $errors, 'analytics update fixture must validate');

    (new PostPasswordHashUpdater($root))->update('new-password-hash');
    assertSame(false, ConfigWriter::write($configPath, $analyticsUpdate, $root), 'stale partial config update must be rejected');

    $afterConflict = require $configPath;
    assertSame('new-password-hash', $afterConflict['security']['post_password_hash'] ?? null, 'newer password update must survive stale write attempt');
    assertSame('', $afterConflict['analytics']['ga4_measurement_id'] ?? null, 'rejected stale analytics update must not be partially written');
    assertSame('keep-token', $afterConflict['security']['inbox_api_token_hash'] ?? null, 'unrelated security data must remain intact');

    [$retryUpdate, $retryErrors] = AnalyticsConfigWriter::update($afterConflict, 'G-ABCDEF1234');
    assertSame([], $retryErrors, 'retry against latest config must validate');
    assertSame(true, ConfigWriter::write($configPath, $retryUpdate, $root), 'retry against latest config must succeed');

    $final = require $configPath;
    assertSame('new-password-hash', $final['security']['post_password_hash'] ?? null, 'retry must preserve concurrent password change');
    assertSame('G-ABCDEF1234', $final['analytics']['ga4_measurement_id'] ?? null, 'retry must apply requested analytics change');

    assertSame(false, file_exists($configPath . '.tmp'), 'fixed config.php.tmp staging file must not be used');
    assertSame(true, is_file($root . '/storage/config-write.lock'), 'persistent config lock file must exist');
    assertSame(0600, fileperms($root . '/storage/config-write.lock') & 0777, 'config lock must be private');

    if (function_exists('pcntl_fork')) {
        verifySharedLockBlocksPasswordUpdater($root, $configPath);
    }

    echo "config_write_cas_check: OK\n";
} finally {
    removeTree($root);
}

function verifySharedLockBlocksPasswordUpdater(string $root, string $configPath): void
{
    $go = $root . '/child-go';
    $started = $root . '/child-started';
    $finished = $root . '/child-finished';

    // Fork before the parent acquires the lock so the child cannot inherit a
    // descriptor that already owns the test lock.
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('could not fork config lock test');
    }
    if ($pid === 0) {
        $deadline = microtime(true) + 3.0;
        while (!is_file($go) && microtime(true) < $deadline) {
            usleep(1000);
        }
        if (!is_file($go)) {
            exit(3);
        }
        file_put_contents($started, '1');
        try {
            (new PostPasswordHashUpdater($root))->update('child-password-hash');
            file_put_contents($finished, '1');
            exit(0);
        } catch (Throwable $exception) {
            exit(4);
        }
    }

    ConfigWriteLock::run($root, function () use ($go, $started, $finished): void {
        file_put_contents($go, '1');
        $deadline = microtime(true) + 2.0;
        while (!is_file($started) && microtime(true) < $deadline) {
            usleep(1000);
        }
        assertSame(true, is_file($started), 'child password updater must start while parent holds lock');
        usleep(100000);
        assertSame(false, is_file($finished), 'password updater must wait for the shared config lock');
    });

    pcntl_waitpid($pid, $status);
    assertSame(0, pcntl_wexitstatus($status), 'child password update must finish after lock release');
    assertSame(true, is_file($finished), 'child must complete after shared lock release');
    $afterBlocking = require $configPath;
    assertSame('child-password-hash', $afterBlocking['security']['post_password_hash'] ?? null, 'blocked updater must apply after lock release');
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
