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

$sourceTheme = dirname(__DIR__) . '/theme-packages/tomos-lab';
if (!is_dir($sourceTheme)) {
    fwrite(STDERR, "FAIL: distributable tomos-lab theme package source is missing.\n");
    exit(1);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-lab-commercial-' . bin2hex(random_bytes(8));
$passes = 0;
$failures = [];

try {
    mkdir($root . '/storage', 0700, true);
    mkdir($root . '/themes', 0755, true);
    mkdir($root . '/theme-assets', 0755, true);
    mkdir($root . '/content', 0755, true);

    file_put_contents($root . '/VERSION', "0.3.0\n");
    file_put_contents($root . '/config.php', "<?php return ['theme'=>['name'=>'tomos-lab']];\n");
    file_put_contents($root . '/theme-settings.php', "<?php return ['hero'=>['title'=>'Example Lab']];\n");
    file_put_contents($root . '/theme-assets/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    file_put_contents($root . '/theme-assets/hero.jpg', 'site-specific-hero');
    file_put_contents($root . '/content/index.md', "# Example Lab\n\nResearch content.\n");

    $preserved = hashes($root, [
        'config.php',
        'theme-settings.php',
        'theme-assets/logo.svg',
        'theme-assets/hero.jpg',
        'content/index.md',
    ]);

    check('install commercial theme package', function () use ($root, $sourceTheme): void {
        $entries = themeEntriesFromDirectory($sourceTheme);
        $deployment = new ThemePackageDeployment($root, $root . '/themes', 'production-staff');
        $result = deployEntries($deployment, $root, 'production-staff', $entries);
        assertSame('install', $result['operation']);
        assertSame('tomos-lab', $result['theme_id']);
        assertSame('1.0.0', $result['version']);
        assertTrue(is_file($root . '/themes/tomos-lab/templates/home.html'), 'home template was not installed');
    }, $passes, $failures);

    check('same-version production revision fully replaces theme', function () use ($root, $sourceTheme): void {
        file_put_contents($root . '/themes/tomos-lab/assets/obsolete-production.css', '.obsolete{}');

        $entries = themeEntriesFromDirectory($sourceTheme);
        $entries['tomos-lab/assets/style.css'] .= "\n/* production revision A */\n";
        $deployment = new ThemePackageDeployment($root, $root . '/themes', 'production-staff');
        $result = deployEntries($deployment, $root, 'production-staff', $entries);

        assertSame('update', $result['operation']);
        assertSame('same', $result['version_relation']);
        assertSame('1.0.0', $result['previous_version']);
        assertSame('1.0.0', $result['version']);
        assertTrue(strpos((string) file_get_contents($root . '/themes/tomos-lab/assets/style.css'), 'production revision A') !== false, 'same-version revision was not deployed');
        assertTrue(!file_exists($root . '/themes/tomos-lab/assets/obsolete-production.css'), 'obsolete theme file survived whole-directory replacement');
    }, $passes, $failures);

    check('released theme upgrade keeps active selection and site data', function () use ($root, $sourceTheme, $preserved): void {
        $entries = themeEntriesFromDirectory($sourceTheme);
        $theme = json_decode($entries['tomos-lab/theme.json'], true);
        $theme['version'] = '1.0.1';
        $entries['tomos-lab/theme.json'] = json_encode($theme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $entries['tomos-lab/assets/style.css'] .= "\n/* released revision 1.0.1 */\n";

        $deployment = new ThemePackageDeployment($root, $root . '/themes', 'production-staff');
        $result = deployEntries($deployment, $root, 'production-staff', $entries);

        assertSame('update', $result['operation']);
        assertSame('newer', $result['version_relation']);
        assertSame('1.0.1', $result['version']);
        assertTrue(strpos((string) file_get_contents($root . '/config.php'), "'name'=>'tomos-lab'") !== false, 'active theme selection changed');
        assertSame($preserved, hashes($root, array_keys($preserved)));
    }, $passes, $failures);

    check('theme package remains isolated from site-specific resources', function () use ($root, $preserved): void {
        assertSame($preserved, hashes($root, array_keys($preserved)));
        assertTrue(!is_file($root . '/themes/tomos-lab/theme-settings.php'), 'theme package unexpectedly contains theme-settings.php');
        assertTrue(!is_dir($root . '/themes/tomos-lab/theme-assets'), 'theme package unexpectedly contains theme-assets');
        assertTrue(!is_dir($root . '/themes/tomos-lab/content'), 'theme package unexpectedly contains content');
        assertTrue(!is_file($root . '/themes/tomos-lab/config.php'), 'theme package unexpectedly contains config.php');
    }, $passes, $failures);

    check('commercial package contains no PHP', function () use ($root): void {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/themes/tomos-lab', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                throw new RuntimeException('PHP file found in commercial theme: ' . $file->getPathname());
            }
        }
    }, $passes, $failures);
} finally {
    removeTree($root);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "lab_theme_commercial_workflow_check: {$passes} checks passed\n";

function themeEntriesFromDirectory(string $sourceTheme): array
{
    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceTheme, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($sourceTheme) + 1);
        $entries['tomos-lab/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = (string) file_get_contents($file->getPathname());
    }
    return $entries;
}

function deployEntries(ThemePackageDeployment $deployment, string $root, string $owner, array $entries): array
{
    [$id] = inspectEntries($deployment, $root, $owner, $entries);
    return $deployment->apply($id, $owner);
}

function inspectEntries(ThemePackageDeployment $deployment, string $root, string $owner, array $entries): array
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
    $summary = $inspect->invoke($installer, $id, $owner, true);
    unlink($zipPath);
    return [$id, $summary];
}

function makeZip(string $path, array $entries): void
{
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

function hashes(string $root, array $paths): array
{
    $result = [];
    foreach ($paths as $path) {
        $full = $root . '/' . $path;
        if (!is_file($full)) {
            throw new RuntimeException('preserved file is missing: ' . $path);
        }
        $result[$path] = hash_file('sha256', $full);
    }
    return $result;
}

function check(string $name, callable $callback, int &$passes, array &$failures): void
{
    try {
        $callback();
        $passes++;
    } catch (Throwable $exception) {
        $failures[] = $name . ': ' . $exception->getMessage();
    }
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
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
