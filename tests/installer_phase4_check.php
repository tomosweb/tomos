<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/installer/InstallManifest.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerPublicKey.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerSecurity.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerDownloader.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerStaging.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerCore.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerVerifiedResult.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerJournal.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerPlacement.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerLifecycle.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerApplication.php';

$repo = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/tomos-installer-phase4-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700, true);
mkdir($tmp . '/sessions', 0700, true);
session_save_path($tmp . '/sessions');
$passes = 0;
$failures = [];

try {
    $version = trim((string) file_get_contents($repo . '/VERSION'));
    $zipPath = $repo . '/build/tomos-' . $version . '.zip';
    check(is_file($zipPath), 'fixture distribution ZIP exists');
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    $details = openssl_pkey_get_details($key);
    $publicKey = (string) $details['key'];

    $uiRoot = newRoot();
    $ui = makeApplication($repo, $uiRoot, $zipPath, $version, $publicKey);
    InstallerSecurity::startSession(true);
    InstallerSecurity::bootstrap();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    $ui->run();
    $html = (string) ob_get_clean();
    check(strpos($html, 'Tomos かんたんインストール') !== false, 'initial UI has title');
    check(strpos($html, 'この場所に設置') !== false && strpos($html, '新しいフォルダに設置') !== false, 'initial UI has A/B choices');
    check(strpos($html, 'class="wrap"') !== false && strpos($html, 'class="actions"') !== false && strpos($html, 'class="hint"') !== false, 'initial UI uses Tomos layout classes');
    check(strpos($html, '確認番号:') !== false, 'initial UI uses confirmation label');
    check(strpos($html, '#f6f4ef') !== false && strpos($html, '#fcfbf8') !== false && strpos($html, '#d9d6cf') !== false, 'initial UI uses Tomos surface and border tokens');
    check(strpos($html, '#9a431c') !== false && strpos($html, '#853919') !== false, 'initial UI uses Tomos primary tokens');
    check(strpos($html, 'max-width:800px') !== false && strpos($html, '@media(max-width:560px)') !== false, 'initial UI uses Tomos layout breakpoints');
    check(strpos($html, ':focus-visible') !== false && strpos($html, 'min-height:44px') !== false, 'initial UI preserves keyboard focus and touch target styles');
    check(strpos($html, '#28634d') === false && strpos($html, 'max-width:640px') === false && strpos($html, 'class="card"') === false, 'initial UI does not retain old layout tokens');
    check(strpos($html, '診断コード:') === false, 'initial UI does not retain old diagnostic label');
    check(strpos($html, '@media(max-width:480px)') === false, 'initial UI does not retain old breakpoint');
    check(strpos($html, 'staging') === false && strpos($html, 'RSA') === false && strpos($html, 'ZipArchive') === false, 'initial UI hides internal terminology');
    check(strpos($html, expectedDiagnostic('ready')) !== false, 'initial UI uses ready diagnostic code');
    checkDiagnosticFor($ui, 'environment', 'environment');
    checkDiagnosticFor($ui, 'manifest_signature', 'manifest_signature');
    checkDiagnosticFor($ui, 'asset_hash', 'asset_hash');
    cleanup($uiRoot);

    $aRoot = newRoot();
    $a = makeApplication($repo, $aRoot, $zipPath, $version, $publicKey);
    $csrf = InstallerSecurity::csrfToken();
    $installed = $a->installPost(['csrf' => $csrf, 'mode' => InstallerPlacement::MODE_CURRENT]);
    check($installed['mode'] === InstallerPlacement::MODE_CURRENT, 'A application flow succeeds');
    check(is_file($aRoot . '/index.php') && is_file($aRoot . '/setup/index.php'), 'A application flow places Tomos');
    check(is_file($aRoot . '/.tomos-installer/installed.json'), 'A installed marker exists');
    check(is_file($aRoot . '/.tomos-installer/disabled.json'), 'A disabled marker exists');
    check($installed['start_url'] === './setup/', 'A completion points to setup');
    $GLOBALS['_phase4_test_app'] = $a;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    $a->run();
    $disabledHtml = (string) ob_get_clean();
    check(strpos($disabledHtml, '使用済み') !== false, 'disabled installer shows used state');
    cleanup($aRoot);

    $bRoot = newRoot();
    $b = makeApplication($repo, $bRoot, $zipPath, $version, $publicKey);
    $csrf = InstallerSecurity::csrfToken();
    $installed = $b->installPost(['csrf' => $csrf, 'mode' => InstallerPlacement::MODE_CHILD, 'child_directory' => 'blog']);
    check($installed['mode'] === InstallerPlacement::MODE_CHILD, 'B application flow succeeds');
    check(is_file($bRoot . '/blog/index.php') && !is_file($bRoot . '/index.php'), 'B application flow places only child target');
    check($installed['start_url'] === './blog/setup/', 'B completion points to child setup');
    cleanup($bRoot);

    $invalidRoot = newRoot();
    $invalid = makeApplication($repo, $invalidRoot, $zipPath, $version, $publicKey);
    expectCode('invalid child is rejected before download', 'target_exists', function () use ($invalid): void {
        $invalid->installPost(['csrf' => InstallerSecurity::csrfToken(), 'mode' => InstallerPlacement::MODE_CHILD, 'child_directory' => '../blog']);
    });
    cleanup($invalidRoot);

    $fallbackRoot = newRoot();
    $fallback = makeApplication($repo, $fallbackRoot, $zipPath, $version, $publicKey, ['https' => false]);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    $fallback->run();
    $fallbackHtml = (string) ob_get_clean();
    check(strpos($fallbackHtml, '通常のファイルアップロード') !== false, 'environment failure has normal upload fallback');
    cleanup($fallbackRoot);

    $deleteRoot = newRoot();
    $lifecycle = new InstallerLifecycle($deleteRoot, InstallerApplication::VERSION);
    $lifecycle->disable(['version' => $version, 'transaction_id' => str_repeat('a', 32)]);
    file_put_contents($deleteRoot . '/install.php', '<?php');
    check($lifecycle->trySelfDelete($deleteRoot . '/install.php', true), 'self-delete succeeds in local fixture');
    check(!file_exists($deleteRoot . '/install.php') && $lifecycle->isDisabled(), 'self-delete leaves disable marker');
    cleanup($deleteRoot);

    echo "installer_phase4_check: {$passes} checks passed\n";
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
}
if ($failures !== []) exit(1);

