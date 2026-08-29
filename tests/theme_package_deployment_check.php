<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/core/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Tomos\ThemePackageDeployment;
use Tomos\ThemePackageException;
use Tomos\ThemePackageInstaller;

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "SKIP: ZipArchive is unavailable.\n");
    exit(2);
}

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-theme-deployment-' . bin2hex(random_bytes(8));
mkdir($testRoot, 0700, true);
$passes = 0;
$failures = [];

try {
    runTests($testRoot, $passes, $failures);
} finally {
    removeTree($testRoot);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "theme_package_deployment_check: {$passes} checks passed\n";

function runTests(string $testRoot, int &$passes, array &$failures): void
{
    check('new theme installs through deployment wrapper', function () use ($testRoot): void {
        [$root, $deployment] = environment($testRoot, 'install');
        $result = deployPackage($deployment, $root, 'owner-a', themeEntries('1.0.0', 'body{color:#111;}'));
        assertSame('install', $result['operation']);
        assertSame('1.0.0', $result['version']);
        assertTrue(is_file($root . '/themes/tomos-test/theme.json'), 'theme was not installed');
        assertNoCandidateArtifacts($root);
    }, $passes, $failures);

    check('newer version replaces whole theme directory', function () use ($testRoot): void {
        [$root, $deployment] = environment($testRoot, 'newer');
        $first = themeEntries('1.0.0', 'body{color:#111;}');
        $first['tomos-test/assets/old.css'] = '.old{}';
        deployPackage($deployment, $root, 'owner-a', $first);

        $deployment = new ThemePackageDeployment($root, $root . '/themes', 'owner-a');
        $result = deployPackage($deployment, $root, 'owner-a', themeEntries('1.1.0', 'body{color:#222;}'));
        assertSame('update', $result['operation']);
        assertSame('1.0.0', $result['previous_version']);
        assertSame('1.1.0', $result['version']);
        assertSame('newer', $result['version_relation']);
        assertSame('body{color:#222;}', file_get_contents($root . '/themes/tomos-test/assets/style.css'));
        assertTrue(!file_exists($root . '/themes/tomos-test/assets/old.css'), 'obsolete file survived replacement');
        assertNoCandidateArtifacts($root);
    }, $passes, $failures);

    check('same version reinstall is allowed', function () use ($testRoot): void {
        [$root, $deployment] = environment($testRoot, 'same-version');
        deployPackage($deployment, $root, 'owner-a', themeEntries('1.0.0', 'body{color:#111;}'));

        $deployment = new ThemePackageDeployment($root, $root . '/themes', 'owner-a');
        $result = deployPackage($deployment, $root, 'owner-a', themeEntries('1.0.0', 'body{color:#333;}'));
        assertSame('update', $result['operation']);
        assertSame('same', $result['version_relation']);
        assertSame('body{color:#333;}', file_get_contents($root . '/themes/tomos-test/assets/style.css'));
        assertNoCandidateArtifacts($root);
    }, $passes, $failures);

    check('downgrade is rejected before replacement', function () use ($testRoot): void {
        [$root, $deployment] = environment($testRoot, 'downgrade');
        deployPackage($deployment, $root, 'owner-a', themeEntries('2.0.0', 'body{color:#222;}'));

        $deployment = new ThemePackageDeployment($root, $root . '/themes', 'owner-a');
        [$id] = inspectPackage($deployment, $root, 'owner-a', themeEntries('1.0.0', 'body{color:#111;}'));
        expectStage(function () use ($deployment, $id): void {
            $deployment->apply($id, 'owner-a');
        }, 'version_downgrade');
        $theme = json_decode((string) file_get_contents($root . '/themes/tomos-test/theme.json'), true);
        assertSame('2.0.0', $theme['version'] ?? null);
        assertSame('body{color:#222;}', file_get_contents($root . '/themes/tomos-test/assets/style.css'));
        assertNoCandidateArtifacts($root);
    }, $passes, $failures);

    check('site-specific files stay untouched during theme update', function () use ($testRoot): void {
        [$root, $deployment] = environment($testRoot, 'site-specific');
        file_put_contents($root . '/theme-settings.php', "<?php return ['hero'=>['title'=>'Lab']];\n");
        mkdir($root . '/theme-assets', 0755);
        file_put_contents($root . '/theme-assets/logo.svg', '<svg></svg>');
        mkdir($root . '/content', 0755);
        file_put_contents($root . '/content/index.md', "# Lab\n");
        $before = [
            hash_file('sha256', $root . '/theme-settings.php'),
            hash_file('sha256', $root . '/theme-assets/logo.svg'),
            hash_file('sha256', $root . '/content/index.md'),
        ];

        deployPackage($deployment, $root, 'owner-a', themeEntries('1.0.0', 'body{color:#111;}'));
        $deployment = new ThemePackageDeployment($root, $root . '/themes', 'owner-a');
        deployPackage($deployment, $root, 'owner-a', themeEntries('1.1.0', 'body{color:#222;}'));

        $after = [
            hash_file('sha256', $root . '/theme-settings.php'),
            hash_file('sha256', $root . '/theme-assets/logo.svg'),
            hash_file('sha256', $root . '/content/index.md'),
        ];
        assertSame($before, $after);
    }, $passes, $failures);

    check('deploy lock failure does not leave candidate theme', function () use ($testRoot): void {
        [$root, $deployment] = environment($testRoot, 'deploy-lock');
        [$id] = inspectPackage($deployment, $root, 'owner-a', themeEntries('1.0.0', 'body{color:#111;}'));
        $lock = fopen($root . '/storage/theme-deploy.lock', 'x');
        flock($lock, LOCK_EX);
        fwrite($lock, json_encode(['started_at' => time(), 'owner_hash' => hash('sha256', 'other')]));
        expectStage(function () use ($deployment, $id): void {
            $deployment->apply($id, 'owner-a');
        }, 'deploy_lock');
        flock($lock, LOCK_UN);
        fclose($lock);
        unlink($root . '/storage/theme-deploy.lock');
        assertTrue(!is_dir($root . '/themes/tomos-test'), 'theme installed while deploy lock held');
        assertNoCandidateArtifacts($root);
    }, $passes, $failures);

    if (function_exists('pcntl_fork')) {
        check('post-placement failure restores previous theme', function () use ($testRoot): void {
            [$root, $deployment] = environment($testRoot, 'rollback');
            $old = themeEntries('1.0.0', 'body{color:#111;}');
            $old['tomos-test/assets/old.css'] = '.old{}';
            deployPackage($deployment, $root, 'owner-a', $old);

            $deployment = new ThemePackageDeployment($root, $root . '/themes', 'owner-a');
            $new = themeEntries('1.1.0', 'body{color:#222;}');
            for ($i = 0; $i < 150; $i++) {
                $new['tomos-test/assets/extra-' . $i . '.css'] = '.x' . $i . '{}';
            }
            [$id] = inspectPackage($deployment, $root, 'owner-a', $new);
            withWatcher(function () use ($root): bool {
                $backups = glob($root . '/themes/.tomos-theme-backup-*') ?: [];
                $path = $root . '/themes/tomos-test/theme.json';
                if ($backups === [] || !is_file($path)) {
                    return false;
                }
                unlink($path);
                return true;
            }, function () use ($deployment, $id): void {
                try {
                    $deployment->apply($id, 'owner-a');
                } catch (ThemePackageException $exception) {
                    assertTrue(in_array($exception->stage(), ['required_runtime', 'theme_json', 'validator', 'entry_type', 'post_validation'], true), 'unexpected rollback trigger stage');
                    return;
                }
                throw new RuntimeException('post-placement mutation was not rejected');
            });

            $theme = json_decode((string) file_get_contents($root . '/themes/tomos-test/theme.json'), true);
            assertSame('1.0.0', $theme['version'] ?? null);
            assertTrue(is_file($root . '/themes/tomos-test/assets/old.css'), 'old theme was not restored');
            assertNoCandidateArtifacts($root);
            assertNoBackupArtifacts($root);
        }, $passes, $failures);
    }
}

function environment(string $testRoot, string $name): array
{
    $root = $testRoot . DIRECTORY_SEPARATOR . $name;
    mkdir($root . '/storage', 0700, true);
    mkdir($root . '/themes', 0755, true);
    return [$root, new ThemePackageDeployment($root, $root . '/themes', 'owner-a')];
}

function deployPackage(ThemePackageDeployment $deployment, string $root, string $owner, array $entries): array
{
    [$id] = inspectPackage($deployment, $root, $owner, $entries);
    return $deployment->apply($id, $owner);
}

function inspectPackage(ThemePackageDeployment $deployment, string $root, string $owner, array $entries): array
{
    $zipPath = $root . '/package-' . bin2hex(random_bytes(4)) . '.zip';
    makeZip($zipPath, $entries);

    $installerProperty = new ReflectionProperty($deployment, 'installer');
    $installer = $installerProperty->getValue($deployment);
    if (!$installer instanceof ThemePackageInstaller) {
        throw new RuntimeException('deployment installer is unavailable');
    }

    $id = bin2hex(random_bytes(16));
    $temporaryRootProperty = new ReflectionProperty($installer, 'temporaryRoot');
    $temporaryRoot = (string) $temporaryRootProperty->getValue($installer);
    mkdir($temporaryRoot . '/' . $id, 0700, true);
    copy($zipPath, $temporaryRoot . '/' . $id . '/package.zip');

    $inspect = new ReflectionMethod($installer, 'inspectPackage');
    $summary = $inspect->invoke($installer, $id, $owner, true);
    unlink($zipPath);
    return [$id, $summary];
}

function themeEntries(string $version, string $css): array
{
    return [
        'tomos-test/theme.json' => themeJson($version),
        'tomos-test/templates/layout.html' => '<!doctype html><html><body>{{{ page.body }}}</body></html>',
        'tomos-test/templates/page.html' => '<article><h1>{{ page.title }}</h1>{{{ page.content }}}</article>',
        'tomos-test/templates/list.html' => '<main><h1>{{ page.title }}</h1>{{{ list.pages }}}</main>',
        'tomos-test/assets/style.css' => $css,
        'tomos-test/preview.png' => pngBytes(),
        'tomos-test/README.md' => "# Test theme\n",
        'tomos-test/LICENSE' => "Test license\n",
    ];
}

function themeJson(string $version): string
{
    return (string) json_encode([
        'name' => 'tomos-test',
        'display_name' => 'Tomos Test',
        'version' => $version,
        'description' => 'Theme deployment test fixture.',
        'author' => 'Tomos',
    ], JSON_UNESCAPED_SLASHES);
}

function pngBytes(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
}

function makeZip(string $path, array $entries): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('cannot create ZIP');
    }
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, (string) $content);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
    }
    $zip->close();
}

