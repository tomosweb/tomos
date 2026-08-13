<?php

declare(strict_types=1);

require_once __DIR__ . '/installer/InstallManifest.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', ['version:', 'manifest-url:', 'signature-url:', 'output:']);
$version = (string) ($options['version'] ?? '');
$manifestUrl = (string) ($options['manifest-url'] ?? '');
$signatureUrl = (string) ($options['signature-url'] ?? '');
$output = (string) ($options['output'] ?? '');
if ($version === '' || $manifestUrl === '' || $signatureUrl === '' || $output === '') {
    fwrite(STDERR, "Usage: php tools/build-install-latest-pointer.php --version=0.1.0-alpha.15 --manifest-url=https://example.invalid/download/install/v0.1.0-alpha.15/install-manifest.json --signature-url=https://example.invalid/download/install/v0.1.0-alpha.15/install-manifest.sig --output=build/latest.json\n");
    exit(1);
}

try {
    $pointer = InstallManifest::buildPointer($version, $manifestUrl, $signatureUrl);
    $raw = InstallManifest::encodePointer($pointer);
    if (@file_put_contents($output, $raw, LOCK_EX) === false) {
        throw new InstallManifestException('pointer_schema', 'Could not write pointer.');
    }
    echo $output . PHP_EOL;
} catch (InstallManifestException $exception) {
    fwrite(STDERR, 'ERROR[' . $exception->errorCode() . ']: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
