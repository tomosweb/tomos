<?php

declare(strict_types=1);

require_once __DIR__ . '/installer/InstallManifest.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', ['manifest:', 'signature:', 'zip:', 'public-key:']);
$root = dirname(__DIR__);
$manifest = (string) ($options['manifest'] ?? '');
$signature = (string) ($options['signature'] ?? '');
$zip = (string) ($options['zip'] ?? '');
$publicKey = (string) ($options['public-key'] ?? $root . '/update/public-key.pem');
if ($manifest === '' || $signature === '' || $zip === '') {
    fwrite(STDERR, "Usage: php tools/verify-install-package.php --manifest=build/install-manifest.json --signature=build/install-manifest.sig --zip=build/tomos-0.1.0-alpha.15.zip [--public-key=update/public-key.pem]\n");
    exit(1);
}

try {
    $result = InstallManifest::verifyPackage($manifest, $signature, $zip, $publicKey);
    echo 'OK version=' . $result['version'] . ' files=' . count($result['files']) . PHP_EOL;
} catch (InstallManifestException $exception) {
    fwrite(STDERR, 'ERROR[' . $exception->errorCode() . ']: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
