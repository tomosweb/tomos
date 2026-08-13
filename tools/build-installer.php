<?php

declare(strict_types=1);

$repo = dirname(__DIR__);
$options = getopt('', ['output::', 'pointer-url::', 'public-key-file::']);
$output = (string) ($options['output'] ?? $repo . '/build/install.php');
$pointerUrl = (string) ($options['pointer-url'] ?? 'https://tomoswords.org/download/install/latest.json');
$publicKeyFile = (string) ($options['public-key-file'] ?? $repo . '/update/public-key.pem');
$publicKeySource = $repo . '/tools/installer/InstallerPublicKey.php';
$updateKey = $repo . '/update/public-key.pem';
if (!is_file($publicKeySource) || !is_file($updateKey)) fail('Public key source is missing.');
$publicKeyText = trim((string) @file_get_contents($publicKeyFile));
if ($publicKeyText === '' || strpos($publicKeyText, '-----BEGIN') === false) fail('Public key file is missing or invalid.');
$defaultKey = realpath($publicKeyFile) === realpath($updateKey);
if ($defaultKey) {
    $embeddedSource = (string) file_get_contents($publicKeySource);
    $updateKeyText = trim((string) file_get_contents($updateKey));
    if (strpos($embeddedSource, $updateKeyText) === false) fail('Embedded installer public key does not match Update public key.');
} else {
    if (strpos($publicKeyText, '-----BEGIN PUBLIC KEY-----') === false && strpos($publicKeyText, '-----BEGIN RSA PUBLIC KEY-----') === false) {
        fail('Test public key must be a PEM public key.');
    }
}
$pointer = parse_url($pointerUrl);
if (!is_array($pointer) || ($pointer['scheme'] ?? '') !== 'https' || empty($pointer['host']) || isset($pointer['user'], $pointer['pass'], $pointer['query'], $pointer['fragment'])) {
    fail('Pointer URL must be an HTTPS URL without credentials, query, or fragment.');
}
$pointerHost = strtolower((string) $pointer['host']);
$sources = [
    'tools/installer/InstallManifest.php',
    'tools/installer/InstallerPublicKey.php',
    'tools/installer/InstallerSecurity.php',
    'tools/installer/InstallerDownloader.php',
    'tools/installer/InstallerStaging.php',
    'tools/installer/InstallerCore.php',
    'tools/installer/InstallerVerifiedResult.php',
    'tools/installer/InstallerJournal.php',
    'tools/installer/InstallerPlacement.php',
    'tools/installer/InstallerLifecycle.php',
    'tools/installer/InstallerApplication.php',
];
$bundle = "<?php\n\ndeclare(strict_types=1);\n\n";
foreach ($sources as $relative) {
    $path = $repo . '/' . $relative;
    if (!is_file($path)) fail('Missing installer source: ' . $relative);
    $source = (string) file_get_contents($path);
    $source = preg_replace('/\A\s*<\?php\s*declare\(strict_types=1\);\s*/', '', $source, 1, $count);
    if ($count !== 1) fail('Installer source is not bundleable: ' . $relative);
    if ($relative === 'tools/installer/InstallerPlacement.php') {
        $source = stripTestHooks($source);
    }
    if ($relative === 'tools/installer/InstallerPublicKey.php' && !$defaultKey) {
        $source = replaceEmbeddedPublicKey($source, $publicKeyText);
    }
    if ($relative === 'tools/installer/InstallerCore.php' && !$defaultKey) {
        $source = str_replace("public const DEFAULT_POINTER_URL = 'https://tomoswords.org/download/install/latest.json';", "public const DEFAULT_POINTER_URL = " . var_export($pointerUrl, true) . ";", $source);
    }
    $bundle .= "\n/* BEGIN " . $relative . " */\n" . $source . "\n/* END " . $relative . " */\n";
}

if ($defaultKey && $pointerUrl === 'https://tomoswords.org/download/install/latest.json') {
    $bundle .= <<<'PHP'

/* BEGIN installer entry */
(new InstallerApplication(__DIR__, [
    'installer_path' => __FILE__,
    'allow_self_delete' => true,
]))->run();
/* END installer entry */
PHP;
} else {
    $bundle .= "\n/* TEST RELEASE CANDIDATE entry */\n";
    $bundle .= '(new InstallerApplication(__DIR__, ' . var_export([
        'installer_path' => '__INSTALLER_PATH__',
        'allow_self_delete' => true,
        'pointer_url' => $pointerUrl,
        'pointer_hosts' => [$pointerHost],
        'manifest_hosts' => [$pointerHost],
        'asset_hosts' => [$pointerHost],
    ], true) . '))->run();';
    $bundle = str_replace("'__INSTALLER_PATH__'", '__FILE__', $bundle);
    $bundle .= "\n/* END TEST RELEASE CANDIDATE entry */\n";
}
$bundle .= "\n";
if ($defaultKey && (strpos($bundle, 'require_once') !== false || strpos($bundle, 'require(') !== false || strpos($bundle, 'fixture.test') !== false || strpos($bundle, 'localhost') !== false || preg_match('/BEGIN.*test/i', $bundle))) {
    fail('Generated installer contains a forbidden external or test dependency.');
}
if (preg_match('/-----BEGIN (?:RSA )?PRIVATE KEY-----/', $bundle)) {
    fail('Generated installer contains a private key.');
}
$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0755, true)) fail('Could not create build directory.');
$temporary = $output . '.tmp-' . bin2hex(random_bytes(8));
if (file_put_contents($temporary, $bundle, LOCK_EX) === false || !rename($temporary, $output)) {
    @unlink($temporary);
    fail('Could not write generated installer.');
}
chmod($output, 0644);
$lint = [];
$status = 0;
exec('php -l ' . escapeshellarg($output) . ' 2>&1', $lint, $status);
if ($status !== 0) fail(implode("\n", $lint));
echo $output . "\n";

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function stripTestHooks(string $source): string
{
    $source = preg_replace('/final class InstallerSimulatedTermination extends RuntimeException\s*\{\s*\}\s*/', '', $source, 1);
    $source = preg_replace('/\s*catch \(InstallerSimulatedTermination \$exception\) \{\s*throw \$exception;\s*\}/', '', $source);
    $source = preg_replace('/\s*\$this->fault\([^;]+\);/', '', $source);
    $source = preg_replace('/\s*if \(\$this->faultEnabled\(\$faults, \'rename_fail\'\)\) \{\s*throw new InstallManifestException\([^;]+;\s*\}/', '', $source);
    $source = preg_replace('/\s*if \(\$this->faultEnabled\(\$faults, \'marker_fail\'\)\) \{\s*throw new InstallManifestException\([^;]+;\s*\}/', '', $source);
    $source = preg_replace('/\n    private function fault\(array \$faults.*?\n    \}\n\n    private function faultEnabled\(.*?\n    \}\n\}/s', "\n}", $source, 1);
    if (!is_string($source) || strpos($source, 'InstallerSimulatedTermination') !== false || strpos($source, 'faultEnabled') !== false) {
        fail('Could not remove test-only fault hooks from generated installer.');
    }
    return $source;
}

function replaceEmbeddedPublicKey(string $source, string $publicKey): string
{
    $replacement = "public const PEM = <<<'PEM'\n" . rtrim($publicKey) . "\nPEM;";
    $updated = preg_replace('/public const PEM = <<<\'PEM\'.*?\nPEM;/s', $replacement, $source, 1, $count);
    if (!is_string($updated) || $count !== 1) {
        fail('Could not replace embedded test public key.');
    }
    return $updated;
}
