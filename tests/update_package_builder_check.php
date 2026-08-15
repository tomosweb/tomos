<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/tomos-update-builder-' . bin2hex(random_bytes(8));
if (!mkdir($tmp, 0700, true)) {
    throw new RuntimeException('could not create test directory');
}

$passes = 0;

function check(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

function runBuilder(string $root, string $tmp, array $arguments): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/build-update-package.php');
    foreach ($arguments as $key => $value) {
        $command .= $value === true
            ? ' ' . escapeshellarg('--' . $key)
            : ' ' . escapeshellarg('--' . $key . '=' . $value);
    }
    $lines = [];
    $code = 0;
    exec($command . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
}

function removeTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        removeTree($path . '/' . $item);
    }
    @rmdir($path);
}

try {
    $privateKey = '';
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($key === false || !openssl_pkey_export($key, $privateKey)) {
        throw new RuntimeException('could not create test signing key');
    }
    $privateKeyPath = $tmp . '/private.pem';
    file_put_contents($privateKeyPath, $privateKey, LOCK_EX);

    $output = $tmp . '/tomos-update-0.1.0-alpha.17.zip';
    [$code, $outputText] = runBuilder($root, $tmp, [
        'from' => '0.1.0-alpha.16',
        'version' => '0.1.0-alpha.17',
        'private-key' => $privateKeyPath,
        'output' => $output,
        'file' => 'VERSION',
    ]);
    check($code === 0, 'builder accepts a valid from/version pair: ' . $outputText);

    $zip = new ZipArchive();
    check($zip->open($output) === true, 'builder creates a readable ZIP');
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    check(is_array($manifest), 'builder writes JSON manifest');
    check(($manifest['from_version'] ?? null) === '0.1.0-alpha.16', 'manifest contains from_version');
    check(($manifest['version'] ?? null) === '0.1.0-alpha.17', 'manifest contains target version');
    check(!array_key_exists('minimum_version', $manifest), 'manifest does not contain legacy minimum_version');
    $zip->close();

    $bridgeOutput = $tmp . '/tomos-update-0.1.0-alpha.17-bridge.zip';
    [$code, $outputText] = runBuilder($root, $tmp, [
        'from' => '0.1.0-alpha.16',
        'version' => '0.1.0-alpha.17',
        'legacy-bridge' => true,
        'private-key' => $privateKeyPath,
        'output' => $bridgeOutput,
        'file' => 'VERSION',
    ]);
    check($code === 0, 'builder accepts explicit legacy bridge: ' . $outputText);
    $bridgeZip = new ZipArchive();
    check($bridgeZip->open($bridgeOutput) === true, 'builder creates a readable bridge ZIP');
    $bridgeManifest = json_decode((string) $bridgeZip->getFromName('manifest.json'), true);
    check(is_array($bridgeManifest), 'bridge ZIP contains JSON manifest');
    check(($bridgeManifest['from_version'] ?? null) === '0.1.0-alpha.16', 'bridge manifest contains from_version');
    check(($bridgeManifest['minimum_version'] ?? null) === '0.1.0-alpha.16', 'bridge minimum_version equals from_version');
    check(($bridgeManifest['version'] ?? null) === '0.1.0-alpha.17', 'bridge manifest contains target version');
    check(is_array($bridgeManifest['files'] ?? null), 'bridge manifest contains files');
    check(($bridgeManifest['product'] ?? null) === 'Tomos', 'bridge manifest has Tomos product');
    $bridgeZip->close();

    check(
        ($bridgeManifest['product'] ?? null) === 'Tomos'
            && is_string($bridgeManifest['version'] ?? null)
            && is_string($bridgeManifest['minimum_version'] ?? null)
            && is_array($bridgeManifest['files'] ?? null),
        'bridge manifest retains the alpha.17 legacy-required fields'
    );

    foreach ([
        'missing from' => ['minimum' => '0.1.0-alpha.16'],
        'from equals version' => ['from' => '0.1.0-alpha.17', 'version' => '0.1.0-alpha.17'],
        'from is newer' => ['from' => '0.1.0-alpha.18', 'version' => '0.1.0-alpha.17'],
        'invalid from' => ['from' => 'not-a-version', 'version' => '0.1.0-alpha.17'],
    ] as $label => $arguments) {
        $arguments['private-key'] = $privateKeyPath;
        $arguments['output'] = $tmp . '/' . bin2hex(random_bytes(4)) . '.zip';
        $arguments['file'] = 'VERSION';
        [$code] = runBuilder($root, $tmp, $arguments);
        check($code !== 0, $label . ' is rejected');
    }
} finally {
    removeTree($tmp);
}

echo "update_package_builder_check: {$passes} checks passed\n";
