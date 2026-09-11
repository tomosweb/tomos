<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/tools/UpdateFileSet.php';

function failCheck(string $message): never
{
    fwrite(STDERR, "post_auth_wall_browser_update_package_check: FAIL: {$message}\n");
    exit(1);
}

function assertCheck(bool $condition, string $message): void
{
    if (!$condition) {
        failCheck($message);
    }
}

$baselineRef = '05089edafd3105025053b54436694c8b39022584';
$runtimeFiles = UpdateFileSet::fromGitDiff($root, $baselineRef, 'HEAD');

assertCheck(in_array('post/index.php', $runtimeFiles, true), 'post/index.php is selected for Browser Update');
assertCheck(in_array('post/.htaccess', $runtimeFiles, true), 'post/.htaccess is selected for Browser Update');
assertCheck(in_array('post/auth-gate.php', $runtimeFiles, true), 'post/auth-gate.php is selected for Browser Update');
assertCheck(!in_array('.htaccess', $runtimeFiles, true), 'protected root .htaccess is excluded from Browser Update');

$tmp = sys_get_temp_dir() . '/tomos-auth-wall-update-' . bin2hex(random_bytes(6));
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
    assertCheck($archiveStatus === 0, 'could not create released v0.7.1 baseline fixture');

    $baselineRootHtaccess = file_get_contents($baselineDir . '/.htaccess');
    assertCheck(is_string($baselineRootHtaccess), 'released v0.7.1 root .htaccess is available');

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    assertCheck($key !== false, 'could not create temporary signing key');
    $privateKeyPem = '';
    assertCheck(openssl_pkey_export($key, $privateKeyPem), 'could not export temporary signing key');
    assertCheck(file_put_contents($keyPath, $privateKeyPem) !== false, 'could not write temporary signing key');

    // The branch intentionally remains VERSION 0.7.1 until release work starts.
    // Use a temporary version only in this CI workspace so the real package builder
    // can exercise its normal version and signing gates without committing a release version.
    $testVersion = '0.7.2-dev';
    assertCheck(file_put_contents($root . '/VERSION', $testVersion . "\n") !== false, 'could not set temporary package version');

    $packageFiles = $runtimeFiles;
    if (!in_array('VERSION', $packageFiles, true)) {
        $packageFiles[] = 'VERSION';
    }
    sort($packageFiles);

    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/tools/build-update-package.php')
        . ' --from=0.7.1'
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
        assertCheck($zip->locateName('files/post/index.php') !== false, 'generated Update ZIP contains post/index.php');
        assertCheck($zip->locateName('files/post/.htaccess') !== false, 'generated Update ZIP contains post/.htaccess');
        assertCheck($zip->locateName('files/post/auth-gate.php') !== false, 'generated Update ZIP contains post/auth-gate.php');
        assertCheck($zip->locateName('files/.htaccess') === false, 'generated Update ZIP excludes root .htaccess');

        $manifestRaw = $zip->getFromName('manifest.json');
        assertCheck(is_string($manifestRaw), 'generated Update ZIP contains manifest.json');
        $manifest = json_decode($manifestRaw, true);
        assertCheck(is_array($manifest), 'manifest.json is valid JSON');
        $manifestFiles = $manifest['files'] ?? null;
        assertCheck(is_array($manifestFiles), 'manifest contains files map');
        assertCheck(isset($manifestFiles['post/index.php']), 'manifest includes post/index.php');
        assertCheck(isset($manifestFiles['post/.htaccess']), 'manifest includes post/.htaccess');
        assertCheck(isset($manifestFiles['post/auth-gate.php']), 'manifest includes post/auth-gate.php');
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

    assertCheck(is_file($baselineDir . '/post/.htaccess'), 'applied v0.7.0 fixture contains post/.htaccess');
    assertCheck(is_file($baselineDir . '/post/auth-gate.php'), 'applied v0.7.1 fixture contains post/auth-gate.php');
    $appliedIndex = (string) file_get_contents($baselineDir . '/post/index.php');
    $appliedPostHtaccess = (string) file_get_contents($baselineDir . '/post/.htaccess');
    assertCheck(strpos($appliedIndex, "require __DIR__ . '/auth-gate.php';") !== false, 'applied Post index owns auth wall');
    assertCheck(strpos($appliedPostHtaccess, 'RewriteEngine') === false && strpos($appliedPostHtaccess, 'RewriteCond') === false && strpos($appliedPostHtaccess, 'RewriteRule') === false && stripos($appliedPostHtaccess, 'referer') === false, 'applied post/.htaccess neutralizes obsolete routing');
    assertCheck(file_get_contents($baselineDir . '/.htaccess') === $baselineRootHtaccess, 'Browser Update leaves released v0.7.1 root .htaccess unchanged');

    echo "post_auth_wall_browser_update_package_check: PASS\n";
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
