<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdateLock.php';
require_once dirname(__DIR__) . '/core/UpdateService.php';

use Tomos\UpdateException;
use Tomos\UpdateService;
$tmp = sys_get_temp_dir() . '/tomos-update-recovery-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700, true);

function removeRecoveryTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            removeRecoveryTree($path . '/' . $item);
        }
    }
    @rmdir($path);
}

function makeRecoveryPackage(string $path, string $from, string $version, string $privateKey, bool $recovery): void
{
    $versionBytes = $version . PHP_EOL;
    $manifest = [
        'product' => 'Tomos',
        'from_version' => $from,
        'version' => $version,
        'files' => ['VERSION' => hash('sha256', $versionBytes)],
    ];
    if ($recovery) {
        $manifest['recovery'] = true;
    }
    $raw = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $signature = '';
    if (!is_string($raw) || !openssl_sign($raw, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('could not sign recovery package');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true
        || !$zip->addFromString('manifest.json', $raw)
        || !$zip->addFromString('manifest.sig', $signature)
        || !$zip->addFromString('files/VERSION', $versionBytes)
        || !$zip->close()
    ) {
        throw new RuntimeException('could not create recovery package');
    }
}

try {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $privateKey = '';
    if ($key === false || !openssl_pkey_export($key, $privateKey)) {
        throw new RuntimeException('could not create signing key');
    }
    $details = openssl_pkey_get_details($key);
    $publicKey = is_array($details) ? (string) ($details['key'] ?? '') : '';
    if ($publicKey === '') {
        throw new RuntimeException('could not read public key');
    }

    $root = $tmp . '/root';
    foreach (['storage/update-tmp', 'storage/update-backups', 'storage/update-logs', 'update'] as $directory) {
        mkdir($root . '/' . $directory, 0700, true);
    }
    file_put_contents($root . '/VERSION', '0.6.2' . PHP_EOL, LOCK_EX);
    file_put_contents($root . '/update/public-key.pem', $publicKey, LOCK_EX);
    $service = new UpdateService($root);

    $normal = $tmp . '/normal.zip';
    makeRecoveryPackage($normal, '0.6.2', '0.6.2', $privateKey, false);
    try {
        $service->stageDownloadedPackage($normal, 'recovery-owner', '0.6.2', '0.6.2');
        throw new RuntimeException('normal same-version package was accepted');
    } catch (UpdateException $exception) {
        if ($exception->stage() !== 'version') {
            throw new RuntimeException('normal same-version package failed at ' . $exception->stage());
        }
    }

    $recovery = $tmp . '/recovery.zip';
    makeRecoveryPackage($recovery, '0.6.2', '0.6.2', $privateKey, true);
    $summary = $service->stageDownloadedPackage($recovery, 'recovery-owner', '0.6.2', '0.6.2');
    $result = $service->apply((string) $summary['id'], 'recovery-owner');
    if (empty($result['ok']) || trim((string) file_get_contents($root . '/VERSION')) !== '0.6.2') {
        throw new RuntimeException('same-version recovery package did not apply');
    }

    $wrongCurrent = $tmp . '/wrong-current.zip';
    makeRecoveryPackage($wrongCurrent, '0.6.1', '0.6.1', $privateKey, true);
    try {
        $service->stageDownloadedPackage($wrongCurrent, 'recovery-owner', '0.6.1', '0.6.1');
        throw new RuntimeException('recovery package for another version was accepted');
    } catch (UpdateException $exception) {
        if ($exception->stage() !== 'update_sequence') {
            throw new RuntimeException('wrong-version recovery failed at ' . $exception->stage());
        }
    }

    echo 'update_recovery_manifest_check: normal same-version rejected, explicit 0.6.2 recovery applied, wrong-version recovery rejected' . PHP_EOL;
} finally {
    removeRecoveryTree($tmp);
}
