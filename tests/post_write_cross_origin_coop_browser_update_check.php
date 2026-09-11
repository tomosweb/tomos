<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/tools/UpdateFileSet.php';

function failCheck(string $message): never
{
    fwrite(STDERR, "post_write_cross_origin_coop_browser_update_check: FAIL: {$message}\n");
    exit(1);
}

function assertCheck(bool $condition, string $message): void
{
    if (!$condition) {
        failCheck($message);
    }
}

$baselineRef = '55b5c6332af30c9a9a822a4f7b0e0ac4c97829aa';
$runtimeFiles = UpdateFileSet::fromGitDiff($root, $baselineRef, 'HEAD');

assertCheck(in_array('post/.htaccess', $runtimeFiles, true), 'post/.htaccess is selected for Browser Update from v0.7.2');
assertCheck(in_array('core/required-installed-files.txt', $runtimeFiles, true), 'installed runtime manifest is selected for Browser Update');
assertCheck(!in_array('.htaccess', $runtimeFiles, true), 'protected root .htaccess remains excluded from Browser Update');

$postHtaccess = (string) file_get_contents($root . '/post/.htaccess');
assertCheck(strpos($postHtaccess, 'Cross-Origin-Opener-Policy') !== false, 'post/.htaccess sets Cross-Origin-Opener-Policy');
assertCheck(strpos($postHtaccess, 'same-origin-allow-popups') !== false, 'post/.htaccess preserves cross-origin opener for Tomos Write');
assertCheck(strpos($postHtaccess, 'RewriteEngine') === false, 'post/.htaccess does not restore RewriteEngine');
assertCheck(strpos($postHtaccess, 'RewriteCond') === false, 'post/.htaccess does not restore RewriteCond');
assertCheck(strpos($postHtaccess, 'RewriteRule') === false, 'post/.htaccess does not restore RewriteRule');
assertCheck(stripos($postHtaccess, 'referer') === false, 'post/.htaccess does not restore Referer routing');

