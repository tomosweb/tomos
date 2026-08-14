<?php

declare(strict_types=1);

require_once __DIR__ . '/installer/InstallManifest.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', ['manifest:', 'private-key:', 'public-key:', 'output:']);
$root = dirname(__DIR__);
$manifestPath = (string) ($options['manifest'] ?? '');
$privateKeyPath = (string) ($options['private-key'] ?? '');
$publicKeyPath = (string) ($options['public-key'] ?? $root . '/update/public-key.pem');
$output = (string) ($options['output'] ?? '');

if ($manifestPath === '' || $privateKeyPath === '') {
    fwrite(STDERR, "Usage: php tools/sign-install-manifest.php --manifest=build/install-manifest.json --private-key=/secure/private-key.pem [--public-key=update/public-key.pem] [--output=build/install-manifest.sig]\n");
    exit(1);
}
if ($output === '') {
    $output = dirname($manifestPath) . '/install-manifest.sig';
}
$realRoot = realpath($root);
$realPrivate = realpath($privateKeyPath);
if ($realPrivate !== false && $realRoot !== false && strpos($realPrivate, $realRoot . DIRECTORY_SEPARATOR) === 0) {
    fwrite(STDERR, "ERROR[private_key]: Private key must be outside the project tree.\n");
    exit(1);
}

try {
    $raw = @file_get_contents($manifestPath);
    $privateKey = @file_get_contents($privateKeyPath);
    $publicKey = @file_get_contents($publicKeyPath);
    if (!is_string($raw) || !is_string($privateKey) || !is_string($publicKey)) {
        throw new InstallManifestException('manifest_signature', 'Signature inputs are missing.');
    }
    InstallManifest::decodeAndValidate($raw);
    $signature = '';
    if (!openssl_sign($raw, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new InstallManifestException('manifest_signature', 'Could not sign manifest.');
    }
    $temporary = $output . '.tmp-' . bin2hex(random_bytes(8));
    if (@file_put_contents($temporary, $signature, LOCK_EX) === false || !@rename($temporary, $output)) {
        @unlink($temporary);
        throw new InstallManifestException('manifest_signature', 'Could not write signature.');
    }
    $written = @file_get_contents($output);
    if (!is_string($written) || openssl_verify($raw, $written, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
        throw new InstallManifestException('manifest_signature', 'Written signature failed public-key verification.');
    }
    echo $output . PHP_EOL;
} catch (InstallManifestException $exception) {
    fwrite(STDERR, 'ERROR[' . $exception->errorCode() . ']: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
