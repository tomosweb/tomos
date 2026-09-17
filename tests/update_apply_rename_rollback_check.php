<?php

declare(strict_types=1);

namespace Tomos {
    function rename($from, $to): bool
    {
        $from = (string) $from;
        $to = (string) $to;

        if (
            !empty($GLOBALS['tomos_update_inject_rename_failure'])
            && strpos(basename($from), '.tomos-update-') === 0
        ) {
            $GLOBALS['tomos_update_target_rename_calls'] = (int) ($GLOBALS['tomos_update_target_rename_calls'] ?? 0) + 1;
            if ($GLOBALS['tomos_update_target_rename_calls'] === (int) ($GLOBALS['tomos_update_fail_on_call'] ?? 2)) {
                return false;
            }
        }

        return \rename($from, $to);
    }
}

namespace {
    require_once dirname(__DIR__) . '/core/UpdateLock.php';
    require_once dirname(__DIR__) . '/core/UpdateService.php';

    use Tomos\UpdateException;
    use Tomos\UpdateService;

    if (!class_exists(ZipArchive::class) || !function_exists('openssl_pkey_new')) {
        fwrite(STDERR, "SKIP: ZipArchive or OpenSSL unavailable.\n");
        exit(0);
    }

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-update-rename-rollback-' . bin2hex(random_bytes(6));
    foreach (['storage/update-tmp', 'storage/update-backups', 'storage/update-logs', 'update', 'assets'] as $directory) {
        mkdir($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory), 0700, true);
    }

    $oldVersion = '0.1.0-alpha.19';
    $newVersion = '0.1.0-beta.1';
    $oldIndex = "<?php\necho 'old';\n";
    $newIndex = "<?php\necho 'new';\n";
    $oldAsset = "old asset\n";
    $newAsset = "new asset\n";

    file_put_contents($root . '/VERSION', $oldVersion . "\n");
    file_put_contents($root . '/index.php', $oldIndex);
    file_put_contents($root . '/assets/a.txt', $oldAsset);

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($key === false || !openssl_pkey_export($key, $privateKey)) {
        throw new RuntimeException('test signing key could not be created');
    }
    $details = openssl_pkey_get_details($key);
    $publicKey = is_array($details) ? (string) ($details['key'] ?? '') : '';
    if ($publicKey === '') {
        throw new RuntimeException('test public key could not be read');
    }
    file_put_contents($root . '/update/public-key.pem', $publicKey);

    $zipPath = $root . '/package.zip';
    makeSignedUpdateZip($zipPath, $oldVersion, $newVersion, $privateKey, [
        'index.php' => $newIndex,
        'assets/a.txt' => $newAsset,
        'VERSION' => $newVersion . "\n",
    ]);

    try {
        foreach ([1, 2, 3] as $failureAt) {
            $service = new UpdateService($root);
            $summary = $service->stageDownloadedPackage($zipPath, 'rename-rollback-owner', $oldVersion, $newVersion);

            $GLOBALS['tomos_update_target_rename_calls'] = 0;
            $GLOBALS['tomos_update_fail_on_call'] = $failureAt;
            $GLOBALS['tomos_update_inject_rename_failure'] = true;

            $caught = null;
            try {
                $service->apply((string) $summary['id'], 'rename-rollback-owner');
            } catch (UpdateException $exception) {
                $caught = $exception;
            }

            if (!$caught instanceof UpdateException) {
                throw new RuntimeException('rename failure was not surfaced at call ' . $failureAt);
            }
            if ($caught->stage() !== 'replace') {
                throw new RuntimeException('expected replace stage, got ' . $caught->stage());
            }
            if ($caught->rollbackFailed()) {
                throw new RuntimeException('rollback unexpectedly reported failure at call ' . $failureAt);
            }
            if (($GLOBALS['tomos_update_target_rename_calls'] ?? 0) < $failureAt) {
                throw new RuntimeException('target rename was not reached at call ' . $failureAt);
            }

            assertFileBytes($root . '/index.php', $oldIndex, 'first updated file was not rolled back');
            assertFileBytes($root . '/assets/a.txt', $oldAsset, 'asset was not rolled back');
            assertFileBytes($root . '/VERSION', $oldVersion . "\n", 'VERSION changed after failed update');

            if (file_exists($root . '/storage/update.lock')) {
                throw new RuntimeException('update lock remained after rollback');
            }
            if ((glob($root . '/storage/update-tmp/*') ?: []) !== []) {
                throw new RuntimeException('update staging remained after rollback');
            }

            $GLOBALS['tomos_update_inject_rename_failure'] = false;
        }

        echo "update_apply_rename_rollback_check: OK\n";
    } finally {
        $GLOBALS['tomos_update_inject_rename_failure'] = false;
        removeTree($root);
    }

    function makeSignedUpdateZip(string $path, string $fromVersion, string $version, string $privateKey, array $files): void
    {
        $manifestFiles = [];
        foreach ($files as $relative => $bytes) {
            $manifestFiles[$relative] = hash('sha256', (string) $bytes);
        }
        $manifest = [
            'product' => 'Tomos',
            'from_version' => $fromVersion,
            'version' => $version,
            'files' => $manifestFiles,
        ];
        $manifestRaw = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($manifestRaw)) {
            throw new RuntimeException('manifest could not be encoded');
        }
        $signature = '';
        if (!openssl_sign($manifestRaw, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('manifest could not be signed');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('update ZIP could not be created');
        }
        $zip->addFromString('manifest.json', $manifestRaw);
        $zip->addFromString('manifest.sig', $signature);
        foreach ($files as $relative => $bytes) {
            $zip->addFromString('files/' . $relative, (string) $bytes);
        }
        $zip->close();
    }

    function assertFileBytes(string $path, string $expected, string $message): void
    {
        $actual = @file_get_contents($path);
        if (!is_string($actual) || !hash_equals(hash('sha256', $expected), hash('sha256', $actual))) {
            throw new RuntimeException($message);
        }
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
}