$installedManifest = file($root . '/core/required-installed-files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$distributionManifest = file($root . '/tools/required-distribution-files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
assertCheck(in_array('post/.htaccess', $installedManifest, true), 'installed manifest requires post/.htaccess');
assertCheck(in_array('post/.htaccess', $distributionManifest, true), 'distribution manifest requires post/.htaccess');

$tmp = sys_get_temp_dir() . '/tomos-write-coop-update-' . bin2hex(random_bytes(6));
$baselineDir = $tmp . '/baseline';
$zipPath = $tmp . '/update.zip';
$keyPath = $tmp . '/private.pem';
mkdir($baselineDir, 0777, true);

$originalVersion = file_get_contents($root . '/VERSION');
if (!is_string($originalVersion)) {
    failCheck('could not read VERSION');
}

try {
    $archiveCmd = 'git -C ' . escapeshellarg($root)
        . ' archive ' . escapeshellarg($baselineRef)
        . ' | tar -x -C ' . escapeshellarg($baselineDir);
    passthru($archiveCmd, $archiveStatus);
    assertCheck($archiveStatus === 0, 'could not create released v0.7.2 baseline fixture');

    $baselineRootHtaccess = file_get_contents($baselineDir . '/.htaccess');
    assertCheck(is_string($baselineRootHtaccess), 'released v0.7.2 root .htaccess is available');

    $baselinePostHtaccess = file_get_contents($baselineDir . '/post/.htaccess');
    assertCheck(is_string($baselinePostHtaccess), 'released v0.7.2 post/.htaccess is available');
    assertCheck(strpos($baselinePostHtaccess, 'same-origin-allow-popups') === false, 'v0.7.2 baseline does not already contain COOP compatibility header');

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    assertCheck($key !== false, 'could not create temporary signing key');
    $privateKeyPem = '';
    assertCheck(openssl_pkey_export($key, $privateKeyPem), 'could not export temporary signing key');
    assertCheck(file_put_contents($keyPath, $privateKeyPem) !== false, 'could not write temporary signing key');

    $testVersion = '0.7.3-dev';
    assertCheck(file_put_contents($root . '/VERSION', $testVersion . "\n") !== false, 'could not set temporary package version');

    $packageFiles = $runtimeFiles;
    if (!in_array('VERSION', $packageFiles, true)) {
        $packageFiles[] = 'VERSION';
    }
    sort($packageFiles);

    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/tools/build-update-package.php')
        . ' --from=0.7.2'
        . ' --version=' . escapeshellarg($testVersion)
        . ' --private-key=' . escapeshellarg($keyPath)
        . ' --output=' . escapeshellarg($zipPath);
    foreach ($packageFiles as $relative) {
        $command .= ' --file=' . escapeshellarg($relative);
    }

    passthru($command, $buildStatus);
    assertCheck($buildStatus === 0, 'real Update package builder failed');
    assertCheck(is_file($zipPath), 'Update ZIP was not created');

    $zip = new ZipArchive();
    assertCheck($zip->open($zipPath) === true, 'could not open generated Update ZIP');
    try {
        assertCheck($zip->locateName('files/post/.htaccess') !== false, 'generated Update ZIP contains post/.htaccess');
        assertCheck($zip->locateName('files/core/required-installed-files.txt') !== false, 'generated Update ZIP contains installed runtime manifest');
        assertCheck($zip->locateName('files/.htaccess') === false, 'generated Update ZIP excludes root .htaccess');

        $packagedHtaccess = $zip->getFromName('files/post/.htaccess');
        assertCheck(is_string($packagedHtaccess), 'could not read packaged post/.htaccess');
        assertCheck(strpos($packagedHtaccess, 'Header always set Cross-Origin-Opener-Policy "same-origin-allow-popups"') !== false, 'packaged post/.htaccess contains required COOP header');
        assertCheck(strpos($packagedHtaccess, 'RewriteEngine') === false && strpos($packagedHtaccess, 'RewriteCond') === false && strpos($packagedHtaccess, 'RewriteRule') === false, 'packaged post/.htaccess contains no auth routing');

        $manifestRaw = $zip->getFromName('manifest.json');
        assertCheck(is_string($manifestRaw), 'generated Update ZIP contains manifest.json');
        $manifest = json_decode($manifestRaw, true);
        assertCheck(is_array($manifest), 'manifest.json is valid JSON');
        $manifestFiles = $manifest['files'] ?? null;
        assertCheck(is_array($manifestFiles), 'manifest contains files map');
        assertCheck(isset($manifestFiles['post/.htaccess']), 'manifest includes post/.htaccess');
        assertCheck(isset($manifestFiles['core/required-installed-files.txt']), 'manifest includes installed runtime manifest');
        assertCheck(!isset($manifestFiles['.htaccess']), 'manifest excludes root .htaccess');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || strpos($name, 'files/') !== 0 || substr($name, -1) === '/') {
                continue;
            }
            $relative = substr($name, 6);
            $target = $baselineDir . '/' . $relative;
            $parent = dirname($target);
            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            $contents = $zip->getFromIndex($i);
            assertCheck(is_string($contents), 'could not read package entry: ' . $name);
            assertCheck(file_put_contents($target, $contents) !== false, 'could not apply package entry: ' . $relative);
        }
    } finally {
        $zip->close();
    }

    $appliedPostHtaccess = (string) file_get_contents($baselineDir . '/post/.htaccess');
    assertCheck(strpos($appliedPostHtaccess, 'same-origin-allow-popups') !== false, 'Browser Update replaces v0.7.2 tombstone with functional COOP header');
    assertCheck(strpos($appliedPostHtaccess, 'RewriteEngine') === false && strpos($appliedPostHtaccess, 'RewriteCond') === false && strpos($appliedPostHtaccess, 'RewriteRule') === false, 'applied post/.htaccess remains routing-free');
    assertCheck(file_get_contents($baselineDir . '/.htaccess') === $baselineRootHtaccess, 'Browser Update leaves root .htaccess unchanged');

    echo "post_write_cross_origin_coop_browser_update_check: PASS\n";
} finally {
    file_put_contents($root . '/VERSION', $originalVersion);
    if (is_dir($tmp)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($tmp);
    }
}
