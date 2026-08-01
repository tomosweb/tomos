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

$options = getopt('', ['version:', 'minimum:', 'private-key:', 'output:', 'file:']);
$version = trim((string) ($options['version'] ?? ''));
$minimum = trim((string) ($options['minimum'] ?? ''));
$privateKeyPath = (string) ($options['private-key'] ?? '');
$outputPath = (string) ($options['output'] ?? '');
$files = $options['file'] ?? [];
$files = is_array($files) ? $files : [$files];
$rootDir = dirname(__DIR__);
$requiredFilesPath = __DIR__ . DIRECTORY_SEPARATOR . 'required-source-files.txt';

if ($version === '' || $minimum === '' || $privateKeyPath === '' || $outputPath === '' || $files === []) {
    fwrite(STDERR, "Usage: php tools/build-update-package.php --version=0.1.1-alpha --minimum=0.1.0-alpha --private-key=/safe/private.pem --output=/path/update.zip --file=core/File.php --file=VERSION\n");
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
foreach ($files as $relative) {
    $relative = (string) $relative;
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
$manifest = json_encode([
    'product' => 'Tomos',
    'version' => $version,
    'minimum_version' => $minimum,
    'files' => $manifestFiles,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
$zip->addFromString('manifest.json', $manifest);
$zip->addFromString('manifest.sig', $signature);
foreach (array_keys($manifestFiles) as $relative) {
    $zip->addFile($rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), 'files/' . $relative);
}
if (!$zip->close()) {
    fwrite(STDERR, "Could not finalize ZIP.\n");
    exit(1);
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
