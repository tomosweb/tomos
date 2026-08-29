<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/UpdateFileSet.php';
require_once dirname(__DIR__) . '/core/UpdateLock.php';
require_once dirname(__DIR__) . '/core/UpdateService.php';
require_once dirname(__DIR__) . '/core/UpdaterSelfUpdate.php';

use Tomos\UpdateService;
use Tomos\UpdaterSelfUpdate;

$root = dirname(__DIR__);
$distributionPath = $root . '/build/tomos-' . trim((string) file_get_contents($root . '/VERSION')) . '.zip';
if (!is_file($distributionPath) || !class_exists(ZipArchive::class)) {
    fwrite(STDERR, "SKIP: distribution ZIP or ZipArchive is unavailable.\n");
    exit(0);
}

$fromVersion = '0.3.1';
$targetVersion = '0.4.0';
$runtimeFiles = UpdateFileSet::fromGitDiff($root, 'v0.3.1', 'HEAD');
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-runtime-parity-' . bin2hex(random_bytes(8));
if (!mkdir($tmp, 0700, true)) {
    throw new RuntimeException('could not create runtime parity fixture');
}

try {
    $fixture = $tmp . '/fixture';
    mkdir($fixture, 0700, true);
    $archiveCommand = 'git -C ' . escapeshellarg($root) . ' archive v0.3.1 | tar -x -C ' . escapeshellarg($fixture);
    $archiveOutput = [];
    $archiveCode = 0;
    exec($archiveCommand, $archiveOutput, $archiveCode);
    if ($archiveCode !== 0) {
        throw new RuntimeException('could not materialize v0.3.1 fixture');
    }
    foreach (['storage/update-tmp', 'storage/update-backups', 'storage/update-logs', 'core/updater-pending'] as $directory) {
        mkdir($fixture . '/' . $directory, 0700, true);
    }
    copy($root . '/update/public-key.pem', $fixture . '/update/public-key.pem');
    file_put_contents($fixture . '/config.php', "<?php return ['theme'=>['name'=>'tomos-research-lab']];\n", LOCK_EX);
    mkdir($fixture . '/theme-assets', 0700, true);
    file_put_contents($fixture . '/theme-settings.php', "<?php return ['navigation'=>['mode'=>'manual']];\n", LOCK_EX);
    file_put_contents($fixture . '/theme-assets/preserved.txt', "keep\n", LOCK_EX);
    file_put_contents($fixture . '/content/preserved.md', "# Keep\n", LOCK_EX);
    mkdir($fixture . '/images', 0700, true);
    file_put_contents($fixture . '/images/preserved.txt', "keep\n", LOCK_EX);
    $protectedBefore = protectedSnapshot($fixture);

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $privateKey = '';
    if ($key === false || !openssl_pkey_export($key, $privateKey)) {
        throw new RuntimeException('could not create parity signing key');
    }
    $keyDetails = openssl_pkey_get_details($key);
    $publicKey = is_array($keyDetails) ? (string) ($keyDetails['key'] ?? '') : '';
    if ($publicKey === '') {
        throw new RuntimeException('could not export parity public key');
    }
    file_put_contents($fixture . '/update/public-key.pem', $publicKey, LOCK_EX);
    $keyPath = $tmp . '/private.pem';
    file_put_contents($keyPath, $privateKey, LOCK_EX);
    $packagePath = $tmp . '/update.zip';
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/tools/build-update-package.php')
        . ' ' . escapeshellarg('--from=' . $fromVersion)
        . ' ' . escapeshellarg('--version=' . $targetVersion)
        . ' ' . escapeshellarg('--private-key=' . $keyPath)
        . ' ' . escapeshellarg('--output=' . $packagePath)
        . ' ' . escapeshellarg('--from-ref=v0.3.1');
    $lines = [];
    $code = 0;
    exec($command . ' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException('could not build parity update: ' . implode("\n", $lines));
    }

    $service = new UpdateService($fixture);
    $summary = $service->stageDownloadedPackage($packagePath, 'runtime-parity-owner', $fromVersion, $targetVersion);
    $result = $service->apply((string) $summary['id'], 'runtime-parity-owner');
    if (!empty($result['ok']) && (new UpdaterSelfUpdate($fixture))->hasPendingUpdate()) {
        $selfUpdate = (new UpdaterSelfUpdate($fixture))->apply();
        if (empty($selfUpdate['applied'])) {
            throw new RuntimeException('pending updater bundle was not applied');
        }
    }

    $distribution = distributionInventory($distributionPath);
    $installed = filesystemInventory($fixture);
    if (array_keys($distribution) !== array_keys($installed)) {
        throw new RuntimeException('applied runtime inventory differs from distribution inventory');
    }
    foreach ($distribution as $path => $hash) {
        if (!hash_equals($hash, $installed[$path])) {
            throw new RuntimeException('applied runtime hash differs from distribution: ' . $path);
        }
    }
    if (trim((string) file_get_contents($fixture . '/VERSION')) !== $targetVersion) {
        throw new RuntimeException('applied runtime VERSION mismatch');
    }
    assertContains((string) file_get_contents($fixture . '/post/security/index.php'), 'return_to', 'Security return_to support missing after update');
    assertContains((string) file_get_contents($fixture . '/post/passkey/login/index.php'), 'refreshSessionCookiePath', 'Passkey cookie-path refresh missing after update');
    assertContains((string) file_get_contents($fixture . '/post/passkey/login/index.php'), 'returnUrl', 'Passkey returnUrl support missing after update');
    if (protectedSnapshot($fixture) !== $protectedBefore) {
        throw new RuntimeException('protected resources changed during update');
    }

    echo 'update_runtime_parity_check: runtime parity, auth transition, and protected-resource preservation passed' . PHP_EOL;
} finally {
    removeTree($tmp);
}

function distributionInventory(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('could not open distribution ZIP');
    }
    $files = [];
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $relative = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
            if ($relative === '' || substr($relative, -1) === '/' || !UpdateFileSet::isAllowedUpdatePath($relative) || strpos($relative, 'core/webauthn/vendor/') === 0) {
                continue;
            }
            $bytes = $zip->getFromIndex($i);
            if (!is_string($bytes)) {
                throw new RuntimeException('could not read distribution entry: ' . $relative);
            }
            $files[$relative] = hash('sha256', $bytes);
        }
    } finally {
        $zip->close();
    }
    ksort($files);
    return $files;
}

function filesystemInventory(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
        if (!UpdateFileSet::isAllowedUpdatePath($relative) || strpos($relative, 'core/webauthn/vendor/') === 0) {
            continue;
        }
        $files[$relative] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);
    return $files;
}

function protectedSnapshot(string $root): array
{
    $paths = ['config.php', 'theme-settings.php', 'theme-assets/preserved.txt', 'content/preserved.md', 'images/preserved.txt'];
    $snapshot = [];
    foreach ($paths as $path) {
        $snapshot[$path] = hash_file('sha256', $root . '/' . $path);
    }
    return $snapshot;
}

function assertContains(string $value, string $needle, string $message): void
{
    if (strpos($value, $needle) === false) {
        throw new RuntimeException($message);
    }
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) return;
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) removeTree($path . DIRECTORY_SEPARATOR . $item);
    @rmdir($path);
}
