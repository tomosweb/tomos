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

use Tomos\ThemePackageDeployment;
use Tomos\ThemePackageInstaller;

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "SKIP: ZipArchive is unavailable.\n");
    exit(2);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-theme-candidate-reset-' . bin2hex(random_bytes(8));
mkdir($root . '/storage', 0700, true);
mkdir($root . '/themes', 0755, true);

try {
    $deployment = new ThemePackageDeployment($root, $root . '/themes', 'owner-a');
    $firstId = stagePackage($deployment, $root, 'owner-a', themeEntries('1.0.0', 'body{color:#111;}'));
    $first = $deployment->apply($firstId, 'owner-a');
    assertSame('install', $first['operation'] ?? null);

    $deployment = new ThemePackageDeployment($root, $root . '/themes', 'owner-a');
    $secondId = stagePackage($deployment, $root, 'owner-a', themeEntries('1.0.0', 'body{color:#333;}'));

    $candidateProperty = new ReflectionProperty($deployment, 'candidateThemesDir');
    $candidateDir = (string) $candidateProperty->getValue($deployment);
    mkdir($candidateDir . '/tomos-test', 0700, true);
    file_put_contents($candidateDir . '/tomos-test/stale.txt', 'must not block update');

    $result = $deployment->apply($secondId, 'owner-a');

    assertSame('update', $result['operation'] ?? null);
    assertSame('same', $result['version_relation'] ?? null);
    assertSame('1.0.0', $result['previous_version'] ?? null);
    assertSame('1.0.0', $result['version'] ?? null);
    assertSame('body{color:#333;}', file_get_contents($root . '/themes/tomos-test/assets/style.css'));
    assertTrue(!file_exists($candidateDir . '/tomos-test'), 'candidate theme remained after update');

    echo "theme_update_candidate_reset_check: PASS\n";
} finally {
    removeTree($root);
}

function stagePackage(ThemePackageDeployment $deployment, string $root, string $owner, array $entries): string
{
    $zipPath = $root . '/package-' . bin2hex(random_bytes(4)) . '.zip';
    makeZip($zipPath, $entries);

    $installerProperty = new ReflectionProperty($deployment, 'installer');
    $installer = $installerProperty->getValue($deployment);
    if (!$installer instanceof ThemePackageInstaller) {
        throw new RuntimeException('deployment installer is unavailable');
    }

    $id = bin2hex(random_bytes(16));
    $temporaryRootProperty = new ReflectionProperty($installer, 'temporaryRoot');
    $temporaryRoot = (string) $temporaryRootProperty->getValue($installer);
    mkdir($temporaryRoot . '/' . $id, 0700, true);
    copy($zipPath, $temporaryRoot . '/' . $id . '/package.zip');

    $inspect = new ReflectionMethod($installer, 'inspectPackage');
    $inspect->invoke($installer, $id, $owner, true);
    unlink($zipPath);
    return $id;
}

function themeEntries(string $version, string $css): array
{
    return [
        'tomos-test/theme.json' => (string) json_encode([
            'name' => 'tomos-test',
            'display_name' => 'Tomos Test',
            'version' => $version,
            'description' => 'Candidate reset regression fixture.',
            'author' => 'Tomos',
        ], JSON_UNESCAPED_SLASHES),
        'tomos-test/templates/layout.html' => '<!doctype html><html><body>{{{ page.body }}}</body></html>',
        'tomos-test/templates/page.html' => '<article><h1>{{ page.title }}</h1>{{{ page.content }}}</article>',
        'tomos-test/templates/list.html' => '<main><h1>{{ page.title }}</h1>{{{ list.pages }}}</main>',
        'tomos-test/assets/style.css' => $css,
        'tomos-test/preview.png' => (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
        'tomos-test/README.md' => "# Test theme\n",
        'tomos-test/LICENSE' => "Test license\n",
    ];
}

function makeZip(string $path, array $entries): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('cannot create ZIP');
    }
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, (string) $content);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
    }
    $zip->close();
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
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
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
