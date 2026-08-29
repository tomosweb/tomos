<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}
if (!class_exists(ZipArchive::class) || !function_exists('openssl_sign')) {
    fwrite(STDERR, "ZipArchive and OpenSSL are required.\n");
    exit(1);
}

$options = getopt('', ['from:', 'version:', 'legacy-bridge', 'private-key:', 'output:', 'file:', 'from-ref:', 'to-ref:']);
$from = trim((string) ($options['from'] ?? ''));
$version = trim((string) ($options['version'] ?? ''));
$legacyBridge = array_key_exists('legacy-bridge', $options);
$privateKeyPath = (string) ($options['private-key'] ?? '');
$outputPath = (string) ($options['output'] ?? '');
$files = $options['file'] ?? [];
$files = is_array($files) ? $files : [$files];
$fromRef = trim((string) ($options['from-ref'] ?? ''));
$toRef = trim((string) ($options['to-ref'] ?? 'HEAD'));
$rootDir = dirname(__DIR__);
$updateFileSetPath = __DIR__ . DIRECTORY_SEPARATOR . 'UpdateFileSet.php';
if (!is_file($updateFileSetPath)) {
    fwrite(STDERR, "Missing update file set helper.\n");
    exit(1);
}
require_once $updateFileSetPath;
$requiredFilesPath = __DIR__ . DIRECTORY_SEPARATOR . 'required-source-files.txt';

if ($from === '' || $version === '' || $privateKeyPath === '' || $outputPath === '' || ($files === [] && $fromRef === '') || ($files !== [] && $fromRef !== '')) {
    fwrite(STDERR, "Usage: php tools/build-update-package.php --from=0.1.0-alpha.17 --version=0.1.0-alpha.18 [--legacy-bridge] --private-key=/safe/private.pem --output=/path/update.zip --file=core/File.php --file=VERSION OR --from-ref=v0.3.1 [--to-ref=HEAD]\n");
    exit(1);
}
if ($fromRef !== '') {
    $files = UpdateFileSet::fromGitDiff($rootDir, $fromRef, $toRef);
    if ($files === []) {
        fwrite(STDERR, "The selected source refs contain no updateable runtime changes.\n");
        exit(1);
    }
}
if (!isValidTomosVersion($from) || !isValidTomosVersion($version)) {
    fwrite(STDERR, "Both --from and --version must use a valid Tomos version.\n");
    exit(1);
}
if (version_compare($from, $version, '>=')) {
    fwrite(STDERR, "--from must be lower than --version.\n");
    exit(1);
}
if (!is_file($privateKeyPath) || realpath($privateKeyPath) !== false && strpos((string) realpath($privateKeyPath), $rootDir . DIRECTORY_SEPARATOR) === 0) {
    fwrite(STDERR, "The private key must exist outside the Tomos project tree.\n");
    exit(1);
}

if (!is_file($requiredFilesPath) || !is_readable($requiredFilesPath)) {
    fwrite(STDERR, "Missing required file list: tools/required-source-files.txt\n");
    exit(1);
}
$requiredFiles = file($requiredFilesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!is_array($requiredFiles) || $requiredFiles === []) {
    fwrite(STDERR, "The required source file list is empty.\n");
    exit(1);
}
foreach ($requiredFiles as $requiredFile) {
    $requiredFile = trim((string) $requiredFile);
    if ($requiredFile === '') {
        continue;
    }
    $requiredPath = $rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $requiredFile);
    if (!is_file($requiredPath) || is_link($requiredPath)) {
        fwrite(STDERR, "Missing or unsafe required source file: {$requiredFile}\n");
        exit(1);
    }
}

