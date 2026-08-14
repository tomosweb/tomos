<?php

declare(strict_types=1);

require_once __DIR__ . '/installer/InstallManifest.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', ['zip:', 'version-file:', 'asset-url:', 'output:', 'version:']);
$root = dirname(__DIR__);
$zip = (string) ($options['zip'] ?? '');
$versionFile = (string) ($options['version-file'] ?? $root . '/VERSION');
$assetUrl = (string) ($options['asset-url'] ?? '');
$output = (string) ($options['output'] ?? '');
$expectedVersion = isset($options['version']) ? (string) $options['version'] : null;

if ($zip === '' || $assetUrl === '' || $output === '') {
    fwrite(STDERR, "Usage: php tools/build-install-manifest.php --zip=build/tomos-0.1.0-alpha.15.zip --asset-url=https://example.invalid/download/install/v0.1.0-alpha.15/tomos-0.1.0-alpha.15.zip --output=build/install-manifest.json [--version-file=VERSION]\n");
    exit(1);
}

try {
    $manifest = InstallManifest::buildFromZip($zip, $versionFile, $assetUrl, $expectedVersion);
    $raw = InstallManifest::encode($manifest);
    if (@file_put_contents($output, $raw, LOCK_EX) === false) {
        throw new InstallManifestException('manifest_schema', 'Could not write manifest.');
    }
    echo $output . PHP_EOL;
} catch (InstallManifestException $exception) {
    fwrite(STDERR, 'ERROR[' . $exception->errorCode() . ']: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
