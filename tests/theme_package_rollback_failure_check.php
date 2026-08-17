<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/core/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Tomos\ThemePackageException;
use Tomos\ThemePackageInstaller;

$sourcePath = dirname(__DIR__) . '/core/ThemePackageInstaller.php';
$source = (string) file_get_contents($sourcePath);

assertContains(
    '$this->removeInstalledTheme($themeId, $stagingDir);',
    $source,
    'apply() must route installed-theme failures through rollback cleanup'
);
assertContains(
    '追加後の検証に失敗し、自動復元も完了できませんでした。管理者による確認が必要です。',
    $source,
    'rollback failure message must remain explicit'
);
assertContains(
    "'rollback'",
    $source,
    'rollback failure must retain an explicit rollback stage'
);

$root = sys_get_temp_dir() . '/tomos-theme-rollback-' . bin2hex(random_bytes(8));
mkdir($root . '/storage', 0700, true);
mkdir($root . '/themes/tomos-test', 0755, true);
file_put_contents($root . '/themes/tomos-test/keep.txt', 'rollback artifact');
$installer = new ThemePackageInstaller($root, $root . '/themes');

try {
    $target = $root . '/themes/tomos-test';
    @chmod($target, 0555);
    @chmod($root . '/themes', 0555);

    $method = new ReflectionMethod($installer, 'removeInstalledTheme');
    $method->setAccessible(true);
    $caught = null;
    try {
        // A nonexistent staging directory forces the same removeTree fallback
        // used when quarantine is unavailable. With both target and parent
        // read-only, the normal CI user cannot remove the installed artifact.
        $method->invoke($installer, 'tomos-test', $root . '/missing-staging');
    } catch (ReflectionException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    if ($caught === null) {
        // Privileged/root environments can bypass directory permissions. The
        // source-level invariant above still protects the propagation path;
        // the permission injection is authoritative on normal CI/runtime users.
        fwrite(STDERR, "SKIP runtime rollback permission injection: process can remove read-only directories.\n");
    } else {
        if (!$caught instanceof ThemePackageException) {
            throw new RuntimeException('unexpected rollback exception type: ' . get_class($caught));
        }
        if ($caught->stage() !== 'rollback') {
            throw new RuntimeException('expected rollback stage, got ' . $caught->stage());
        }
        if (strpos($caught->getMessage(), '自動復元も完了できませんでした') === false) {
            throw new RuntimeException('rollback failure message is not explicit');
        }
        if (!is_dir($target)) {
            throw new RuntimeException('failed rollback artifact must remain available for manual inspection');
        }
    }

    echo "theme_package_rollback_failure_check: OK\n";
} finally {
    @chmod($root . '/themes', 0755);
    @chmod($root . '/themes/tomos-test', 0755);
    removeTree($root);
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
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
    @chmod($path, 0755);
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
