<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/tools/verify-published-install-assets.php');

function checkVerifier(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

checkVerifier(strpos($source, "stripos(\$installerResponse, 'Tomos')") !== false, 'installer response identifies Tomos');
checkVerifier(strpos($source, "trim(\$installerResponse) === ''") !== false, 'empty installer response is rejected');
checkVerifier(strpos($source, 'hash_equals($installerHash, $actualInstallerHash)') === false, 'HTTP response is not compared with source hash');
checkVerifier(strpos($source, "'installer_download'") !== false, 'installer HTTP failures remain rejected');
checkVerifier(strpos($source, 'InstallManifest::verifyPackage($manifestPath, $signaturePath, $zipPath, $publicKeyPath)') !== false, 'manifest, signature, and ZIP verification is preserved');

echo "verify_published_install_assets_check: PASS\n";
