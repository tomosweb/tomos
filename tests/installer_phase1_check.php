<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/installer/InstallManifest.php';

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/tomos-installer-phase1-' . bin2hex(random_bytes(8));
if (!mkdir($tmp, 0700, true)) {
    fwrite(STDERR, "Could not create test directory.\n");
    exit(1);
}

$passes = 0;
$failures = [];
$normalZip = $root . '/build/tomos-' . trim((string) file_get_contents($root . '/VERSION')) . '.zip';
$versionFile = $root . '/VERSION';

try {
    if (!is_file($normalZip)) {
        throw new RuntimeException('normal distribution ZIP is missing: ' . $normalZip);
    }
    $version = trim((string) file_get_contents($versionFile));
    $assetUrl = 'https://example.invalid/installer/releases/' . $version . '/tomos-' . $version . '.zip';
    $manifestPath = $tmp . '/install-manifest.json';
    $signaturePath = $tmp . '/install-manifest.sig';
    $publicKeyPath = $tmp . '/TEST-ONLY-public.pem';
    $privateKeyPath = $tmp . '/TEST-ONLY-private.pem';

    $manifest = InstallManifest::buildFromZip($normalZip, $versionFile, $assetUrl);
    $raw = InstallManifest::encode($manifest);
    file_put_contents($manifestPath, $raw, LOCK_EX);
    $manifestAgain = InstallManifest::encode(InstallManifest::buildFromZip($normalZip, $versionFile, $assetUrl));
    check($raw === $manifestAgain, 'same ZIP produces byte-identical manifest');
    check(isset($manifest['files']['.htaccess']), 'hidden .htaccess is in inventory');
    check(count($manifest['files']) > 0 && count($manifest['files']) <= InstallManifest::MAX_ENTRIES, 'normal ZIP inventory is within entry limit');
    check((int) $manifest['limits']['max_entries'] === 500, 'manifest max_entries is fixed');

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($key === false || !openssl_pkey_export($key, $privateKey)) {
        throw new RuntimeException('could not create test key');
    }
    $details = openssl_pkey_get_details($key);
    if (!is_array($details) || !is_string($details['key'] ?? null)) {
        throw new RuntimeException('could not read test public key');
    }
    file_put_contents($privateKeyPath, $privateKey, LOCK_EX);
    file_put_contents($publicKeyPath, $details['key'], LOCK_EX);
    $signature = '';
    if (!openssl_sign($raw, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('could not sign test manifest');
    }
    file_put_contents($signaturePath, $signature, LOCK_EX);
    check(openssl_verify($raw, $signature, $details['key'], OPENSSL_ALGO_SHA256) === 1, 'signature verifies with test public key');
    check(InstallManifest::verifyPackage($manifestPath, $signaturePath, $normalZip, $publicKeyPath)['version'] === $version, 'normal package verifies');

    $mutatedManifest = $manifest;
    $mutatedManifest['files']['index.php']['sha256'] = str_repeat('0', 64);
    $mutatedRaw = InstallManifest::encode($mutatedManifest);
    $mutatedManifestPath = $tmp . '/mutated-manifest.json';
    $mutatedSignaturePath = $tmp . '/mutated-manifest.sig';
    file_put_contents($mutatedManifestPath, $mutatedRaw, LOCK_EX);
    openssl_sign($mutatedRaw, $mutatedSignature, $privateKey, OPENSSL_ALGO_SHA256);
    file_put_contents($mutatedSignaturePath, $mutatedSignature, LOCK_EX);
    expectCode('file hash mismatch', 'file_hash', function () use ($mutatedManifestPath, $mutatedSignaturePath, $normalZip, $publicKeyPath): void {
        InstallManifest::verifyPackage($mutatedManifestPath, $mutatedSignaturePath, $normalZip, $publicKeyPath);
    });
    expectCode('manifest signature mutation', 'manifest_signature', function () use ($manifestPath, $signaturePath, $normalZip, $publicKeyPath): void {
        $broken = (string) file_get_contents($signaturePath);
        $broken[0] = chr(ord($broken[0]) ^ 1);
        $path = dirname($signaturePath) . '/broken.sig';
        file_put_contents($path, $broken, LOCK_EX);
        InstallManifest::verifyPackage($manifestPath, $path, $normalZip, $publicKeyPath);
    });
    $unknownSchema = $manifest;
    $unknownSchema['schema_version'] = 99;
    expectCode('unknown schema', 'manifest_schema', function () use ($unknownSchema): void {
        InstallManifest::validateManifest($unknownSchema);
    });
    $wrongVersion = $manifest;
    $wrongVersion['version'] = '0.0.0';
    expectCode('VERSION mismatch schema', 'manifest_schema', function () use ($wrongVersion): void {
        InstallManifest::validateManifest($wrongVersion);
    });

    $mutatedZipDir = $tmp . '/mutated-asset';
    mkdir($mutatedZipDir, 0700, true);
    $mutatedZip = $mutatedZipDir . '/tomos-' . $version . '.zip';
    $zipBytes = (string) file_get_contents($normalZip);
    $replacementVersion = preg_replace('/[0-9]/', '0', $version);
    $zipBytes = str_replace($version, (string) $replacementVersion, $zipBytes);
    file_put_contents($mutatedZip, $zipBytes, LOCK_EX);
    expectCode('ZIP hash mismatch', 'asset_hash', function () use ($manifestPath, $signaturePath, $mutatedZip, $publicKeyPath): void {
        InstallManifest::verifyPackage($manifestPath, $signaturePath, $mutatedZip, $publicKeyPath);
    });

    foreach ([
        ['traversal', '../escape.txt', 'zip_path'],
        ['absolute path', '/escape.txt', 'zip_path'],
        ['Windows drive path', 'C:/escape.txt', 'zip_path'],
        // ZipArchive truncates a NUL-bearing entry name before exposing it; the
        // resulting ZIP is still rejected before manifest creation.
        ['NUL path', "nul\0.txt", 'required_file'],
    ] as $case) {
        $caseDir = $tmp . '/bad-' . $case[0];
        mkdir($caseDir, 0700, true);
        $path = $caseDir . '/tomos-' . $version . '.zip';
        makeZip($path, [(string) $case[1] => 'x']);
        expectCode($case[0], (string) $case[2], function () use ($path, $versionFile, $assetUrl): void {
            InstallManifest::buildFromZip($path, $versionFile, 'https://example.invalid/bad.zip');
        });
    }
    $symlinkDir = $tmp . '/bad-symlink';
    mkdir($symlinkDir, 0700, true);
    $symlinkZip = $symlinkDir . '/tomos-' . $version . '.zip';
    makeSymlinkZip($symlinkZip);
    expectCode('symlink', 'zip_symlink', function () use ($symlinkZip, $versionFile): void {
        InstallManifest::buildFromZip($symlinkZip, $versionFile, 'https://example.invalid/bad.zip');
    });
    $tooManyDir = $tmp . '/bad-entry-count';
    mkdir($tooManyDir, 0700, true);
    $tooManyZip = $tooManyDir . '/tomos-' . $version . '.zip';
    $entries = [];
    for ($i = 0; $i < 501; $i++) {
        $entries['file-' . $i . '.txt'] = 'x';
    }
    makeZip($tooManyZip, $entries);
    expectCode('entry count', 'zip_limits', function () use ($tooManyZip, $versionFile): void {
        InstallManifest::buildFromZip($tooManyZip, $versionFile, 'https://example.invalid/bad.zip');
    });
    $largeDir = $tmp . '/bad-file-size';
    mkdir($largeDir, 0700, true);
    $largeZip = $largeDir . '/tomos-' . $version . '.zip';
    $largeFile = $largeDir . '/large.bin';
    $largeHandle = fopen($largeFile, 'wb');
    fwrite($largeHandle, str_repeat('x', InstallManifest::MAX_FILE_BYTES + 1));
    fclose($largeHandle);
    $largeArchive = new ZipArchive();
    $largeArchive->open($largeZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $largeArchive->addFile($largeFile, 'large.bin');
    $largeArchive->close();
    expectCode('file size limit', 'zip_limits', function () use ($largeZip, $versionFile): void {
        InstallManifest::buildFromZip($largeZip, $versionFile, 'https://example.invalid/bad.zip');
    });
    $totalDir = $tmp . '/bad-total-size';
    mkdir($totalDir, 0700, true);
    $totalZip = $totalDir . '/tomos-' . $version . '.zip';
    $oneMiBPath = $totalDir . '/one-mib.bin';
    $oneMiBHandle = fopen($oneMiBPath, 'wb');
    fwrite($oneMiBHandle, str_repeat('z', 1048576));
    fclose($oneMiBHandle);
    $totalArchive = new ZipArchive();
    $totalArchive->open($totalZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    for ($i = 0; $i < 101; $i++) {
        $totalArchive->addFile($oneMiBPath, 'part-' . $i . '.bin');
    }
    $totalArchive->close();
    expectCode('total size limit', 'zip_limits', function () use ($totalZip, $versionFile): void {
        InstallManifest::buildFromZip($totalZip, $versionFile, 'https://example.invalid/bad.zip');
    });

    $pointer = InstallManifest::buildPointer(
        $version,
        'https://example.invalid/installer/releases/' . $version . '/install-manifest.json',
        'https://example.invalid/installer/releases/' . $version . '/install-manifest.sig'
    );
    InstallManifest::validatePointer($pointer);
    check(InstallManifest::encodePointer($pointer) === InstallManifest::encodePointer($pointer), 'pointer output is reproducible');
    expectCode('pointer schema', 'pointer_schema', function () use ($version): void {
        InstallManifest::buildPointer($version, 'http://example.invalid/manifest.json', 'https://example.invalid/v' . $version . '/install-manifest.sig');
    });
    expectCode('pointer URL structure', 'pointer_schema', function () use ($version): void {
        InstallManifest::buildPointer($version, 'https://example.invalid/manifest.json', 'https://example.invalid/signature.sig');
    });
    echo "installer_phase1_check: {$passes} checks passed\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    $failures[] = $exception->getMessage();
} finally {
    removeTree($tmp);
}

if ($failures !== []) {
    exit(1);
}

function check(bool $condition, string $label): void
{
    global $passes, $failures;
    if ($condition) {
        $passes++;
        return;
    }
    $failures[] = $label;
    throw new RuntimeException($label);
}

function expectCode(string $label, string $expected, callable $action): void
{
    try {
        $action();
    } catch (InstallManifestException $exception) {
        check($exception->errorCode() === $expected, $label . ' expected ' . $expected . ', got ' . $exception->errorCode());
        return;
    }
    check(false, $label . ' did not fail');
}

function makeZip(string $path, array $entries): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('could not create test ZIP');
    }
    foreach ($entries as $name => $contents) {
        if (!$zip->addFromString((string) $name, (string) $contents)) {
            $zip->close();
            throw new RuntimeException('could not add test ZIP entry');
        }
    }
    if (!$zip->close()) {
        throw new RuntimeException('could not finalize test ZIP');
    }
}

function makeSymlinkZip(string $path): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true
        || !$zip->addFromString('link', 'target')
        || !$zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16)
        || !$zip->close()
    ) {
        throw new RuntimeException('could not create symlink test ZIP');
    }
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
        removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
