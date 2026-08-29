<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdateLock.php';
require_once dirname(__DIR__) . '/core/UpdateService.php';
require_once dirname(__DIR__) . '/core/InstalledIntegrityVerifier.php';

use Tomos\InstalledIntegrityVerifier;
use Tomos\UpdateException;
use Tomos\UpdateService;

$root = sys_get_temp_dir() . '/tomos-update-integrity-' . bin2hex(random_bytes(6));
foreach (['storage/update-tmp', 'storage/update-backups', 'storage/update-logs', 'update', 'core'] as $directory) {
    mkdir($root . '/' . $directory, 0700, true);
}

$oldVersion = '0.3.1';
$newVersion = '0.4.0';
$oldIndex = "<?php\necho 'old';\n";
$newIndex = "<?php\necho 'new';\n";
$requiredFiles = "VERSION\nindex.php\ncore/required-installed-files.txt\n";

file_put_contents($root . '/VERSION', $oldVersion . "\n");
file_put_contents($root . '/index.php', $oldIndex);
file_put_contents($root . '/core/required-installed-files.txt', $requiredFiles);

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$privateKey = '';
if ($key === false || !openssl_pkey_export($key, $privateKey)) {
    throw new RuntimeException('test signing key could not be created');
}
$details = openssl_pkey_get_details($key);
$publicKey = is_array($details) ? (string) ($details['key'] ?? '') : '';
if ($publicKey === '') {
    throw new RuntimeException('test public key could not be read');
}
file_put_contents($root . '/update/public-key.pem', $publicKey);

$badZip = $root . '/bad.zip';
makeSignedUpdateZip($badZip, $oldVersion, $newVersion, $privateKey, [
    'VERSION' => $newVersion . "\n",
    'index.php' => $newIndex,
    'core/required-installed-files.txt' => "../invalid\n",
]);

$goodZip = $root . '/good.zip';
makeSignedUpdateZip($goodZip, $oldVersion, $newVersion, $privateKey, [
    'VERSION' => $newVersion . "\n",
    'index.php' => $newIndex,
    'core/required-installed-files.txt' => $requiredFiles,
]);

try {
    $service = new UpdateService($root);
    $summary = $service->stageDownloadedPackage($badZip, 'integrity-owner', $oldVersion, $newVersion);
    $result = $service->apply((string) $summary['id'], 'integrity-owner');

    $caught = null;
    try {
        (new InstalledIntegrityVerifier($root))->verifyAfterUpdate($result);
    } catch (UpdateException $exception) {
        $caught = $exception;
    }
    if (!$caught instanceof UpdateException || $caught->stage() !== 'verify_required_files') {
        throw new RuntimeException('post-update verification failure was not surfaced');
    }
    assertFileBytes($root . '/VERSION', $oldVersion . "\n", 'VERSION was not rolled back after verification failure');
    assertFileBytes($root . '/index.php', $oldIndex, 'runtime file was not rolled back after verification failure');
    assertFileBytes($root . '/core/required-installed-files.txt', $requiredFiles, 'required file list was not rolled back');
    if ($service->currentVersion() !== $oldVersion) {
        throw new RuntimeException('failed update left the new current version');
    }
    if ((glob($root . '/storage/update-tmp/*') ?: []) !== []) {
        throw new RuntimeException('staging remained after verification rollback');
    }
    if (is_file($root . '/storage/update.lock')) {
        throw new RuntimeException('update lock remained after verification rollback');
    }

    $retry = new UpdateService($root);
    $retrySummary = $retry->stageDownloadedPackage($goodZip, 'integrity-owner', $oldVersion, $newVersion);
    $retryResult = $retry->apply((string) $retrySummary['id'], 'integrity-owner');
    (new InstalledIntegrityVerifier($root))->verifyAfterUpdate($retryResult);
    assertFileBytes($root . '/VERSION', $newVersion . "\n", 'successful retry did not update VERSION');
    assertFileBytes($root . '/index.php', $newIndex, 'successful retry did not update runtime');

    echo "update_integrity_rollback_check: OK\n";
} finally {
    removeTree($root);
}

function makeSignedUpdateZip(string $path, string $fromVersion, string $version, string $privateKey, array $files): void
{
    $manifestFiles = [];
    foreach ($files as $relative => $bytes) {
        $manifestFiles[$relative] = hash('sha256', (string) $bytes);
    }
    ksort($manifestFiles);
    $manifest = json_encode([
        'product' => 'Tomos',
        'from_version' => $fromVersion,
        'version' => $version,
        'files' => $manifestFiles,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($manifest)) {
        throw new RuntimeException('manifest could not be encoded');
    }
    $signature = '';
    if (!openssl_sign($manifest, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('manifest could not be signed');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('update ZIP could not be created');
    }
    $zip->addFromString('manifest.json', $manifest);
    $zip->addFromString('manifest.sig', $signature);
    foreach (array_keys($manifestFiles) as $relative) {
        $zip->addFromString('files/' . $relative, (string) $files[$relative]);
    }
    $zip->close();
}

function assertFileBytes(string $path, string $expected, string $message): void
{
    $actual = @file_get_contents($path);
    if (!is_string($actual) || !hash_equals(hash('sha256', $expected), hash('sha256', $actual))) {
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
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
