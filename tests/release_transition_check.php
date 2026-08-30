<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$targetVersion = trim((string) file_get_contents($root . '/VERSION'));
$fromVersion = '0.5.2';
$fromRef = '2dc3ce590bc8bd8305eb6c7733519ea5b9cd1591';
require_once $root . '/tools/UpdateFileSet.php';
// v0.5.2 is maintained in the public distribution repository. Use its
// immutable peeled commit because the dev repository intentionally does not
// publish the public release tag.
$runtimeFiles = UpdateFileSet::fromGitDiff($root, $fromRef, 'HEAD');

if ($targetVersion !== '0.5.3') {
    throw new RuntimeException('release transition check requires VERSION 0.5.3');
}
if (!version_compare($fromVersion, $targetVersion, '<')) {
    throw new RuntimeException($fromVersion . ' must compare older than ' . $targetVersion);
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

    $output = $tmp . '/tomos-update-' . $fromVersion . '-to-' . $targetVersion . '.zip';
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/tools/build-update-package.php')
        . ' ' . escapeshellarg('--from=' . $fromVersion)
        . ' ' . escapeshellarg('--version=' . $targetVersion)
        . ' ' . escapeshellarg('--private-key=' . $keyPath)
        . ' ' . escapeshellarg('--output=' . $output)
        . ' ' . escapeshellarg('--from-ref=' . $fromRef);

    $lines = [];
    $code = 0;
    exec($command . ' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException($fromVersion . ' -> ' . $targetVersion . ' update package build failed: ' . implode("\n", $lines));
    }

    $zip = new ZipArchive();
    if ($zip->open($output) !== true) {
        throw new RuntimeException($fromVersion . ' -> ' . $targetVersion . ' update package is not readable');
    }
    try {
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (!is_array($manifest)) {
            throw new RuntimeException($targetVersion . ' manifest is not valid JSON');
        }
        if (($manifest['product'] ?? null) !== 'Tomos') {
            throw new RuntimeException($targetVersion . ' manifest product mismatch');
        }
        if (($manifest['from_version'] ?? null) !== $fromVersion) {
            throw new RuntimeException($targetVersion . ' manifest from_version mismatch');
        }
        if (($manifest['version'] ?? null) !== $targetVersion) {
            throw new RuntimeException($targetVersion . ' manifest version mismatch');
        }
        if (array_key_exists('minimum_version', $manifest)) {
            throw new RuntimeException($targetVersion . ' normal update must not contain legacy minimum_version');
        }
        $manifestPaths = array_keys(is_array($manifest['files'] ?? null) ? $manifest['files'] : []);
        sort($manifestPaths);
        $expectedPaths = UpdateFileSet::packagePaths($runtimeFiles);
        sort($expectedPaths);
        if ($manifestPaths !== $expectedPaths) {
            throw new RuntimeException('manifest does not exactly cover the derived runtime update set');
        }
        foreach ($runtimeFiles as $path) {
            $packagePaths = UpdateFileSet::packagePaths([$path]);
            $payloadCandidates = array_values(array_filter($packagePaths, static fn (string $value): bool => substr($value, -4) === '.php'));
            $payloadPath = $payloadCandidates[0] ?? $path;
            $bytes = $zip->getFromName('files/' . $payloadPath);
            if (!is_string($bytes)) {
                throw new RuntimeException($targetVersion . ' package payload missing: ' . $path);
            }
            if (($manifest['files'][$payloadPath] ?? null) !== hash('sha256', $bytes)) {
                throw new RuntimeException($targetVersion . ' manifest hash mismatch: ' . $path);
            }
            if (in_array($path, ['update/index.php', 'core/UpdateService.php', 'core/UpdateLock.php'], true)) {
                $metadataCandidates = array_values(array_filter($packagePaths, static fn (string $value): bool => substr($value, -5) === '.json'));
                $metadataPath = (string) ($metadataCandidates[0] ?? '');
                $metadataBytes = $zip->getFromName('files/' . $metadataPath);
                if (!is_string($metadataBytes)) {
                    throw new RuntimeException($targetVersion . ' package pending updater metadata missing');
                }
                $metadata = json_decode($metadataBytes, true);
                if (!is_array($metadata) || ($metadata['target'] ?? null) !== $path) {
                    throw new RuntimeException($targetVersion . ' pending updater metadata target mismatch');
                }
                if (($manifest['files'][$metadataPath] ?? null) !== hash('sha256', $metadataBytes)) {
                    throw new RuntimeException($targetVersion . ' pending updater metadata hash mismatch');
                }
            }
        }
        $versionBytes = $zip->getFromName('files/VERSION');
        if (!is_string($versionBytes) || trim($versionBytes) !== $targetVersion) {
            throw new RuntimeException($targetVersion . ' package VERSION payload mismatch');
        }

        foreach ([
            'core/PostDrafts.php',
            'core/PostInbox.php',
            'core/PostInboxApi.php',
            'core/PostInboxAutoPublisher.php',
            'core/PostInboxImageStore.php',
            'core/PostInboxPreview.php',
            'core/PostUpload.php',
            'core/ImageProcessor.php',
            'core/UpdatePackageDownloader.php',
            'post/inbox/image/index.php',
            'post/index.php',
            'post/security/index.php',
        ] as $requiredV053Runtime) {
            if (!in_array($requiredV053Runtime, $runtimeFiles, true)) {
                throw new RuntimeException('required v0.5.3 runtime was not derived: ' . $requiredV053Runtime);
            }
            if (!isset($manifest['files'][$requiredV053Runtime])) {
                throw new RuntimeException('required v0.5.3 runtime is missing from manifest: ' . $requiredV053Runtime);
            }
        }
    } finally {
        $zip->close();
    }

    $releaseNote = (string) file_get_contents($root . '/docs/releases/v' . $targetVersion . '.md');
    if (strpos($releaseNote, 'v' . $fromVersion . 'からv' . $targetVersion) === false) {
        throw new RuntimeException($targetVersion . ' release note must document the exact update transition');
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
