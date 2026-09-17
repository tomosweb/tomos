<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdateLock.php';
require_once dirname(__DIR__) . '/core/UpdateService.php';
require_once dirname(__DIR__) . '/core/InstalledIntegrityVerifier.php';

use Tomos\InstalledIntegrityVerifier;
use Tomos\UpdateService;
use Tomos\UpdaterSelfUpdate;

$sourceRoot = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/tomos-v073-to-v090-' . bin2hex(random_bytes(8));
$fixture = $tmp . '/fixture';
$package = $tmp . '/tomos-update-0.9.0.zip';

function removeTransitionTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            removeTransitionTree($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
}

function copyTransitionTree(string $source, string $destination): void
{
    if (!is_dir($destination) && !mkdir($destination, 0700, true)) {
        throw new RuntimeException('could not create copied tree');
    }
    foreach (scandir($source) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $from = $source . DIRECTORY_SEPARATOR . $item;
        $to = $destination . DIRECTORY_SEPARATOR . $item;
        if (is_dir($from) && !is_link($from)) {
            copyTransitionTree($from, $to);
        } elseif (is_file($from) && !is_link($from) && !copy($from, $to)) {
            throw new RuntimeException('could not copy runtime dependency');
        }
    }
}

function archiveTransitionBaseline(string $sourceRoot, string $fixture): void
{
    $output = [];
    $status = 0;
    exec('git -C ' . escapeshellarg($sourceRoot) . ' archive HEAD | tar -x -C ' . escapeshellarg($fixture), $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('could not materialize v0.7.3 baseline');
    }
    foreach (['core/updater-pending', 'core/webauthn/vendor/lbuchs/webauthn', 'cache', 'storage/update-tmp', 'storage/update-backups', 'storage/update-logs', 'trash', 'content', 'themes/custom-theme'] as $directory) {
        if (!is_dir($fixture . '/' . $directory) && !mkdir($fixture . '/' . $directory, 0700, true)) {
            throw new RuntimeException('could not create baseline directory: ' . $directory);
        }
    }
}

function addTransitionEntry(ZipArchive $zip, array &$files, string $path, string $contents): void
{
    $files[$path] = hash('sha256', $contents);
    if (!$zip->addFromString('files/' . $path, $contents)) {
        throw new RuntimeException('could not add update entry: ' . $path);
    }
}

function protectedTransitionSnapshot(string $root): array
{
    $snapshot = [];
    foreach (['config.php', 'content', 'themes/custom-theme', 'cache/data.txt', 'storage/data.txt', 'trash/data.txt'] as $relative) {
        $path = $root . '/' . $relative;
        if (is_file($path)) {
            $snapshot[$relative] = hash_file('sha256', $path);
            continue;
        }
        if (!is_dir($path)) {
            throw new RuntimeException('protected fixture path missing: ' . $relative);
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $fileRelative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
                $snapshot[$fileRelative] = hash_file('sha256', $file->getPathname());
            }
        }
    }
    ksort($snapshot);
    return $snapshot;
}

if (!class_exists(ZipArchive::class) || !function_exists('openssl_sign')) {
    fwrite(STDERR, "SKIP: ZipArchive and OpenSSL are required.\n");
    exit(0);
}