function makeApplication(string $repo, string $root, string $zipPath, string $version, string $publicKey, array $extra = []): InstallerApplication
{
    $artifact = $root . '/fixture';
    mkdir($artifact, 0700, true);
    $base = 'https://fixture.test/v' . $version;
    $manifest = InstallManifest::buildFromZip($zipPath, $repo . '/VERSION', $base . '/tomos-' . $version . '.zip');
    $raw = InstallManifest::encode($manifest);
    $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($private, $privateRaw);
    $fixturePublicKey = (string) openssl_pkey_get_details($private)['key'];
    openssl_sign($raw, $signature, $privateRaw, OPENSSL_ALGO_SHA256);
    file_put_contents($artifact . '/latest.json', InstallManifest::encodePointer(InstallManifest::buildPointer($version, $base . '/install-manifest.json', $base . '/install-manifest.sig')));
    file_put_contents($artifact . '/install-manifest.json', $raw);
    file_put_contents($artifact . '/install-manifest.sig', $signature);
    copy($zipPath, $artifact . '/tomos-' . $version . '.zip');
    $map = [
        'https://fixture.test/download/install/latest.json' => $artifact . '/latest.json',
        $base . '/install-manifest.json' => $artifact . '/install-manifest.json',
        $base . '/install-manifest.sig' => $artifact . '/install-manifest.sig',
        $base . '/tomos-' . $version . '.zip' => $artifact . '/tomos-' . $version . '.zip',
    ];
    $transport = new InstallerDownloader(function (string $url, string $destination, int $maxBytes) use (&$map): array {
        if (!isset($map[$url])) return ['status' => 404, 'headers' => []];
        copy($map[$url], $destination);
        return ['status' => 200, 'headers' => [], 'content_length' => filesize($destination)];
    });
    $config = array_merge([
        'pointer_url' => 'https://fixture.test/download/install/latest.json',
        'pointer_hosts' => ['fixture.test'],
        'manifest_hosts' => ['fixture.test'],
        'asset_hosts' => ['fixture.test'],
        'public_key' => $fixturePublicKey,
        'installer_path' => $root . '/install.php',
        'allow_self_delete' => false,
    ], $extra);
    $core = new InstallerCore($root, $config, $transport);
    return new InstallerApplication($root, $config, $core, new InstallerPlacement($root, InstallerApplication::VERSION), new InstallerLifecycle($root, InstallerApplication::VERSION));
}

function newRoot(): string
{
    $root = sys_get_temp_dir() . '/tomos-installer-app-' . bin2hex(random_bytes(8));
    mkdir($root, 0700, true);
    return $root;
}

function cleanup(string $path): void
{
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $item) if ($item !== '.' && $item !== '..') cleanup($path . DIRECTORY_SEPARATOR . $item);
    @rmdir($path);
}

function check(bool $condition, string $label): void
{
    global $passes, $failures;
    if ($condition) { $passes++; return; }
    $failures[] = $label;
    throw new RuntimeException($label);
}

function expectCode(string $label, string $expected, callable $action): void
{
    try { $action(); }
    catch (InstallManifestException $exception) { check($exception->errorCode() === $expected, $label . ' expected ' . $expected . ', got ' . $exception->errorCode()); return; }
    check(false, $label . ' did not fail');
}

function checkDiagnosticFor(InstallerApplication $application, string $errorCode, string $label): void
{
    $mapper = new ReflectionMethod(InstallerApplication::class, 'messageFor');
    $message = $mapper->invoke($application, new InstallManifestException($errorCode, 'test'));
    $renderer = new ReflectionMethod(InstallerApplication::class, 'render');
    ob_start();
    $renderer->invoke($application, ['errors' => [], 'warnings' => []], [], $message);
    $html = (string) ob_get_clean();
    check(strpos($html, expectedDiagnostic($errorCode)) !== false, $label . ' diagnostic code uses actual error code');
    check(strpos($html, expectedDiagnostic('ready')) === false, $label . ' diagnostic code is not ready');
}

function expectedDiagnostic(string $code): string
{
    return 'TOMOS-INSTALL-' . strtoupper(substr(hash('sha256', $code . '|' . gmdate('Y-m-d-H')), 0, 10));
}
