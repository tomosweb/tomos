<?php

declare(strict_types=1);

require_once __DIR__ . '/installer/InstallManifest.php';
require_once __DIR__ . '/installer/InstallerSecurity.php';
require_once __DIR__ . '/installer/InstallerDownloader.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', [
    'pointer-url:',
    'installer-url:',
    'installer-sha256:',
    'public-key:',
    'output-dir:',
    'pointer-hosts::',
    'manifest-hosts::',
    'asset-hosts::',
    'installer-hosts::',
]);
$root = dirname(__DIR__);
$pointerUrl = (string) ($options['pointer-url'] ?? '');
$installerUrl = (string) ($options['installer-url'] ?? '');
$installerHash = strtolower((string) ($options['installer-sha256'] ?? ''));
$publicKeyPath = (string) ($options['public-key'] ?? $root . '/update/public-key.pem');
$cleanupOutput = !isset($options['output-dir']);
$outputDir = (string) ($options['output-dir'] ?? sys_get_temp_dir() . '/tomos-published-verify-' . bin2hex(random_bytes(8)));
$pointerHosts = csvHosts($options['pointer-hosts'] ?? 'tomoswords.org');
$manifestHosts = csvHosts($options['manifest-hosts'] ?? 'tomoswords.org');
$assetHosts = csvHosts($options['asset-hosts'] ?? 'tomoswords.org');
$installerHosts = csvHosts($options['installer-hosts'] ?? 'tomoswords.org');

if ($pointerUrl === '' || $installerUrl === '' || !preg_match('/\A[a-f-f0-9]{64}\z/', $installerHash) || !is_file($publicKeyPath)) {
    fwrite(STDERR, "Usage: php tools/verify-published-install-assets.php --pointer-url=https://.../latest.json --installer-url=https://.../install.php --installer-sha256=64hex [--public-key=update/public-key.pem]\n");
    exit(2);
}

mkdir($outputDir, 0700, true);
$downloader = new InstallerDownloader();
try {
    InstallerSecurity::validateUrl($pointerUrl, $pointerHosts, 'pointer_schema');
    $pointerPath = $outputDir . '/latest.json';
    $downloader->download($pointerUrl, $pointerPath, InstallerDownloader::POINTER_MAX_BYTES, $pointerHosts, 'pointer_download');
    $pointer = InstallManifest::decodePointer((string) file_get_contents($pointerPath));
    InstallerSecurity::validateUrl($pointer['manifest_url'], $manifestHosts, 'manifest_schema');
    InstallerSecurity::validateUrl($pointer['signature_url'], $manifestHosts, 'manifest_schema');

    $manifestPath = $outputDir . '/install-manifest.json';
    $signaturePath = $outputDir . '/install-manifest.sig';
    $downloader->download($pointer['manifest_url'], $manifestPath, InstallerDownloader::MANIFEST_MAX_BYTES, $manifestHosts, 'manifest_download');
    $downloader->download($pointer['signature_url'], $signaturePath, InstallerDownloader::SIGNATURE_MAX_BYTES, $manifestHosts, 'signature_download');
    $manifest = InstallManifest::decodeAndValidate((string) file_get_contents($manifestPath));
    if ($manifest['version'] !== $pointer['version']) {
        throw new InstallManifestException('manifest_version', 'Pointer and manifest versions differ.');
    }
    InstallerSecurity::validateUrl($manifest['asset']['url'], $assetHosts, 'asset_host');
    $zipPath = $outputDir . '/' . $manifest['asset']['name'];
    $downloader->download($manifest['asset']['url'], $zipPath, InstallerDownloader::ZIP_MAX_BYTES, $assetHosts, 'asset_download');
    InstallManifest::verifyPackage($manifestPath, $signaturePath, $zipPath, $publicKeyPath);

    InstallerSecurity::validateUrl($installerUrl, $installerHosts, 'installer_download');
    $installerPath = $outputDir . '/install.php';
    $download = $downloader->download($installerUrl, $installerPath, 10485760, $installerHosts, 'installer_download');
    $actualInstallerHash = strtolower((string) hash_file('sha256', $installerPath));
    if (!hash_equals($installerHash, $actualInstallerHash)) {
        throw new InstallManifestException('installer_hash', 'Published installer hash differs from the expected hash.');
    }
    echo 'OK version=' . $manifest['version'] . ' zip=' . $manifest['asset']['name'] . ' installer_bytes=' . $download['size'] . PHP_EOL;
} catch (InstallManifestException $exception) {
    fwrite(STDERR, 'ERROR[' . $exception->errorCode() . ']: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($cleanupOutput) removeTree($outputDir);
}

function csvHosts($value): array
{
    $raw = is_string($value) ? $value : 'tomoswords.org';
    $hosts = array_values(array_filter(array_map('trim', explode(',', $raw)), static function (string $host): bool { return $host !== ''; }));
    return $hosts === [] ? ['tomoswords.org'] : $hosts;
}

function removeTree(string $path): void
{
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
