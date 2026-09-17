<?php

declare(strict_types=1);

$distribution = getenv('TOMOS_V061_DISTRIBUTION') ?: dirname(__DIR__) . '/build/tomos-0.6.1.zip';
$updatePackage = getenv('TOMOS_V062_MIGRATION_PACKAGE') ?: '';
if (!is_file($distribution) || !is_file($updatePackage) || !class_exists(ZipArchive::class)) {
    failOrSkip('v0.6.1 distribution or v0.6.2 update package is unavailable.');
}

$fixture = sys_get_temp_dir() . '/tomos-v061-to-v062-' . bin2hex(random_bytes(8));
if (!mkdir($fixture, 0700, true)) {
    throw new RuntimeException('could not create migration fixture');
}

try {
    $zip = new ZipArchive();
    if ($zip->open($distribution) !== true || !$zip->extractTo($fixture)) {
        throw new RuntimeException('could not extract v0.6.1 distribution');
    }
    $zip->close();
    $testPublicKey = getenv('TOMOS_HISTORICAL_PUBLIC_KEY') ?: '';
    if ($testPublicKey !== '') {
        if (!is_file($testPublicKey) || !copy($testPublicKey, $fixture . '/update/public-key.pem')) {
            throw new RuntimeException('could not install historical test public key');
        }
    }
    removeMigrationTree($fixture . '/docs/theme');

    $config = "<?php return ['site' => ['name' => 'preserved'], 'theme' => ['name' => 'custom-theme']];\n";
    file_put_contents($fixture . '/config.php', $config, LOCK_EX);
    file_put_contents($fixture . '/content/preserved.md', "# Preserved\n", LOCK_EX);
    mkdir($fixture . '/uploads', 0700, true);
    file_put_contents($fixture . '/uploads/preserved.txt', "keep\n", LOCK_EX);
    mkdir($fixture . '/themes/custom-theme', 0700, true);
    file_put_contents($fixture . '/themes/custom-theme/theme.json', "{}\n", LOCK_EX);
    foreach (['storage/update-tmp', 'storage/update-backups', 'storage/update-logs', 'core/updater-pending'] as $directory) {
        if (!is_dir($fixture . '/' . $directory) && !mkdir($fixture . '/' . $directory, 0700, true)) {
            throw new RuntimeException('could not prepare ' . $directory);
        }
    }

    $before = [
        'config.php' => hash_file('sha256', $fixture . '/config.php'),
        'content/preserved.md' => hash_file('sha256', $fixture . '/content/preserved.md'),
        'uploads/preserved.txt' => hash_file('sha256', $fixture . '/uploads/preserved.txt'),
        'themes/custom-theme/theme.json' => hash_file('sha256', $fixture . '/themes/custom-theme/theme.json'),
    ];

    require_once $fixture . '/core/UpdateLock.php';
    require_once $fixture . '/core/UpdateService.php';
    $service = new Tomos\UpdateService($fixture);
    $summary = $service->stageDownloadedPackage($updatePackage, 'v061-to-v062-owner', '0.6.1', '0.6.2');
    $result = $service->apply((string) $summary['id'], 'v061-to-v062-owner');
    if (empty($result['ok']) || trim((string) file_get_contents($fixture . '/VERSION')) !== '0.6.2') {
        throw new RuntimeException('v0.6.1 UpdateService did not complete the main update');
    }

    require_once $fixture . '/core/InstalledIntegrityVerifier.php';
    $verified = (new Tomos\InstalledIntegrityVerifier($fixture))->verifyAfterUpdate($result);
    if (empty($verified['ok']) || !is_file($fixture . '/docs/theme/theme-rules.json')) {
        throw new RuntimeException('v0.6.2 update did not materialize Theme rules before required-file verification');
    }

    require_once $fixture . '/core/UpdaterSelfUpdate.php';
    $selfUpdate = new Tomos\UpdaterSelfUpdate($fixture);
    if (!$selfUpdate->hasPendingUpdate()) {
        throw new RuntimeException('v0.6.2 updater bundle is not pending');
    }
    $selfResult = $selfUpdate->apply();
    if (empty($selfResult['ok']) || !$selfResult['cleanup_ok']) {
        throw new RuntimeException('v0.6.2 updater bundle did not complete');
    }
    $rulesPath = $fixture . '/docs/theme/theme-rules.json';
    if (!is_file($rulesPath) || hash_file('sha256', $rulesPath) !== hash_file('sha256', dirname(__DIR__) . '/docs/theme/theme-rules.json')) {
        throw new RuntimeException('theme-rules.json was not installed at its runtime path');
    }
    foreach ($before as $relative => $hash) {
        if (!is_file($fixture . '/' . $relative) || hash_file('sha256', $fixture . '/' . $relative) !== $hash) {
            throw new RuntimeException('protected resource changed: ' . $relative);
        }
    }

    echo "update_theme_rules_migration_check: v0.6.1 -> v0.6.2 update and runtime preservation passed\n";
} finally {
    removeMigrationTree($fixture);
}

function removeMigrationTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeMigrationTree($path . DIRECTORY_SEPARATOR . $item);
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
