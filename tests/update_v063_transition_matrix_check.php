<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdateLock.php';
require_once dirname(__DIR__) . '/core/UpdateService.php';
require_once dirname(__DIR__) . '/core/UpdaterSelfUpdate.php';
require_once dirname(__DIR__) . '/core/InstalledIntegrityVerifier.php';

use Tomos\InstalledIntegrityVerifier;
use Tomos\UpdateService;
use Tomos\UpdaterSelfUpdate;

$root = dirname(__DIR__);
$publicRepo = getenv('TOMOS_PUBLIC_REPO') ?: '';
$package061 = getenv('TOMOS_V063_FROM_061') ?: '';
$package062 = getenv('TOMOS_V063_FROM_062') ?: '';
if ($publicRepo === '' || !is_dir($publicRepo) || !is_file($package061) || !is_file($package062)) {
    fwrite(STDERR, "SKIP: public v0.6.2 source or v0.6.3 transition packages are unavailable.\n");
    exit(0);
}

$tmp = sys_get_temp_dir() . '/tomos-v063-transition-' . bin2hex(random_bytes(8));
if (!mkdir($tmp, 0700, true)) {
    throw new RuntimeException('could not create transition fixture');
}

try {
    runTransition($root, 'e770fee', $package061, '0.6.1');
    runTransitionFromRepo($root, $publicRepo, 'v0.6.2', $package062, '0.6.2');
    echo "update_v063_transition_matrix_check: 0.6.1 -> 0.6.3 and 0.6.2 -> 0.6.3 passed\n";
} finally {
    removeTree($tmp);
}

function runTransition(string $root, string $ref, string $package, string $from): void
{
    $fixture = tempnam(sys_get_temp_dir(), 'tomos-v063-061-');
    if ($fixture === false) {
        throw new RuntimeException('could not allocate v0.6.1 fixture path');
    }
    @unlink($fixture);
    if (!mkdir($fixture, 0700, true)) {
        throw new RuntimeException('could not create v0.6.1 fixture');
    }
    archiveInto($root, $ref, $fixture);
    exerciseUpdate($root, $fixture, $package, $from);
    removeTree($fixture);
}

function runTransitionFromRepo(string $root, string $repo, string $ref, string $package, string $from): void
{
    $fixture = tempnam(sys_get_temp_dir(), 'tomos-v063-062-');
    if ($fixture === false) {
        throw new RuntimeException('could not allocate v0.6.2 fixture path');
    }
    @unlink($fixture);
    if (!mkdir($fixture, 0700, true)) {
        throw new RuntimeException('could not create v0.6.2 fixture');
    }
    archiveInto($repo, $ref, $fixture);
    exerciseUpdate($root, $fixture, $package, $from);
    removeTree($fixture);
}

function archiveInto(string $repo, string $ref, string $destination): void
{
    $command = 'git -C ' . escapeshellarg($repo) . ' archive ' . escapeshellarg($ref)
        . ' | tar -x -C ' . escapeshellarg($destination);
    $output = [];
    $status = 0;
    exec($command, $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('could not materialize baseline: ' . $ref);
    }
    foreach (['storage/update-tmp', 'storage/update-backups', 'storage/update-logs', 'core/updater-pending'] as $directory) {
        if (!is_dir($destination . '/' . $directory) && !mkdir($destination . '/' . $directory, 0700, true)) {
            throw new RuntimeException('could not create baseline directory: ' . $directory);
        }
    }
    file_put_contents($destination . '/config.php', "<?php return ['theme'=>['name'=>'custom-theme']];\n", LOCK_EX);
    mkdir($destination . '/content', 0700, true);
    file_put_contents($destination . '/content/keep.md', "# Keep\n", LOCK_EX);
    mkdir($destination . '/uploads', 0700, true);
    file_put_contents($destination . '/uploads/keep.txt', "keep\n", LOCK_EX);
    mkdir($destination . '/themes/custom-theme', 0700, true);
    file_put_contents($destination . '/themes/custom-theme/theme.json', "{}\n", LOCK_EX);
    file_put_contents($destination . '/themes/custom-theme/custom.txt', "keep\n", LOCK_EX);
}

function exerciseUpdate(string $sourceRoot, string $fixture, string $package, string $from): void
{
    $before = [];
    foreach (['config.php', 'content/keep.md', 'uploads/keep.txt', 'themes/custom-theme/custom.txt'] as $path) {
        $before[$path] = hash_file('sha256', $fixture . '/' . $path);
    }
    $service = new UpdateService($fixture);
    if ($service->currentVersion() !== $from) {
        throw new RuntimeException('baseline VERSION mismatch: ' . $from);
    }
    $summary = $service->stageDownloadedPackage($package, 'v063-transition-owner-' . $from, $from, '0.6.3');
    $result = (new InstalledIntegrityVerifier($fixture))->verifyAfterUpdate($service->apply((string) $summary['id'], 'v063-transition-owner-' . $from));
    if (empty($result['ok'])) {
        throw new RuntimeException('update result was not successful: ' . $from);
    }
    $selfUpdate = new UpdaterSelfUpdate($fixture);
    if ($selfUpdate->hasPendingUpdate()) {
        $selfResult = $selfUpdate->apply();
        if (empty($selfResult['applied'])) {
            throw new RuntimeException('pending updater bundle did not complete: ' . $from);
        }
    }
    if (trim((string) file_get_contents($fixture . '/VERSION')) !== '0.6.3') {
        throw new RuntimeException('updated VERSION mismatch: ' . $from);
    }
    foreach (['docs/theme/theme-rules.json', 'core/ThemeRules.php', 'core/ContentSecurityPolicy.php'] as $path) {
        if (!is_file($fixture . '/' . $path)) {
            throw new RuntimeException('required runtime file missing after update: ' . $from . ' ' . $path);
        }
    }
    foreach ($before as $path => $hash) {
        if (!hash_equals($hash, hash_file('sha256', $fixture . '/' . $path))) {
            throw new RuntimeException('site data changed during update: ' . $from . ' ' . $path);
        }
    }
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) return;
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) removeTree($path . DIRECTORY_SEPARATOR . $item);
    @rmdir($path);
}
