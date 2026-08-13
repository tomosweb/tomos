<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/installer/InstallManifest.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerPublicKey.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerSecurity.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerDownloader.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerStaging.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerCore.php';

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/tomos-installer-phase2-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700, true);
mkdir($tmp . '/sessions', 0700, true);
session_save_path($tmp . '/sessions');
$passes = 0;
$failures = [];

try {
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $zipPath = $root . '/build/tomos-' . $version . '.zip';
    $baseUrl = 'https://fixture.test/download/install/v' . $version;
    $assetUrl = $baseUrl . '/tomos-' . $version . '.zip';
    $manifest = InstallManifest::buildFromZip($zipPath, $root . '/VERSION', $assetUrl);
    $manifestRaw = InstallManifest::encode($manifest);

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    $details = openssl_pkey_get_details($key);
    $publicKey = (string) $details['key'];
    openssl_sign($manifestRaw, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $artifactDir = $tmp . '/fixture';
    mkdir($artifactDir, 0700, true);
    file_put_contents($artifactDir . '/manifest.json', $manifestRaw, LOCK_EX);
    file_put_contents($artifactDir . '/manifest.sig', $signature, LOCK_EX);
    copy($zipPath, $artifactDir . '/tomos-' . $version . '.zip');
    $pointer = InstallManifest::buildPointer($version, $baseUrl . '/install-manifest.json', $baseUrl . '/install-manifest.sig');
    $pointerRaw = InstallManifest::encodePointer($pointer);
    file_put_contents($artifactDir . '/latest.json', $pointerRaw, LOCK_EX);
    $map = [
        'https://fixture.test/download/install/latest.json' => $artifactDir . '/latest.json',
        $baseUrl . '/install-manifest.json' => $artifactDir . '/manifest.json',
        $baseUrl . '/install-manifest.sig' => $artifactDir . '/manifest.sig',
        $assetUrl => $artifactDir . '/tomos-' . $version . '.zip',
    ];
    $transport = new InstallerDownloader(function (string $url, string $destination, int $maxBytes) use (&$map): array {
        if (!isset($map[$url])) {
            return ['status' => 404, 'headers' => []];
        }
        if (!copy($map[$url], $destination)) {
            return ['status' => 200, 'headers' => [], 'content_length' => 0];
        }
        return ['status' => 200, 'headers' => [], 'content_length' => filesize($destination)];
    });

    check(InstallerPublicKey::pem() === (string) file_get_contents($root . '/update/public-key.pem'), 'embedded public key matches Update public key');
    check((new InstallerCore($tmp, ['pointer_url' => 'https://fixture.test/download/install/latest.json', 'pointer_hosts' => ['fixture.test'], 'manifest_hosts' => ['fixture.test'], 'asset_hosts' => ['fixture.test'], 'public_key' => $publicKey], $transport))->diagnostics()['errors'] === [], 'core diagnostics pass');

    InstallerSecurity::startSession(true);
    $bootstrap = InstallerSecurity::bootstrap();
    $ownerSession = session_id();
    $csrf = InstallerSecurity::csrfToken();
    check((int) $bootstrap['expires_at'] > time(), 'bootstrap owner has expiry');
    InstallerSecurity::assertPostOwner($csrf);
    expectCode('invalid CSRF', 'csrf', function () use ($csrf): void {
        InstallerSecurity::assertPostOwner($csrf . 'x');
    });
    expectCode('expired bootstrap', 'bootstrap_owner', function () use ($csrf, $bootstrap): void {
        InstallerSecurity::assertPostOwner($csrf, (int) $bootstrap['expires_at'] + 1);
    });
    session_write_close();
    session_id('phase2-other-' . bin2hex(random_bytes(4)));
    session_start();
    expectCode('different session', 'bootstrap_owner', function (): void {
        InstallerSecurity::assertPostOwner('anything');
    });
    session_write_close();
    session_id($ownerSession);
    session_start();
    $csrf = InstallerSecurity::csrfToken();

    $core = new InstallerCore($tmp, [
        'pointer_url' => 'https://fixture.test/download/install/latest.json',
        'pointer_hosts' => ['fixture.test'],
        'manifest_hosts' => ['fixture.test'],
        'asset_hosts' => ['fixture.test'],
        'public_key' => $publicKey,
    ], $transport);
    $result = $core->prepare($csrf);
    check(is_dir($result['verified_staging_path']), 'verified staging exists');
    check(is_file($result['verified_staging_path'] . '/VERSION'), 'staging contains VERSION');
    check(is_file($result['verified_staging_path'] . '/index.php'), 'staging contains index.php');
    check(!is_file($tmp . '/index.php'), 'target root was not modified');
    check($result['selected_mode'] === null, 'placement mode is not selected in Phase 2');
    $core->cleanup($result['work_path']);
    check(!file_exists($result['work_path']), 'successful work cleanup removes staging');

    $badManifest = $manifest;
    $badManifest['files']['index.php']['sha256'] = str_repeat('0', 64);
    $badRaw = InstallManifest::encode($badManifest);
    openssl_sign($badRaw, $badSignature, $privateKey, OPENSSL_ALGO_SHA256);
    file_put_contents($artifactDir . '/bad-manifest.json', $badRaw, LOCK_EX);
    file_put_contents($artifactDir . '/bad-manifest.sig', $badSignature, LOCK_EX);
    $badMap = $map;
    $badBaseUrl = 'https://fixture.test/bad/v' . $version;
    $badMap[$badBaseUrl . '/install-manifest.json'] = $artifactDir . '/bad-manifest.json';
    $badMap[$badBaseUrl . '/install-manifest.sig'] = $artifactDir . '/bad-manifest.sig';
    $badPointer = InstallManifest::encodePointer(InstallManifest::buildPointer($version, $badBaseUrl . '/install-manifest.json', $badBaseUrl . '/install-manifest.sig'));
    file_put_contents($artifactDir . '/bad-latest.json', $badPointer, LOCK_EX);
    $badMap['https://fixture.test/download/install/bad-latest.json'] = $artifactDir . '/bad-latest.json';
    $badTransport = new InstallerDownloader(function (string $url, string $destination, int $maxBytes) use (&$badMap): array {
        if (!isset($badMap[$url])) {
            return ['status' => 404, 'headers' => []];
        }
        copy($badMap[$url], $destination);
        return ['status' => 200, 'headers' => [], 'content_length' => filesize($destination)];
    });
    $badCore = new InstallerCore($tmp, [
        'pointer_url' => 'https://fixture.test/download/install/bad-latest.json',
        'pointer_hosts' => ['fixture.test'], 'manifest_hosts' => ['fixture.test'], 'asset_hosts' => ['fixture.test'], 'public_key' => $publicKey,
    ], $badTransport);
    expectCode('pointer-selected tampered manifest', 'file_hash', function () use ($badCore, $csrf): void {
        $badCore->prepare($csrf);
    });
    check(count(glob($tmp . '/.tomos-installer/work-*') ?: []) === 0, 'failed preparation cleans work directory');

    expectCode('disallowed pointer host', 'pointer_download', function () use ($tmp, $publicKey, $transport, $csrf): void {
        $core = new InstallerCore($tmp, ['pointer_url' => 'https://evil.test/latest.json', 'pointer_hosts' => ['fixture.test'], 'manifest_hosts' => ['fixture.test'], 'asset_hosts' => ['fixture.test'], 'public_key' => $publicKey], $transport);
        $core->prepare($csrf);
    });

    $redirectTransport = new InstallerDownloader(function (string $url, string $destination, int $maxBytes): array {
        if ($url === 'https://fixture.test/redirect') {
            return ['status' => 302, 'headers' => [], 'location' => 'https://fixture.test/final'];
        }
        if ($url === 'https://fixture.test/final') {
            file_put_contents($destination, 'ok', LOCK_EX);
            return ['status' => 200, 'headers' => [], 'content_length' => 2];
        }
        return ['status' => 404, 'headers' => []];
    });
    $redirectFile = $tmp . '/redirect.bin';
    check($redirectTransport->download('https://fixture.test/redirect', $redirectFile, 100, ['fixture.test'], 'asset_download')['size'] === 2, 'redirect succeeds within allowlist');
    expectCode('disallowed redirect host', 'asset_download', function () use ($tmp): void {
        $transport = new InstallerDownloader(function (string $url, string $destination, int $maxBytes): array {
            return ['status' => 302, 'headers' => [], 'location' => 'https://evil.test/final'];
        });
        $transport->download('https://fixture.test/redirect', $tmp . '/bad-redirect.bin', 100, ['fixture.test'], 'asset_download');
    });

    echo "installer_phase2_check: {$passes} checks passed\n";
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
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

function removeTree(string $path): void
{
    if (is_link($path) || !is_dir($path)) {
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
