<?php

declare(strict_types=1);

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
    fwrite(STDERR, "Usage: php tools/build-install-latest-pointer.php --version=0.1.0-alpha.17 --manifest-url=https://tomoswords.org/installer/releases/0.1.0-alpha.17/install-manifest.json --signature-url=https://tomoswords.org/installer/releases/0.1.0-alpha.17/install-manifest.sig --output=build/latest.json\n");
    exit(1);
}

try {
    if (preg_match('/\A[0-9A-Za-z][0-9A-Za-z.+-]{0,63}\z/', $version) !== 1) {
        throw new RuntimeException('Version is invalid.');
    }
    validateReleaseUrl($manifestUrl, $version, 'install-manifest.json');
    validateReleaseUrl($signatureUrl, $version, 'install-manifest.sig');

    $pointer = [
        'schema_version' => 1,
        'version' => $version,
        'manifest_url' => $manifestUrl,
        'signature_url' => $signatureUrl,
    ];
    $raw = json_encode($pointer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($raw)) {
        throw new RuntimeException('Could not encode pointer.');
    }
    if (@file_put_contents($output, $raw . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not write pointer.');
    }
    echo $output . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR[pointer_schema]: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

function validateReleaseUrl(string $url, string $version, string $filename): void
{
    $parts = parse_url($url);
    if (!is_array($parts)
        || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host'])
        || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
    ) {
        throw new RuntimeException('Pointer URL must be HTTPS without credentials, query, or fragment.');
    }
    $expectedSuffix = '/installer/releases/' . $version . '/' . $filename;
    $path = (string) ($parts['path'] ?? '');
    if (substr($path, -strlen($expectedSuffix)) !== $expectedSuffix) {
        throw new RuntimeException('Pointer URL does not match the installer release contract.');
    }
}