$manifestFiles = [];
$packageFiles = [];
$generatedFiles = [];
$requestedFiles = [];
$pendingTargets = [
    'update/index.php' => [
        'pending' => 'core/updater-pending/update-index.php',
        'metadata' => 'core/updater-pending/update-index.json',
    ],
    'core/UpdateService.php' => [
        'pending' => 'core/updater-pending/update-service.php',
        'metadata' => 'core/updater-pending/update-service.json',
    ],
    'core/UpdateLock.php' => [
        'pending' => 'core/updater-pending/update-lock.php',
        'metadata' => 'core/updater-pending/update-lock.json',
    ],
];
foreach ($files as $relative) {
    $relative = (string) $relative;
    if (isset($requestedFiles[$relative])) {
        fwrite(STDERR, "Duplicate update path: {$relative}\n");
        exit(1);
    }
    $requestedFiles[$relative] = true;
    if (isset($pendingTargets[$relative])) {
        $source = $rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($source) || is_link($source)) {
            fwrite(STDERR, "Missing or unsafe source file: {$relative}\n");
            exit(1);
        }
        $pendingPath = $pendingTargets[$relative]['pending'];
        $metadataPath = $pendingTargets[$relative]['metadata'];
        $hash = (string) hash_file('sha256', $source);
        $metadata = json_encode([
            'target' => $relative,
            'sha256' => $hash,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($metadata)) {
            fwrite(STDERR, "Could not encode updater metadata.\n");
            exit(1);
        }
        $manifestFiles[$pendingPath] = $hash;
        $manifestFiles[$metadataPath] = hash('sha256', $metadata);
        $packageFiles[$pendingPath] = $source;
        $generatedFiles[$metadataPath] = $metadata;
        continue;
    }
    if (!isAllowedUpdatePath($relative)) {
        fwrite(STDERR, "Forbidden update path: {$relative}\n");
        exit(1);
    }
    $source = $rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($source) || is_link($source)) {
        fwrite(STDERR, "Missing or unsafe source file: {$relative}\n");
        exit(1);
    }
    $manifestFiles[$relative] = hash_file('sha256', $source);
    $packageFiles[$relative] = $source;
}
if (!isset($manifestFiles['VERSION'])) {
    fwrite(STDERR, "VERSION must be included.\n");
    exit(1);
}
if (trim((string) file_get_contents($rootDir . '/VERSION')) !== $version) {
    fwrite(STDERR, "VERSION content must match --version.\n");
    exit(1);
}
ksort($manifestFiles);
$manifestData = [
    'product' => 'Tomos',
    'from_version' => $from,
];
if ($legacyBridge) {
    $manifestData['minimum_version'] = $from;
}
$manifestData['version'] = $version;
$manifestData['files'] = $manifestFiles;
$manifest = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($manifest)) {
    fwrite(STDERR, "Could not encode manifest.\n");
    exit(1);
}
$privateKey = file_get_contents($privateKeyPath);
$signature = '';
if (!is_string($privateKey) || !openssl_sign($manifest, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
    fwrite(STDERR, "Could not sign manifest.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create ZIP.\n");
    exit(1);
}
if (!$zip->addFromString('manifest.json', $manifest)
    || !$zip->addFromString('manifest.sig', $signature)
) {
    $zip->close();
    fwrite(STDERR, "Could not add signed manifest to ZIP.\n");
    exit(1);
}
foreach (array_keys($manifestFiles) as $relative) {
    $entry = 'files/' . $relative;
    if (isset($generatedFiles[$relative])) {
        if (!$zip->addFromString($entry, $generatedFiles[$relative])) {
            $zip->close();
            fwrite(STDERR, "Could not add generated package file: {$relative}\n");
            exit(1);
        }
        continue;
    }
    if (!isset($packageFiles[$relative])) {
        $zip->close();
        fwrite(STDERR, "Missing package source: {$relative}\n");
        exit(1);
    }
    if (!$zip->addFile($packageFiles[$relative], $entry)) {
        $zip->close();
        fwrite(STDERR, "Could not add package file: {$relative}\n");
        exit(1);
    }
}
if (!$zip->close()) {
    fwrite(STDERR, "Could not finalize ZIP.\n");
    exit(1);
}

$verifyZip = new ZipArchive();
if ($verifyZip->open($outputPath) !== true) {
    fwrite(STDERR, "Could not reopen ZIP for verification.\n");
    exit(1);
}
try {
    if ($verifyZip->numFiles !== count($manifestFiles) + 2
        || $verifyZip->getFromName('manifest.json') !== $manifest
        || $verifyZip->getFromName('manifest.sig') !== $signature
    ) {
        fwrite(STDERR, "Signed manifest verification failed.\n");
        exit(1);
    }
    foreach ($manifestFiles as $relative => $expectedHash) {
        $contents = $verifyZip->getFromName('files/' . $relative);
        if (!is_string($contents)
            || !hash_equals(strtolower((string) $expectedHash), hash('sha256', $contents))
        ) {
            fwrite(STDERR, "ZIP content hash verification failed: {$relative}\n");
            exit(1);
        }
    }
    foreach (array_keys($pendingTargets) as $pendingTarget) {
        if (isset($requestedFiles[$pendingTarget])
            && $verifyZip->locateName('files/' . $pendingTarget) !== false
        ) {
            fwrite(STDERR, $pendingTarget . " must not be stored directly in the Update ZIP.\n");
            exit(1);
        }
    }
} finally {
    $verifyZip->close();
}
echo $outputPath . PHP_EOL;

function isAllowedUpdatePath(string $path): bool
{
    if ($path === '' || strpos($path, "\0") !== false || strpos($path, '\\') !== false || strpos($path, ':') !== false
        || strpos($path, '/') === 0 || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1
    ) {
        return false;
    }
    if (in_array($path, ['config.php', 'config.sample.php', '.htaccess'], true)) {
        return false;
    }
    foreach (['content/', 'cache/', 'storage/', 'trash/', 'update/', 'tests/', 'tools/', 'build/', 'backups/', 'staging/', '書類/'] as $prefix) {
        if (strpos($path, $prefix) === 0) {
            return false;
        }
    }
    if (preg_match('#\Acore/Update(?:Lock|Service|Exception)\.php\z#', $path) === 1) {
        return false;
    }
    if (strpos($path, 'themes/') === 0) {
        return preg_match('#\Athemes/(tomos-90s|tomos-blog|tomos-dark|tomos-journal|tomos-minimal|tomos-note)/[A-Za-z0-9._/-]+\z#', $path) === 1;
    }
    return $path === 'VERSION' || $path === 'index.php' || preg_match('#\A(core|post|setup|assets)/[A-Za-z0-9._/-]+\z#', $path) === 1;
}

function isValidTomosVersion(string $version): bool
{
    return preg_match('/\A[0-9]+(?:\.[0-9]+)*(?:-[0-9A-Za-z.-]+)?\z/', $version) === 1;
}