try {
    if (!mkdir($fixture, 0700, true)) {
        throw new RuntimeException('could not create transition fixture');
    }
    archiveTransitionBaseline($sourceRoot, $fixture);
    if (!is_dir($sourceRoot . '/core/webauthn/vendor')) {
        throw new RuntimeException('prepare the WebAuthn runtime before this transition test');
    }
    copyTransitionTree($sourceRoot . '/core/webauthn/vendor', $fixture . '/core/webauthn/vendor');

    file_put_contents($fixture . '/config.php', "<?php return ['theme'=>['name'=>'custom-theme']];\n", LOCK_EX);
    file_put_contents($fixture . '/content/keep.md', "# Keep\n", LOCK_EX);
    file_put_contents($fixture . '/themes/custom-theme/custom.txt', "custom\n", LOCK_EX);
    file_put_contents($fixture . '/cache/data.txt', "cache-data\n", LOCK_EX);
    file_put_contents($fixture . '/storage/data.txt', "storage-data\n", LOCK_EX);
    file_put_contents($fixture . '/trash/data.txt', "trash-data\n", LOCK_EX);
    $legacy = $fixture . '/core/webauthn/vendor/lbuchs/webauthn/_test';
    mkdir($legacy, 0700, true);
    file_put_contents($legacy . '/server.php', "<?php echo 'legacy';\n", LOCK_EX);

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $private = '';
    if ($key === false || !openssl_pkey_export($key, $private)) {
        throw new RuntimeException('could not create transition signing key');
    }
    $details = openssl_pkey_get_details($key);
    if (!is_array($details) || !is_string($details['key'] ?? null)) {
        throw new RuntimeException('could not derive transition public key');
    }
    file_put_contents($fixture . '/update/public-key.pem', $details['key'], LOCK_EX);
    $privatePath = $tmp . '/private.pem';
    file_put_contents($privatePath, $private, LOCK_EX);

    $guard = "Order allow,deny\nDeny from all\nRequire all denied\n";
    $zip = new ZipArchive();
    if ($zip->open($package, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('could not create transition ZIP');
    }
    $files = [];
    addTransitionEntry($zip, $files, 'core/.htaccess', (string) file_get_contents($sourceRoot . '/core/.htaccess'));
    addTransitionEntry($zip, $files, 'core/UpdaterSelfUpdate.php', (string) file_get_contents($sourceRoot . '/core/UpdaterSelfUpdate.php'));
    addTransitionEntry($zip, $files, 'core/InstalledIntegrityVerifier.php', (string) file_get_contents($sourceRoot . '/core/InstalledIntegrityVerifier.php'));
    addTransitionEntry($zip, $files, 'core/required-installed-files.txt', (string) file_get_contents($sourceRoot . '/core/required-installed-files.txt'));
    addTransitionEntry($zip, $files, 'VERSION', "0.9.0\n");
    addTransitionEntry($zip, $files, 'core/updater-pending/update-service.php', (string) file_get_contents($sourceRoot . '/core/UpdateService.php'));
    $serviceMetadata = json_encode(['target' => 'core/UpdateService.php', 'sha256' => hash_file('sha256', $sourceRoot . '/core/UpdateService.php')], JSON_UNESCAPED_SLASHES);
    addTransitionEntry($zip, $files, 'core/updater-pending/update-service.json', (string) $serviceMetadata);
    foreach (['cache', 'storage', 'trash'] as $directory) {
        addTransitionEntry($zip, $files, 'core/updater-pending/' . $directory . '-htaccess', $guard);
        addTransitionEntry($zip, $files, 'core/updater-pending/' . $directory . '-htaccess.meta.json', (string) json_encode([
            'target' => $directory . '/.htaccess',
            'sha256' => hash('sha256', $guard),
        ], JSON_UNESCAPED_SLASHES));
    }
    ksort($files);
    $manifest = json_encode([
        'product' => 'Tomos',
        'from_version' => '0.7.3',
        'version' => '0.9.0',
        'files' => $files,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $signature = '';
    if (!is_string($manifest) || !openssl_sign($manifest, $signature, $private, OPENSSL_ALGO_SHA256)
        || !$zip->addFromString('manifest.json', $manifest)
        || !$zip->addFromString('manifest.sig', $signature)
        || !$zip->close()
    ) {
        throw new RuntimeException('could not finalize transition ZIP');
    }

    $before = protectedTransitionSnapshot($fixture);
    $oldService = new UpdateService($fixture);
    if ($oldService->currentVersion() !== '0.7.3') {
        throw new RuntimeException('fixture is not v0.7.3');
    }
    $summary = $oldService->stageDownloadedPackage($package, 'old-v073-owner', '0.7.3', '0.9.0');
    $result = $oldService->apply((string) $summary['id'], 'old-v073-owner');
    if (empty($result['ok'])) {
        throw new RuntimeException('old v0.7.3 UpdateService did not accept the v0.9.0 ZIP');
    }
    $verified = (new InstalledIntegrityVerifier($fixture))->verifyAfterUpdate($result);
    if (empty($verified['ok'])) {
        throw new RuntimeException('v0.7.3 -> v0.9.0 required-file verification failed');
    }
    if (!is_file($fixture . '/core/.htaccess') || !is_dir($fixture . '/core/updater-pending')) {
        throw new RuntimeException('normal core guard or pending bridge was not delivered');
    }

    require_once $fixture . '/core/UpdaterSelfUpdate.php';
    $final = (new UpdaterSelfUpdate($fixture))->apply();
    if (empty($final['ok']) || !is_file($fixture . '/cache/.htaccess') || !is_file($fixture . '/storage/.htaccess') || !is_file($fixture . '/trash/.htaccess')) {
        throw new RuntimeException('protected guards were not finalized');
    }
    if (is_dir($legacy) || is_link($legacy) || is_dir($fixture . '/core/updater-pending')) {
        throw new RuntimeException('legacy cleanup or pending cleanup did not complete');
    }
    if (protectedTransitionSnapshot($fixture) !== $before) {
        throw new RuntimeException('config/content/theme/cache/storage/trash data changed during migration');
    }
    $backup = glob($fixture . '/storage/update-backups/updater-*/files/core/webauthn/vendor/lbuchs/webauthn/_test/server.php');
    if (!is_array($backup) || count($backup) !== 1) {
        throw new RuntimeException('legacy _test was not retained in update backup');
    }

    echo "update_v073_to_v090_migration_check: old v0.7.3 UpdateService acceptance and finalize passed\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    removeTransitionTree($tmp);
}
