<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$targetVersion = trim((string) file_get_contents($root . '/VERSION'));
$fromVersion = '0.3.1';
$runtimeFiles = [
    'VERSION',
    'core/App.php',
    'core/NavigationBuilder.php',
    'core/PostAuthRememberToken.php',
    'core/PostAuthReturnTo.php',
    'core/PostInboxPreview.php',
    'core/InstalledIntegrityVerifier.php',
    'core/ThemePackageDeployment.php',
    'core/ThemeSettings.php',
    'core/ThemeSettingsConfigWriter.php',
    'core/UpdateService.php',
    'core/required-installed-files.txt',
    'post/index.php',
    'post/passkey/login/index.php',
    'post/security/index.php',
    'post/settings/index.php',
    'post/theme/add/index.php',
    'post/theme/index.php',
    'themes/tomos-dark/templates/layout.html',
    'themes/tomos-journal/templates/layout.html',
    'themes/tomos-minimal/templates/layout.html',
    'themes/tomos-note/templates/layout.html',
];

if ($targetVersion !== '0.4.0') {
    throw new RuntimeException('release transition check requires VERSION 0.4.0');
}
if (!version_compare($fromVersion, $targetVersion, '<')) {
    throw new RuntimeException('0.3.1 must compare older than 0.4.0');
}

$tmp = sys_get_temp_dir() . '/tomos-release-transition-' . bin2hex(random_bytes(8));
if (!mkdir($tmp, 0700, true)) {
    throw new RuntimeException('could not create release transition fixture');
}

try {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $privateKey = '';
    if ($key === false || !openssl_pkey_export($key, $privateKey)) {
        throw new RuntimeException('could not create test signing key');
    }
    $keyPath = $tmp . '/private.pem';
    file_put_contents($keyPath, $privateKey, LOCK_EX);

    $output = $tmp . '/tomos-update-0.3.1-to-0.4.0.zip';
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/tools/build-update-package.php')
        . ' ' . escapeshellarg('--from=' . $fromVersion)
        . ' ' . escapeshellarg('--version=' . $targetVersion)
        . ' ' . escapeshellarg('--private-key=' . $keyPath)
        . ' ' . escapeshellarg('--output=' . $output);
    foreach ($runtimeFiles as $runtimeFile) {
        $command .= ' ' . escapeshellarg('--file=' . $runtimeFile);
    }

    $lines = [];
    $code = 0;
    exec($command . ' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException('0.3.1 -> 0.4.0 update package build failed: ' . implode("\n", $lines));
    }

    $zip = new ZipArchive();
    if ($zip->open($output) !== true) {
        throw new RuntimeException('0.3.1 -> 0.4.0 update package is not readable');
    }
    try {
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (!is_array($manifest)) {
            throw new RuntimeException('0.4.0 manifest is not valid JSON');
        }
        if (($manifest['product'] ?? null) !== 'Tomos') {
            throw new RuntimeException('0.4.0 manifest product mismatch');
        }
        if (($manifest['from_version'] ?? null) !== $fromVersion) {
            throw new RuntimeException('0.4.0 manifest from_version mismatch');
        }
        if (($manifest['version'] ?? null) !== $targetVersion) {
            throw new RuntimeException('0.4.0 manifest version mismatch');
        }
        if (array_key_exists('minimum_version', $manifest)) {
            throw new RuntimeException('0.4.0 normal update must not contain legacy minimum_version');
        }
        foreach ($runtimeFiles as $path) {
            $payloadPath = $path === 'core/UpdateService.php'
                ? 'core/updater-pending/update-service.php'
                : $path;
            $bytes = $zip->getFromName('files/' . $payloadPath);
            if (!is_string($bytes)) {
                throw new RuntimeException('0.4.0 package payload missing: ' . $path);
            }
            if (($manifest['files'][$payloadPath] ?? null) !== hash('sha256', $bytes)) {
                throw new RuntimeException('0.4.0 manifest hash mismatch: ' . $path);
            }
            if ($path === 'core/UpdateService.php') {
                $metadataPath = 'core/updater-pending/update-service.json';
                $metadataBytes = $zip->getFromName('files/' . $metadataPath);
                if (!is_string($metadataBytes)) {
                    throw new RuntimeException('0.4.0 package pending updater metadata missing');
                }
                $metadata = json_decode($metadataBytes, true);
                if (!is_array($metadata) || ($metadata['target'] ?? null) !== $path) {
                    throw new RuntimeException('0.4.0 pending updater metadata target mismatch');
                }
                if (($manifest['files'][$metadataPath] ?? null) !== hash('sha256', $metadataBytes)) {
                    throw new RuntimeException('0.4.0 pending updater metadata hash mismatch');
                }
            }
        }
        $versionBytes = $zip->getFromName('files/VERSION');
        if (!is_string($versionBytes) || trim($versionBytes) !== $targetVersion) {
        throw new RuntimeException('0.4.0 package VERSION payload mismatch');
        }
    } finally {
        $zip->close();
    }

    $releaseNote = (string) file_get_contents($root . '/docs/releases/v0.4.0.md');
    if (strpos($releaseNote, 'v0.3.1からv0.4.0') === false) {
        throw new RuntimeException('0.4.0 release note must document the exact update transition');
    }

    echo "release_transition_check: OK\n";
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
