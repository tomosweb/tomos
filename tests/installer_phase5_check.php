<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/installer/InstallManifest.php';

$root = dirname(__DIR__);
$version = trim((string) file_get_contents($root . '/VERSION'));
$candidate = getenv('TOMOS_PHASE5_CANDIDATE_DIR') ?: $root . '/build/release-candidate';
$publicKey = getenv('TOMOS_PHASE5_PUBLIC_KEY') ?: $root . '/update/public-key.pem';
$passes = 0;

try {
    $files = [
        'tomos-' . $version . '.zip',
        'install-manifest.json',
        'install-manifest.sig',
        'install.php',
        'latest.json',
        'SHA256SUMS',
    ];
    foreach ($files as $file) check(is_file($candidate . '/' . $file), 'candidate contains ' . $file);
    $manifest = json_decode((string) file_get_contents($candidate . '/install-manifest.json'), true);
    $pointer = InstallManifest::decodePointer((string) file_get_contents($candidate . '/latest.json'));
    check(is_array($manifest), 'candidate manifest is JSON');
    check($manifest['version'] === $version, 'manifest version matches VERSION');
    check($manifest['asset']['name'] === 'tomos-' . $version . '.zip', 'manifest asset name matches VERSION');
    check($pointer['version'] === $version, 'pointer version matches VERSION');
    check(strpos($pointer['manifest_url'], '/v' . $version . '/install-manifest.json') !== false, 'pointer uses versioned manifest URL');
    check(strpos($pointer['signature_url'], '/v' . $version . '/install-manifest.sig') !== false, 'pointer uses versioned signature URL');
    check(strpos((string) file_get_contents($candidate . '/install.php'), 'require_once') === false, 'installer has no external require');
    check(strpos((string) file_get_contents($candidate . '/install.php'), 'fixture.test') === false, 'installer has no fixture URL');
    check(strpos((string) file_get_contents($candidate . '/install.php'), '-----BEGIN PRIVATE KEY-----') === false, 'installer has no private key');
    InstallManifest::verifyPackage(
        $candidate . '/install-manifest.json',
        $candidate . '/install-manifest.sig',
        $candidate . '/' . $manifest['asset']['name'],
        $publicKey
    );
    check(true, 'production-key package verification succeeds');
    echo "installer_phase5_check: {$passes} checks passed\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

function check(bool $condition, string $label): void
{
    global $passes;
    if (!$condition) throw new RuntimeException($label);
    $passes++;
}
