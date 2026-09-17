<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Tomos\App;
use Tomos\ThemePackageDeployment;
use Tomos\ThemePackageInstaller;
use Tomos\ThemePackagePolicy;

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "SKIP: ZipArchive is unavailable.\n");
    exit(2);
}

$packages = [
    'tomos-quiet' => [
        'path' => dirname(__DIR__) . '/build/theme-test/tomos-quiet-1.0.4-test.zip',
        'version' => '1.0.4',
        'oldVersion' => '1.0.3',
        'marker' => 'quiet-more',
    ],
    'tomos-index' => [
        'path' => dirname(__DIR__) . '/build/theme-test/tomos-index-1.0.5-test.zip',
        'version' => '1.0.5',
        'oldVersion' => '1.0.4',
        'marker' => 'index-more',
    ],
];
foreach ($argv as $argument) {
    if (strpos($argument, '--quiet=') === 0) {
        $packages['tomos-quiet']['path'] = substr($argument, strlen('--quiet='));
    } elseif (strpos($argument, '--index=') === 0) {
        $packages['tomos-index']['path'] = substr($argument, strlen('--index='));
    }
}

$testRoot = sys_get_temp_dir() . '/tomos-theme-folder-pill-update-' . bin2hex(random_bytes(8));
mkdir($testRoot, 0700, true);
try {
    foreach ($packages as $themeId => $package) {
        checkPackageUpdate($themeId, $package, $testRoot . '/' . $themeId);
    }
    echo "theme_folder_pill_package_update_check: OK (ZIP validation, Theme update, active rendering)\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    removeTree($testRoot);
}

function checkPackageUpdate(string $themeId, array $package, string $root): void
{
    $zipPath = (string) $package['path'];
    if (!is_file($zipPath)) {
        throw new RuntimeException($themeId . ': ZIP is missing');
    }
    mkdir($root . '/storage', 0700, true);
    mkdir($root . '/themes', 0755, true);
    mkdir($root . '/content/news', 0755, true);
    mkdir($root . '/cache', 0755, true);
    file_put_contents($root . '/VERSION', "1.0.2\n", LOCK_EX);
    file_put_contents($root . '/config.php', "<?php\nreturn ['theme' => ['name' => '{$themeId}']];\n", LOCK_EX);
    file_put_contents($root . '/theme-settings.php', "<?php\nreturn ['folders' => ['news' => ['title' => 'news']]];\n", LOCK_EX);
    file_put_contents($root . '/content/index.md', "---\ntitle: Home\ndraft: false\n---\nHome\n", LOCK_EX);
    file_put_contents($root . '/content/news/article.md', "---\ntitle: Article\ndate: 2026-01-01\ndraft: false\n---\nArticle\n", LOCK_EX);

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true || !$zip->extractTo($root . '/themes')) {
        throw new RuntimeException($themeId . ': ZIP extraction failed');
    }
    $zip->close();
    $themePath = $root . '/themes/' . $themeId;
    $oldManifest = json_decode((string) file_get_contents($themePath . '/theme.json'), true);
    $oldManifest['version'] = (string) $package['oldVersion'];
    file_put_contents($themePath . '/theme.json', json_encode($oldManifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    $configHash = hash_file('sha256', $root . '/config.php');

    $deployment = new ThemePackageDeployment($root, $root . '/themes', 'theme-folder-pill-test');
    [$id] = inspectPackage($deployment, $zipPath, 'theme-folder-pill-test');
    $result = $deployment->apply($id, 'theme-folder-pill-test');
    if (($result['operation'] ?? '') !== 'update' || ($result['version'] ?? '') !== $package['version'] || ($result['version_relation'] ?? '') !== 'newer') {
        throw new RuntimeException($themeId . ': Theme update result is incorrect');
    }
    if (hash_file('sha256', $root . '/config.php') !== $configHash) {
        throw new RuntimeException($themeId . ': active Theme config changed during update');
    }

    $manifest = json_decode((string) file_get_contents($themePath . '/theme.json'), true);
    if (!is_array($manifest) || (string) ($manifest['version'] ?? '') !== $package['version']) {
        throw new RuntimeException($themeId . ': updated manifest version is incorrect');
    }
    $validation = (new ThemePackagePolicy())->validateExtracted($root . '/themes', $themeId);
    if (($validation['theme_id'] ?? '') !== $themeId || ($validation['version'] ?? '') !== $package['version']) {
        throw new RuntimeException($themeId . ': final Theme validation failed');
    }

    $config = [
        'site' => ['name' => 'Theme Folder Pill', 'description' => '', 'url' => 'https://example.test', 'base_path' => '', 'public_base_path' => ''],
        'paths' => ['content_dir' => $root . '/content', 'cache_dir' => $root . '/cache', 'theme_dir' => $root . '/themes', 'inbox_dir' => $root . '/inbox'],
        'theme' => ['name' => $themeId],
        'features' => ['metadata_cache' => false, 'html_cache' => false, 'rss' => false, 'sitemap' => false],
        'security' => ['allow_raw_html' => false, 'content_security_policy' => false],
        'analytics' => ['ga4_measurement_id' => ''],
    ];
    $home = render($config, '/');
    if (strpos($home, (string) $package['marker']) === false || strpos($home, 'href="/news/"') === false) {
        throw new RuntimeException($themeId . ': updated active Theme did not render immediately');
    }
}

function inspectPackage(ThemePackageDeployment $deployment, string $zipPath, string $owner): array
{
    $installerProperty = new ReflectionProperty($deployment, 'installer');
    $installer = $installerProperty->getValue($deployment);
    if (!$installer instanceof ThemePackageInstaller) {
        throw new RuntimeException('ThemePackageDeployment installer is unavailable');
    }
    $id = bin2hex(random_bytes(16));
    $temporaryRootProperty = new ReflectionProperty($installer, 'temporaryRoot');
    $temporaryRoot = (string) $temporaryRootProperty->getValue($installer);
    mkdir($temporaryRoot . '/' . $id, 0700, true);
    copy($zipPath, $temporaryRoot . '/' . $id . '/package.zip');
    $inspect = new ReflectionMethod($installer, 'inspectPackage');
    $inspect->invoke($installer, $id, $owner, true);
    return [$id];
}

function render(array $config, string $uri): string
{
    http_response_code(200);
    ob_start();
    try {
        (new App($config))->run($uri);
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
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
