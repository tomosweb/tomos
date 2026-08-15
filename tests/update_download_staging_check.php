<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdateService.php';

use Tomos\UpdateException;
use Tomos\UpdateService;

$passes = 0;
$tmp = sys_get_temp_dir() . '/tomos-update-staging-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700, true);

function check(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

function expectUpdateError(callable $callable, string $code, string $label): void
{
    try {
        $callable();
    } catch (UpdateException $exception) {
        check($exception->stage() === $code, $label . ' expected ' . $code . ', got ' . $exception->stage());
        return;
    }
    throw new RuntimeException($label . ' did not fail');
}

function makeRoot(string $tmp, string $publicKey, string $currentVersion = '0.1.0-alpha.17'): string
{
    $root = $tmp . '/root-' . bin2hex(random_bytes(4));
    foreach (['storage/update-tmp', 'storage/update-backups', 'storage/update-logs', 'update'] as $directory) {
        mkdir($root . '/' . $directory, 0700, true);
    }
    file_put_contents($root . '/VERSION', $currentVersion . "\n");
    file_put_contents($root . '/update/public-key.pem', $publicKey);
    return $root;
}

function makeSignedZip(
    string $path,
    string $fromVersion,
    string $version,
    string $privateKey,
    bool $validSignature = true,
    string $manifestType = 'valid'
): void
{
    $versionBytes = $version . "\n";
    if ($manifestType === 'valid') {
        $manifest = [
            'product' => 'Tomos',
            'from_version' => $fromVersion,
            'version' => $version,
            'files' => ['VERSION' => hash('sha256', $versionBytes)],
        ];
    } elseif ($manifestType === 'legacy') {
        $manifest = [
            'product' => 'Tomos',
            'version' => $version,
            'minimum_version' => $fromVersion,
            'files' => ['VERSION' => hash('sha256', $versionBytes)],
        ];
    } elseif ($manifestType === 'missing_from') {
        $manifest = [
            'product' => 'Tomos',
            'version' => $version,
            'files' => ['VERSION' => hash('sha256', $versionBytes)],
        ];
    } else {
        $manifest = ['product' => 'Not Tomos'];
    }
    $manifestRaw = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $signature = '';
    openssl_sign((string) $manifestRaw, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if (!$validSignature) {
        $signature[0] = chr(ord($signature[0]) ^ 1);
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true
        || !$zip->addFromString('manifest.json', (string) $manifestRaw)
        || !$zip->addFromString('manifest.sig', $signature)
        || !$zip->addFromString('files/VERSION', $versionBytes)
        || !$zip->close()
    ) {
        throw new RuntimeException('could not build test update ZIP');
    }
}

function stagingDirectories(string $root): array
{
    return array_values(array_filter((array) glob($root . '/storage/update-tmp/*'), 'is_dir'));
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

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false || !openssl_pkey_export($key, $privateKey)) {
    throw new RuntimeException('could not create test signing key');
}
$details = openssl_pkey_get_details($key);
$publicKey = is_array($details) ? (string) ($details['key'] ?? '') : '';
if ($publicKey === '') {
    throw new RuntimeException('could not read test public key');
}

$root = makeRoot($tmp, $publicKey);
$service = new UpdateService($root);
$source = $tmp . '/downloaded-update.zip';
makeSignedZip($source, '0.1.0-alpha.17', '0.1.0-alpha.18', $privateKey);
$summary = $service->stageDownloadedPackage($source, 'owner', '0.1.0-alpha.17', '0.1.0-alpha.18');
check($summary['current_version'] === '0.1.0-alpha.17', 'successful staging returns current version');
check($summary['from_version'] === '0.1.0-alpha.17', 'successful staging returns signed manifest from version');
check($summary['version'] === '0.1.0-alpha.18', 'successful staging returns signed manifest version');
check(is_file($source), 'source remains after successful staging');
$staged = stagingDirectories($root);
check(count($staged) === 1 && is_file($staged[0] . '/package.zip'), 'downloaded ZIP uses formal package.zip staging');
check(is_file($staged[0] . '/record.json'), 'inspectStaged wrote the staging record');
removeTree($root);

$cases = [
    'expected from mismatch' => ['0.1.0-alpha.16', '0.1.0-alpha.18', '0.1.0-alpha.17', '0.1.0-alpha.18', 'update_sequence'],
    'expected version mismatch' => ['0.1.0-alpha.17', '0.1.0-alpha.19', '0.1.0-alpha.17', '0.1.0-alpha.18', 'update_sequence'],
    'manifest from skips current' => ['0.1.0-alpha.17', '0.1.0-alpha.19', '0.1.0-alpha.18', '0.1.0-alpha.19', 'update_sequence'],
    'manifest from is older than current' => ['0.1.0-alpha.18', '0.1.0-alpha.18', '0.1.0-alpha.17', '0.1.0-alpha.18', 'update_sequence'],
];
foreach ($cases as $label => $case) {
    $root = makeRoot($tmp, $publicKey, $case[0] === '0.1.0-alpha.18' ? '0.1.0-alpha.18' : '0.1.0-alpha.17');
    $service = new UpdateService($root);
    $caseSource = $tmp . '/' . bin2hex(random_bytes(4)) . '.zip';
    makeSignedZip($caseSource, $case[2], $case[3], $privateKey);
    expectUpdateError(static function () use ($service, $caseSource, $case): void {
        $service->stageDownloadedPackage($caseSource, 'owner', $case[0], $case[1]);
    }, $case[4], $label);
    check(stagingDirectories($root) === [], $label . ' removes staging directory');
    check(is_file($caseSource), $label . ' leaves source untouched');
    removeTree($root);
}

$sourceCases = [
    'missing source' => [
        'path' => $tmp . '/missing.zip',
        'code' => 'source',
    ],
    'directory source' => [
        'path' => $tmp . '/source-directory',
        'code' => 'source',
    ],
    'empty source' => [
        'path' => $tmp . '/empty.zip',
        'code' => 'source',
    ],
];
mkdir($sourceCases['directory source']['path'], 0700, true);
file_put_contents($sourceCases['empty source']['path'], '');
foreach ($sourceCases as $label => $case) {
    $root = makeRoot($tmp, $publicKey);
    $service = new UpdateService($root);
    expectUpdateError(static function () use ($service, $case): void {
        $service->stageDownloadedPackage($case['path'], 'owner', '0.1.0-alpha.17', '0.1.0-alpha.18');
    }, $case['code'], $label);
    check(stagingDirectories($root) === [], $label . ' creates no staging directory');
    removeTree($root);
}

$symlink = $tmp . '/symlink.zip';
symlink($source, $symlink);
$root = makeRoot($tmp, $publicKey);
expectUpdateError(static function () use ($root, $symlink): void {
    (new UpdateService($root))->stageDownloadedPackage($symlink, 'owner', '0.1.0-alpha.17', '0.1.0-alpha.18');
}, 'source', 'symlink source');
removeTree($root);

$unreadable = $tmp . '/unreadable.zip';
file_put_contents($unreadable, 'not readable');
chmod($unreadable, 0000);
if (!is_readable($unreadable)) {
    $root = makeRoot($tmp, $publicKey);
    expectUpdateError(static function () use ($root, $unreadable): void {
        (new UpdateService($root))->stageDownloadedPackage($unreadable, 'owner', '0.1.0-alpha.17', '0.1.0-alpha.18');
    }, 'source', 'unreadable source');
    removeTree($root);
}
chmod($unreadable, 0600);

$large = $tmp . '/large.zip';
$handle = fopen($large, 'wb');
ftruncate($handle, UpdateService::MAX_ZIP_BYTES + 1);
fclose($handle);
$root = makeRoot($tmp, $publicKey);
expectUpdateError(static function () use ($root, $large): void {
    (new UpdateService($root))->stageDownloadedPackage($large, 'owner', '0.1.0-alpha.17', '0.1.0-alpha.18');
}, 'source', 'oversized source');
removeTree($root);

$invalidCases = [
    'invalid ZIP' => static function (string $path) use ($source): void { file_put_contents($path, 'not a ZIP'); },
    'invalid signature' => static function (string $path) use ($privateKey): void { makeSignedZip($path, '0.1.0-alpha.17', '0.1.0-alpha.18', $privateKey, false); },
    'invalid manifest' => static function (string $path) use ($privateKey): void { makeSignedZip($path, '0.1.0-alpha.17', '0.1.0-alpha.18', $privateKey, true, 'invalid'); },
    'legacy minimum manifest' => static function (string $path) use ($privateKey): void { makeSignedZip($path, '0.1.0-alpha.17', '0.1.0-alpha.18', $privateKey, true, 'legacy'); },
    'missing from manifest' => static function (string $path) use ($privateKey): void { makeSignedZip($path, '0.1.0-alpha.17', '0.1.0-alpha.18', $privateKey, true, 'missing_from'); },
];
foreach ($invalidCases as $label => $builder) {
    $root = makeRoot($tmp, $publicKey);
    $service = new UpdateService($root);
    $invalidSource = $tmp . '/' . bin2hex(random_bytes(4)) . '.zip';
    $builder($invalidSource);
    expectUpdateError(static function () use ($service, $invalidSource): void {
        $service->stageDownloadedPackage($invalidSource, 'owner', '0.1.0-alpha.17', '0.1.0-alpha.18');
    }, $label === 'invalid ZIP' ? 'zip_open' : ($label === 'invalid signature' ? 'signature' : 'manifest'), $label);
    check(stagingDirectories($root) === [], $label . ' removes staging directory');
    check(is_file($invalidSource), $label . ' leaves source untouched');
    removeTree($root);
}

$orderCases = [
    'manifest from equals version' => ['0.1.0-alpha.18', '0.1.0-alpha.18'],
    'manifest from is newer than version' => ['0.1.0-alpha.19', '0.1.0-alpha.18'],
];
foreach ($orderCases as $label => $versions) {
    $root = makeRoot($tmp, $publicKey, $versions[0]);
    $service = new UpdateService($root);
    $invalidSource = $tmp . '/' . bin2hex(random_bytes(4)) . '.zip';
    makeSignedZip($invalidSource, $versions[0], $versions[1], $privateKey);
    expectUpdateError(static function () use ($service, $invalidSource, $versions): void {
        $service->stageDownloadedPackage($invalidSource, 'owner', $versions[0], $versions[1]);
    }, 'version', $label);
    check(stagingDirectories($root) === [], $label . ' removes staging directory');
    check(is_file($invalidSource), $label . ' leaves source untouched');
    removeTree($root);
}

echo "update_download_staging_check: {$passes} checks passed\n";
removeTree($tmp);
