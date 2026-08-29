<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/installer/InstallManifest.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerSecurity.php';

function expectPointerCode(string $label, callable $callback, string $expected): void
{
    try {
        $callback();
    } catch (InstallManifestException $exception) {
        if ($exception->errorCode() !== $expected) {
            throw new RuntimeException($label . ' returned ' . $exception->errorCode() . ', expected ' . $expected);
        }
        return;
    }
    throw new RuntimeException($label . ' was unexpectedly accepted');
}

$version = '0.5.1';
$manifest = 'https://tomoswords.org/installer/releases/0.5.1/install-manifest.json';
$signature = 'https://tomoswords.org/installer/releases/0.5.1/install-manifest.sig';
$pointer = InstallManifest::buildPointer($version, $manifest, $signature);
InstallManifest::validatePointer($pointer);

expectPointerCode('v-prefixed manifest path', static function () use ($version, $signature): void {
    InstallManifest::buildPointer($version, 'https://tomoswords.org/installer/releases/v0.5.1/install-manifest.json', $signature);
}, 'pointer_schema');

expectPointerCode('mismatched version path', static function () use ($version, $signature): void {
    InstallManifest::buildPointer($version, 'https://tomoswords.org/installer/releases/0.5.0/install-manifest.json', $signature);
}, 'pointer_schema');

expectPointerCode('path traversal', static function () use ($version, $signature): void {
    InstallManifest::buildPointer($version, 'https://tomoswords.org/installer/releases/0.5.1/../install-manifest.json', $signature);
}, 'pointer_schema');

expectPointerCode('manifest query', static function () use ($signature): void {
    InstallerSecurity::validateUrl('https://tomoswords.org/installer/releases/0.5.1/install-manifest.json?download=1', ['tomoswords.org'], 'pointer_schema');
}, 'pointer_schema');

expectPointerCode('manifest fragment', static function (): void {
    InstallerSecurity::validateUrl('https://tomoswords.org/installer/releases/0.5.1/install-manifest.json#part', ['tomoswords.org'], 'pointer_schema');
}, 'pointer_schema');

expectPointerCode('arbitrary host', static function (): void {
    InstallerSecurity::validateUrl('https://evil.example.com/installer/releases/0.5.1/install-manifest.json', ['tomoswords.org'], 'pointer_schema');
}, 'pointer_schema');

expectPointerCode('invalid port', static function (): void {
    InstallerSecurity::validateUrl('https://tomoswords.org:8443/installer/releases/0.5.1/install-manifest.json', ['tomoswords.org'], 'pointer_schema');
}, 'pointer_schema');

echo "installer_pointer_validation_check: OK\n";
