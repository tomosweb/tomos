<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/installer/InstallManifest.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerJournal.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerVerifiedResult.php';
require_once dirname(__DIR__) . '/tools/installer/InstallerPlacement.php';

$repo = dirname(__DIR__);
$version = trim((string) file_get_contents($repo . '/VERSION'));
$zip = $repo . '/build/tomos-' . $version . '.zip';
$manifest = InstallManifest::buildFromZip($zip, $repo . '/VERSION', 'https://fixture.test/installer/releases/' . $version . '/tomos-' . $version . '.zip');
$passes = 0;
$failures = [];

try {
    $root = makeRoot($repo, $manifest, $zip, $version);
    $input = $root['input'];
    $placement = new InstallerPlacement($root['path']);
    $result = $placement->place($input, InstallerPlacement::MODE_CURRENT);
    check($result['mode'] === 'current', 'A normal placement succeeds');
    check(is_file($root['path'] . '/installed-marker-placeholder') === false, 'no test marker leaks into target');
    check(is_file($placement->markerPath()), 'A installed marker exists');
    check(is_file($root['path'] . '/index.php'), 'A index exists');
    check(is_file($root['path'] . '/.htaccess'), 'A htaccess exists');
    check(count(glob($root['path'] . '/.tomos-installer/transactions/*') ?: []) === 0, 'A completed journal is removed');
    cleanup($root['path']);

    $root = makeRoot($repo, $manifest, $zip, $version);
    $input = $root['input'];
    $placement = new InstallerPlacement($root['path']);
    file_put_contents($root['path'] . '/index.php', 'preserve');
    expectCode('A collision', 'target_collision', function () use ($placement, $input): void {
        $placement->place($input, InstallerPlacement::MODE_CURRENT);
    });
    check(file_get_contents($root['path'] . '/index.php') === 'preserve', 'A collision does not change existing file');
    cleanup($root['path']);

    $root = makeRoot($repo, $manifest, $zip, $version);
    $input = $root['input'];
    $placement = new InstallerPlacement($root['path']);
    expectCode('A marker failure rollback', 'installed_marker', function () use ($placement, $input): void {
        $placement->place($input, InstallerPlacement::MODE_CURRENT, null, ['marker_fail' => true]);
    });
    check(!file_exists($root['path'] . '/index.php'), 'A marker failure rolls back index');
    check(!file_exists($root['path'] . '/core'), 'A marker failure rolls back directories');
    check(count(glob($root['path'] . '/.tomos-installer/transactions/*') ?: []) === 0, 'A rollback removes journal');
    cleanup($root['path']);

    $root = makeRoot($repo, $manifest, $zip, $version);
    $input = $root['input'];
    $placement = new InstallerPlacement($root['path']);
    expectCode('A simulated termination', 'simulated', function () use ($placement, $input): void {
        try {
            $placement->place($input, InstallerPlacement::MODE_CURRENT, null, ['file_verified' => '.htaccess']);
        } catch (InstallerSimulatedTermination $exception) {
            throw new InstallManifestException('simulated', $exception->getMessage());
        }
    });
    $journalFiles = glob($root['path'] . '/.tomos-installer/transactions/*/journal.json') ?: [];
    check(count($journalFiles) === 1, 'A termination leaves persistent journal');
    $recovery = $placement->recover();
    check($recovery[0]['status'] === 'cleaned', 'A recovery cleans interrupted transaction');
    check(!file_exists($root['path'] . '/index.php'), 'A recovery removes incomplete publication');
    cleanup($root['path']);

    $root = makeRoot($repo, $manifest, $zip, $version);
    $input = $root['input'];
    $placement = new InstallerPlacement($root['path']);
    expectCode('A unsafe recovery', 'simulated', function () use ($placement, $input): void {
        try {
            $placement->place($input, InstallerPlacement::MODE_CURRENT, null, ['file_verified' => '.htaccess']);
        } catch (InstallerSimulatedTermination $exception) {
            throw new InstallManifestException('simulated', 'termination');
        }
    });
    // The actual hash-mismatch recovery assertion is performed on the next isolated root.
    $journalFiles = glob($root['path'] . '/.tomos-installer/transactions/*/journal.json') ?: [];
    $data = json_decode((string) file_get_contents($journalFiles[0]), true);
    $changed = $root['path'] . '/.htaccess';
    file_put_contents($changed, "changed\n", LOCK_EX);
    $recovery = $placement->recover();
    check($recovery[0]['status'] === 'recovery_required', 'A hash mismatch stops automatic recovery');
    check(file_get_contents($changed) === "changed\n", 'A hash mismatch preserves changed file');
    cleanup($root['path']);

    $root = makeRoot($repo, $manifest, $zip, $version);
    $input = $root['input'];
    $placement = new InstallerPlacement($root['path']);
    $result = $placement->place($root['input'], InstallerPlacement::MODE_CHILD, 'blog');
    check($result['mode'] === 'child', 'B normal placement succeeds');
    check(is_dir($root['path'] . '/blog'), 'B target directory exists');
    check(is_file($root['path'] . '/blog/index.php'), 'B target contains index');
    check(!is_file($root['path'] . '/index.php'), 'B does not place at current root');
    cleanup($root['path']);

    $root = makeRoot($repo, $manifest, $zip, $version);
    $placement = new InstallerPlacement($root['path']);
    mkdir($root['path'] . '/blog');
    expectCode('B existing child', 'target_exists', function () use ($placement, $root): void {
        $placement->place($root['input'], InstallerPlacement::MODE_CHILD, 'blog');
    });
    cleanup($root['path']);

    $root = makeRoot($repo, $manifest, $zip, $version);
    $placement = new InstallerPlacement($root['path']);
    expectCode('B rename failure no fallback', 'rename_failed', function () use ($placement, $root): void {
        $placement->place($root['input'], InstallerPlacement::MODE_CHILD, 'blog', ['rename_fail' => true]);
    });
    check(!file_exists($root['path'] . '/blog'), 'B rename failure leaves no target');
    check(!file_exists($root['path'] . '/index.php'), 'B rename failure does not fallback to A');
    cleanup($root['path']);

    $root = makeRoot($repo, $manifest, $zip, $version);
    $placement = new InstallerPlacement($root['path']);
    expectCode('B moved termination', 'simulated', function () use ($placement, $root): void {
        try {
            $placement->place($root['input'], InstallerPlacement::MODE_CHILD, 'blog', ['after_rename' => true]);
        } catch (InstallerSimulatedTermination $exception) {
            throw new InstallManifestException('simulated', $exception->getMessage());
        }
    });
    check(is_dir($root['path'] . '/blog'), 'B moved state leaves renamed target');
    $recovery = $placement->recover();
    check($recovery[0]['status'] === 'forward_recovered', 'B moved state forward recovers');
    check(is_file($placement->markerPath()), 'B forward recovery writes marker');
    cleanup($root['path']);

    $root = makeRoot($repo, $manifest, $zip, $version);
    $placement = new InstallerPlacement($root['path']);
    expectCode('B ready termination', 'simulated', function () use ($placement, $root): void {
        try {
            $placement->place($root['input'], InstallerPlacement::MODE_CHILD, 'blog', ['ready_to_move' => true]);
        } catch (InstallerSimulatedTermination $exception) {
            throw new InstallManifestException('simulated', $exception->getMessage());
        }
    });
    $recovery = $placement->recover();
    check($recovery[0]['status'] === 'cleaned', 'B ready state cleans staging');
    check(!file_exists($root['path'] . '/blog'), 'B ready recovery leaves no target');
    cleanup($root['path']);

    echo "installer_phase3_check: {$passes} checks passed\n";
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
}
if ($failures !== []) exit(1);

function makeRoot(string $repo, array $manifest, string $zip, string $version): array
{
    $root = sys_get_temp_dir() . '/tomos-installer-phase3-' . bin2hex(random_bytes(8));
    mkdir($root, 0700, true);
    $work = $root . '/.tomos-installer/work-' . bin2hex(random_bytes(16));
    mkdir($work, 0700, true);
    $staging = $work . '/staging';
    InstallManifest::extractVerifiedZip($zip, $manifest, $staging);
    $input = new InstallerVerifiedResult($staging, $root, $version, $manifest, substr(basename($work), 5));
    return ['path' => $root, 'input' => $input];
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
