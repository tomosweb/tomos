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
        $command .= ' ' . escapeshellarg('--' . $key . '=' . $value);
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
