<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$targetVersion = trim((string) file_get_contents($root . '/VERSION'));
$fromVersion = '0.1.0-alpha.19';

if ($targetVersion !== '0.1.0-beta.1') {
    throw new RuntimeException('beta1 release transition check requires VERSION 0.1.0-beta.1');
}
if (!version_compare($fromVersion, $targetVersion, '<')) {
    throw new RuntimeException('alpha.19 must compare older than beta.1');
}

$tmp = sys_get_temp_dir() . '/tomos-beta1-transition-' . bin2hex(random_bytes(8));
if (!mkdir($tmp, 0700, true)) {
    throw new RuntimeException('could not create beta1 transition fixture');
}

try {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $privateKey = '';
    if ($key === false || !openssl_pkey_export($key, $privateKey)) {
        throw new RuntimeException('could not create test signing key');
    }
    $keyPath = $tmp . '/private.pem';
    file_put_contents($keyPath, $privateKey, LOCK_EX);

    $output = $tmp . '/tomos-update-0.1.0-beta.1.zip';
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/tools/build-update-package.php')
        . ' ' . escapeshellarg('--from=' . $fromVersion)
        . ' ' . escapeshellarg('--version=' . $targetVersion)
        . ' ' . escapeshellarg('--private-key=' . $keyPath)
        . ' ' . escapeshellarg('--output=' . $output)
        . ' ' . escapeshellarg('--file=VERSION');

    $lines = [];
    $code = 0;
    exec($command . ' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException('alpha.19 -> beta.1 update package build failed: ' . implode("\n", $lines));
    }

    $zip = new ZipArchive();
    if ($zip->open($output) !== true) {
        throw new RuntimeException('beta1 update package is not readable');
    }
    try {
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (!is_array($manifest)) {
            throw new RuntimeException('beta1 manifest is not valid JSON');
        }
        if (($manifest['product'] ?? null) !== 'Tomos') {
            throw new RuntimeException('beta1 manifest product mismatch');
        }
        if (($manifest['from_version'] ?? null) !== $fromVersion) {
            throw new RuntimeException('beta1 manifest from_version mismatch');
        }
        if (($manifest['version'] ?? null) !== $targetVersion) {
            throw new RuntimeException('beta1 manifest version mismatch');
        }
        if (array_key_exists('minimum_version', $manifest)) {
            throw new RuntimeException('beta1 normal update must not contain legacy minimum_version');
        }
        $versionBytes = $zip->getFromName('files/VERSION');
        if (!is_string($versionBytes) || trim($versionBytes) !== $targetVersion) {
            throw new RuntimeException('beta1 package VERSION payload mismatch');
        }
        if (($manifest['files']['VERSION'] ?? null) !== hash('sha256', $versionBytes)) {
            throw new RuntimeException('beta1 VERSION manifest hash mismatch');
        }
    } finally {
        $zip->close();
    }

    $releaseNote = (string) file_get_contents($root . '/docs/releases/v0.1.0-beta.1.md');
    if (strpos($releaseNote, 'from_version: 0.1.0-alpha.19') === false
        || strpos($releaseNote, 'version: 0.1.0-beta.1') === false
    ) {
        throw new RuntimeException('beta1 release note must document the exact update transition');
    }

    echo "beta1_release_transition_check: OK\n";
} finally {
    removeTree($tmp);
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
