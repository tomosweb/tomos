<?php

declare(strict_types=1);

$distribution = getenv('TOMOS_V061_DISTRIBUTION') ?: dirname(__DIR__) . '/build/tomos-0.6.1.zip';
$updatePackage = getenv('TOMOS_V062_UPDATE_PACKAGE') ?: '';
if (!is_file($distribution) || !is_file($updatePackage) || !class_exists(ZipArchive::class)) {
    failOrSkip('v0.6.1 distribution or v0.6.2 update package is unavailable.');
}

$fixture = sys_get_temp_dir() . '/tomos-legacy-browser-bootstrap-' . bin2hex(random_bytes(8));
if (!mkdir($fixture, 0700, true)) {
    throw new RuntimeException('could not create bootstrap fixture');
}

try {
    $zip = new ZipArchive();
    if ($zip->open($distribution) !== true || !$zip->extractTo($fixture)) {
        throw new RuntimeException('could not extract v0.6.1 distribution');
    }
    $zip->close();

    foreach (['storage/update-tmp', 'storage/update-backups', 'storage/update-logs', 'core/updater-pending', 'docs/theme'] as $directory) {
        if (!is_dir($fixture . '/' . $directory) && !mkdir($fixture . '/' . $directory, 0700, true)) {
            throw new RuntimeException('could not prepare ' . $directory);
        }
    }
    file_put_contents($fixture . '/config.php', "<?php return ['site' => ['name' => 'preserved'], 'theme' => ['name' => 'custom-theme']];\n", LOCK_EX);
    file_put_contents($fixture . '/content/preserved.md', "# Preserved\n", LOCK_EX);
    mkdir($fixture . '/uploads', 0700, true);
    file_put_contents($fixture . '/uploads/preserved.txt', "keep\n", LOCK_EX);
    mkdir($fixture . '/themes/custom-theme', 0700, true);
    file_put_contents($fixture . '/themes/custom-theme/theme.json', "{}\n", LOCK_EX);

    $before = [
        'config.php' => hash_file('sha256', $fixture . '/config.php'),
        'content/preserved.md' => hash_file('sha256', $fixture . '/content/preserved.md'),
        'uploads/preserved.txt' => hash_file('sha256', $fixture . '/uploads/preserved.txt'),
        'themes/custom-theme/theme.json' => hash_file('sha256', $fixture . '/themes/custom-theme/theme.json'),
    ];

    require_once $fixture . '/core/UpdateLock.php';
    require_once $fixture . '/core/UpdateService.php';
    require_once $fixture . '/core/InstalledIntegrityVerifier.php';

    $service = new Tomos\UpdateService($fixture);
    $oldVerifier = new Tomos\InstalledIntegrityVerifier($fixture);
    $summary = $service->stageDownloadedPackage($updatePackage, 'legacy-bootstrap-owner', '0.6.1', '0.6.2');
    $result = $service->apply((string) $summary['id'], 'legacy-bootstrap-owner');
    $verified = $oldVerifier->verifyAfterUpdate($result);
    if (empty($verified['ok']) || trim((string) file_get_contents($fixture . '/VERSION')) !== '0.6.2') {
        throw new RuntimeException('legacy verifier did not accept the bootstrap update');
    }
    if (is_file($fixture . '/docs/theme/theme-rules.json')) {
        throw new RuntimeException('Theme rules were installed before the pending bootstrap finalize step');
    }

    require_once $fixture . '/core/UpdaterSelfUpdate.php';
    $selfUpdate = new Tomos\UpdaterSelfUpdate($fixture);
    if (!$selfUpdate->hasPendingUpdate()) {
        throw new RuntimeException('bootstrap update did not leave a pending finalize bundle');
    }
    try {
        $selfResult = $selfUpdate->apply();
    } catch (Tomos\UpdaterSelfUpdateException $exception) {
        throw new RuntimeException('bootstrap finalize failed at ' . $exception->stage());
    }
    if (empty($selfResult['ok']) || !$selfResult['cleanup_ok']) {
        throw new RuntimeException('bootstrap finalize did not complete');
    }
    if (!is_file($fixture . '/docs/theme/theme-rules.json')
        || hash_file('sha256', $fixture . '/docs/theme/theme-rules.json') !== hash_file('sha256', dirname(__DIR__) . '/docs/theme/theme-rules.json')
        || strpos((string) file_get_contents($fixture . '/core/required-installed-files.txt'), "docs/theme/theme-rules.json\n") === false
    ) {
        throw new RuntimeException('Theme rules and the v0.6.2 required-file list were not finalized');
    }
    if ((new Tomos\UpdaterSelfUpdate($fixture))->hasPendingUpdate()) {
        throw new RuntimeException('bootstrap pending files were not cleaned up');
    }
    foreach ($before as $relative => $hash) {
        if (!is_file($fixture . '/' . $relative) || hash_file('sha256', $fixture . '/' . $relative) !== $hash) {
            throw new RuntimeException('protected resource changed: ' . $relative);
        }
    }

    echo "update_legacy_browser_bootstrap_check: legacy verifier, pending finalize, and protected-resource preservation passed\n";
} finally {
    if (getenv('TOMOS_KEEP_BOOTSTRAP_FIXTURE') !== '1') {
        removeLegacyBootstrapTree($fixture);
    } else {
        fwrite(STDERR, "bootstrap fixture: {$fixture}\n");
    }
}

function removeLegacyBootstrapTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeLegacyBootstrapTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

function failOrSkip(string $message): void
{
    if (getenv('TOMOS_RELEASE_GATE') === '1' || in_array('--strict', $GLOBALS['argv'] ?? [], true)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDERR, "SKIP: {$message}\n");
    exit(0);
}