function assertNoCandidateArtifacts(string $root): void
{
    $candidateRoot = $root . '/themes/.tomos-theme-candidates';
    if (!is_dir($candidateRoot)) {
        return;
    }
    $items = array_values(array_diff(scandir($candidateRoot) ?: [], ['.', '..']));
    assertSame([], $items);
}

function assertNoBackupArtifacts(string $root): void
{
    assertSame([], glob($root . '/themes/.tomos-theme-backup-*') ?: []);
}

function expectStage(callable $callback, string $stage): void
{
    try {
        $callback();
    } catch (ThemePackageException $exception) {
        assertSame($stage, $exception->stage());
        return;
    }
    throw new RuntimeException('expected exception was not thrown');
}

function check(string $name, callable $callback, int &$passes, array &$failures): void
{
    try {
        $callback();
        $passes++;
    } catch (Throwable $exception) {
        $failures[] = $name . ': ' . $exception->getMessage();
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function withWatcher(callable $watcher, callable $operation): void
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('cannot fork watcher');
    }
    if ($pid === 0) {
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            if ($watcher()) {
                exit(0);
            }
            usleep(100);
        }
        exit(3);
    }
    $exception = null;
    try {
        $operation();
    } catch (Throwable $caught) {
        $exception = $caught;
    }
    pcntl_waitpid($pid, $status);
    if (pcntl_wexitstatus($status) !== 0) {
        throw new RuntimeException('filesystem watcher did not observe the target state');
    }
    if ($exception instanceof Throwable) {
        throw $exception;
    }
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
