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

if (!class_exists(ZipArchive::class) || !function_exists('pcntl_fork')) {
    fwrite(STDERR, "SKIP: ZipArchive or pcntl_fork is unavailable.\n");
    exit(0);
}

$root = sys_get_temp_dir() . '/tomos-theme-rollback-' . bin2hex(random_bytes(8));
mkdir($root . '/storage', 0700, true);
mkdir($root . '/themes', 0755, true);
$installer = new ThemePackageInstaller($root, $root . '/themes');
$zipPath = $root . '/theme.zip';
$owner = 'rollback-test-owner';

try {
    makeThemeZip($zipPath);
    [$id] = inspectThemeZip($installer, $zipPath, $owner);

    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('cannot fork watcher');
    }

    if ($pid === 0) {
        $deadline = microtime(true) + 5.0;
        $themeDir = $root . '/themes/tomos-test';
        $themeJson = $themeDir . '/theme.json';
        while (microtime(true) < $deadline) {
            if (is_file($themeJson)) {
                // Force post-placement validation to fail, then make both the
                // installed theme and its parent non-writable so rollback cannot
                // quarantine or remove the installed directory.
                @unlink($themeJson);
                @chmod($themeDir, 0555);
                @chmod($root . '/themes', 0555);
                exit(0);
            }
            usleep(100);
        }
        exit(3);
    }

    $caught = null;
    try {
        $installer->apply($id, $owner);
    } catch (ThemePackageException $exception) {
        $caught = $exception;
    } finally {
        pcntl_waitpid($pid, $status);
    }

    if (pcntl_wexitstatus($status) !== 0) {
        throw new RuntimeException('watcher did not observe installed theme');
    }
    if (!$caught instanceof ThemePackageException) {
        throw new RuntimeException('rollback failure was not surfaced');
    }
    if ($caught->stage() !== 'rollback') {
        throw new RuntimeException('expected rollback stage, got ' . $caught->stage());
    }
    if (strpos($caught->getMessage(), '自動復元も完了できませんでした') === false) {
        throw new RuntimeException('rollback failure message is not explicit');
    }
    if (!is_dir($root . '/themes/tomos-test')) {
        throw new RuntimeException('test did not preserve failed rollback artifact');
    }
    if (file_exists($root . '/storage/theme-upload.lock')) {
        throw new RuntimeException('theme upload lock remained after rollback failure');
    }

    echo "theme_package_rollback_failure_check: OK\n";
} finally {
    @chmod($root . '/themes', 0755);
    @chmod($root . '/themes/tomos-test', 0755);
    foreach (glob($root . '/themes/.tomos-theme-staging-*') ?: [] as $path) {
        @chmod($path, 0755);
    }
    removeTree($root);
}

function inspectThemeZip(ThemePackageInstaller $installer, string $zipPath, string $owner): array
{
    $id = bin2hex(random_bytes(16));
    $rootProperty = new ReflectionProperty($installer, 'temporaryRoot');
    $temporaryRoot = (string) $rootProperty->getValue($installer);
    mkdir($temporaryRoot . '/' . $id, 0700, true);
    copy($zipPath, $temporaryRoot . '/' . $id . '/package.zip');
    $method = new ReflectionMethod($installer, 'inspectPackage');
    $summary = $method->invoke($installer, $id, $owner, true);
    return [$id, $summary];
}

function makeThemeZip(string $path): void
{
    $themeJson = (string) json_encode([
        'name' => 'tomos-test',
        'display_name' => 'Tomos Test',
        'version' => '1.0.0',
        'description' => 'Rollback failure fixture.',
        'author' => 'Tomos',
    ], JSON_UNESCAPED_SLASHES);

    $entries = [
        'tomos-test/theme.json' => $themeJson,
        'tomos-test/templates/layout.html' => '<!doctype html><html><body>{{{ page.body }}}</body></html>',
        'tomos-test/templates/page.html' => '<article><h1>{{ page.title }}</h1>{{{ page.content }}}</article>',
        'tomos-test/templates/list.html' => '<main><h1>{{ page.title }}</h1>{{{ list.pages }}}</main>',
        'tomos-test/assets/style.css' => 'body { color: #222; }',
        'tomos-test/preview.png' => (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
        'tomos-test/README.md' => "# Test theme\n",
        'tomos-test/LICENSE' => "Test license\n",
    ];

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('cannot create ZIP');
    }
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
    }
    $zip->close();
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
