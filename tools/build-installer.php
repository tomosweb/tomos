<?php

declare(strict_types=1);

$repo = dirname(__DIR__);
$output = $repo . '/build/install.php';
$publicKeySource = $repo . '/tools/installer/InstallerPublicKey.php';
$updateKey = $repo . '/update/public-key.pem';
if (!is_file($publicKeySource) || !is_file($updateKey)) fail('Public key source is missing.');
$publicKeyText = (string) file_get_contents($publicKeySource);
$updateKeyText = trim((string) file_get_contents($updateKey));
if (strpos($publicKeyText, $updateKeyText) === false) fail('Embedded installer public key does not match Update public key.');
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
    $bundle .= "\n/* BEGIN " . $relative . " */\n" . $source . "\n/* END " . $relative . " */\n";
}
$bundle .= <<<'PHP'

/* BEGIN installer entry */
(new InstallerApplication(__DIR__, [
    'installer_path' => __FILE__,
    'allow_self_delete' => true,
]))->run();
/* END installer entry */
PHP;
$bundle .= "\n";
if (strpos($bundle, 'require_once') !== false || strpos($bundle, 'require(') !== false || strpos($bundle, 'fixture.test') !== false || strpos($bundle, 'localhost') !== false || preg_match('/BEGIN.*test/i', $bundle)) {
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
