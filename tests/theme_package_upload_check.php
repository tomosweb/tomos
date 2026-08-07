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

use Tomos\ThemePackageException;
use Tomos\ThemePackageInstaller;

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "SKIP: ZipArchive is unavailable.\n");
    exit(2);
}

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-theme-package-' . bin2hex(random_bytes(8));
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
echo "theme_package_upload_check: {$passes} checks passed\n";

function runTests(string $testRoot, int &$passes, array &$failures): void
{
    check('valid package uses two stages and cleans temporary data', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'valid');
        $zip = $root . '/valid.zip';
        makeZip($zip, validEntries());
        [$id, $summary] = inspectZip($installer, $zip, 'owner-a');
        assertSame('tomos-test', $summary['theme_id']);
        assertTrue(!file_exists($root . '/themes/tomos-test'), 'theme exists before apply');
        $result = $installer->apply($id, 'owner-a');
        assertSame('tomos-test', $result['theme_id']);
        assertTrue(is_dir($root . '/themes/tomos-test'), 'theme missing after apply');
        assertTrue(!is_dir($root . '/storage/theme-upload-tmp/' . $id), 'temporary package remains');
        assertTrue(!file_exists($root . '/storage/theme-upload.lock'), 'lock remains');
        assertSame(0644, fileperms($root . '/themes/tomos-test/theme.json') & 0777);
        assertSame(0755, fileperms($root . '/themes/tomos-test/assets') & 0777);
        assertSame(1, (int) (stat($root . '/themes/tomos-test/theme.json')['nlink'] ?? 0));
        $repository = new Tomos\ThemeRepository($root . '/themes');
        assertTrue(!empty($repository->all()['tomos-test']['valid']), 'installed theme is not listed as valid');
    }, $passes, $failures);

    check('ThemeRepository hides dot staging directories', function () use ($testRoot): void {
        [$root] = environment($testRoot, 'hidden-listing');
        mkdir($root . '/themes/.tomos-theme-staging-test/tomos-hidden', 0700, true);
        $repository = new Tomos\ThemeRepository($root . '/themes');
        assertSame([], $repository->all());
    }, $passes, $failures);

    $invalidPackages = [
        'multiple roots' => [array_merge(validEntries(), ['other/theme.json' => '{}']), 'root_count'],
        'root file' => [array_merge(validEntries(), ['orphan.txt' => 'x']), 'root_file'],
        'double theme directory' => [prefixEntries(validEntries(), 'tomos-test/'), 'allowlist'],
        'traversal' => [array_merge(validEntries(), ['tomos-test/../evil.css' => 'x']), 'path'],
        'absolute path' => [array_merge(validEntries(), ['/tomos-test/assets/x.css' => 'x']), 'path'],
        'backslash path' => [array_merge(validEntries(), ['tomos-test/assets\\x.css' => 'x']), 'path'],
        'control character' => [array_merge(validEntries(), ["tomos-test/assets/x\x01.css" => 'x']), 'path'],
        'directory depth' => [array_merge(validEntries(), ['tomos-test/assets/a/b/c/d/e.css' => 'x']), 'depth'],
        'case collision' => [array_merge(validEntries(), ['tomos-test/assets/A.png' => pngBytes(), 'tomos-test/assets/a.png' => pngBytes()]), 'path_collision'],
        'normalization collision' => [array_merge(validEntries(), ["tomos-test/assets/caf\u{00e9}.png" => pngBytes(), "tomos-test/assets/cafe\u{0301}.png" => pngBytes()]), 'path_collision'],
        'PHP extension' => [array_merge(validEntries(), ['tomos-test/assets/x.PHp' => 'x']), 'php'],
        'JavaScript' => [array_merge(validEntries(), ['tomos-test/assets/x.js' => 'x']), 'allowlist'],
        'unlisted extension' => [array_merge(validEntries(), ['tomos-test/assets/x.txt' => 'x']), 'allowlist'],
        'VCS metadata' => [array_merge(validEntries(), ['tomos-test/assets/.git/config' => 'x']), 'path'],
        'temporary file' => [array_merge(validEntries(), ['tomos-test/assets/draft.css~' => 'x']), 'allowlist'],
        'missing theme.json' => [without(validEntries(), 'tomos-test/theme.json'), 'required_runtime'],
        'theme ID mismatch' => [array_replace(validEntries(), ['tomos-test/theme.json' => themeJson('other')]), 'theme_id'],
        'invalid version' => [array_replace(validEntries(), ['tomos-test/theme.json' => themeJson('tomos-test', '1.0')]), 'version'],
        'missing runtime file' => [without(validEntries(), 'tomos-test/templates/list.html'), 'required_runtime'],
        'invalid preview' => [array_replace(validEntries(), ['tomos-test/preview.png' => 'not png']), 'preview'],
    ];
    foreach ($invalidPackages as $name => [$entries, $stage]) {
        check($name, function () use ($testRoot, $name, $entries, $stage): void {
            [$root, $installer] = environment($testRoot, safeName($name));
            $zip = $root . '/invalid.zip';
            makeZip($zip, $entries);
            expectInspectFailure($installer, $zip, $stage);
            assertNoInstallArtifacts($root);
        }, $passes, $failures);
    }

    check('macOS metadata is ignored', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'macos-metadata');
        $zip = $root . '/macos.zip';
        makeZip($zip, array_merge(validEntries(), [
            '__MACOSX/._tomos-test' => 'metadata',
            '__MACOSX/tomos-test/._theme.json' => 'metadata',
            'tomos-test/.DS_Store' => 'metadata',
            'tomos-test/assets/._style.css' => 'metadata',
        ]));
        [$id, $summary] = inspectZip($installer, $zip, 'owner-a');
        assertSame('tomos-test', $summary['theme_id']);
        $installer->apply($id, 'owner-a');
        assertTrue(is_dir($root . '/themes/tomos-test'), 'theme was not installed');
        assertTrue(!file_exists($root . '/themes/tomos-test/.DS_Store'), '.DS_Store was extracted');
        assertTrue(!file_exists($root . '/themes/tomos-test/assets/._style.css'), 'AppleDouble was extracted');
        assertTrue(!is_dir($root . '/themes/__MACOSX'), '__MACOSX was extracted');
    }, $passes, $failures);

    check('missing recommended files only warns', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'recommended-warning');
        $entries = without(validEntries(), 'tomos-test/preview.png');
        $entries = without($entries, 'tomos-test/README.md');
        $entries = without($entries, 'tomos-test/LICENSE');
        $zip = $root . '/recommended-warning.zip';
        makeZip($zip, $entries);
        [$id, $summary] = inspectZip($installer, $zip, 'owner-a');
        assertTrue(!empty($summary['warnings']), 'missing recommended files did not produce a warning');
        $installer->apply($id, 'owner-a');
        assertTrue(is_dir($root . '/themes/tomos-test'), 'theme with missing recommended files was not installed');
    }, $passes, $failures);

    check('duplicate ZIP path', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'duplicate-path');
        $entries = array_merge(validEntries(), [
            'tomos-test/assets/dup-a.png' => pngBytes(),
            'tomos-test/assets/dup-b.png' => pngBytes(),
        ]);
        $zip = $root . '/duplicate.zip';
        makeZip($zip, $entries);
        replaceZipName($zip, 'tomos-test/assets/dup-b.png', 'tomos-test/assets/dup-a.png');
        expectInspectFailure($installer, $zip, null);
        assertNoInstallArtifacts($root);
    }, $passes, $failures);

    check('NUL path', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'nul-path');
        $zip = $root . '/nul.zip';
        makeZip($zip, array_merge(validEntries(), ['tomos-test/assets/nulx.css' => 'x']));
        replaceZipName($zip, 'tomos-test/assets/nulx.css', "tomos-test/assets/nul\0.css");
        expectInspectFailure($installer, $zip, null);
        assertNoInstallArtifacts($root);
    }, $passes, $failures);

    foreach ([
        'symlink' => 0120777,
        'unknown hardlink representation' => 0000644,
        'FIFO special entry' => 0010644,
        'executable file' => 0100755,
    ] as $name => $mode) {
        check($name, function () use ($testRoot, $name, $mode): void {
            [$root, $installer] = environment($testRoot, safeName($name));
            $zip = $root . '/type.zip';
            makeZip($zip, array_merge(validEntries(), ['tomos-test/assets/type.css' => 'x']), [
                'tomos-test/assets/type.css' => $mode,
            ]);
            expectInspectFailure($installer, $zip, 'entry_type');
            assertNoInstallArtifacts($root);
        }, $passes, $failures);
    }

    check('single file size limit', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'file-size');
        $zip = $root . '/large.zip';
        makeZip($zip, array_merge(validEntries(), ['tomos-test/assets/large.woff' => str_repeat('a', 5242881)]));
        expectInspectFailure($installer, $zip, 'file_size');
        assertNoInstallArtifacts($root);
    }, $passes, $failures);

    check('expanded size limit', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'expanded-size');
        $entries = validEntries();
        for ($i = 0; $i < 7; $i++) {
            $entries['tomos-test/assets/large-' . $i . '.woff'] = str_repeat(chr(65 + $i), 5 * 1024 * 1024);
        }
        $zip = $root . '/expanded.zip';
        makeZip($zip, $entries);
        expectInspectFailure($installer, $zip, 'expanded_size');
        assertNoInstallArtifacts($root);
    }, $passes, $failures);

    check('entry count limit', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'entry-count');
        $entries = validEntries();
        for ($i = 0; $i < 193; $i++) {
            $entries['tomos-test/assets/file-' . $i . '.css'] = 'x';
        }
        $zip = $root . '/many.zip';
        makeZip($zip, $entries);
        expectInspectFailure($installer, $zip, 'entry_count');
        assertNoInstallArtifacts($root);
    }, $passes, $failures);

    check('duplicate installed theme', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'duplicate-theme');
        mkdir($root . '/themes/tomos-test', 0755);
        $zip = $root . '/valid.zip';
        makeZip($zip, validEntries());
        expectInspectFailure($installer, $zip, 'duplicate_theme');
        assertTrue(is_dir($root . '/themes/tomos-test'), 'existing theme changed');
        assertTrue(!file_exists($root . '/storage/theme-upload.lock'), 'lock remains');
    }, $passes, $failures);

    check('duplicate theme created after inspection', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'duplicate-after-inspection');
        $zip = $root . '/valid.zip';
        makeZip($zip, validEntries());
        [$id] = inspectZip($installer, $zip, 'owner-a');
        mkdir($root . '/themes/tomos-test', 0755);
        file_put_contents($root . '/themes/tomos-test/existing.txt', 'existing');
        expectExceptionStage(function () use ($installer, $id): void {
            $installer->apply($id, 'owner-a');
        }, 'duplicate_theme');
        assertSame('existing', file_get_contents($root . '/themes/tomos-test/existing.txt'));
        assertTrue(!is_dir($root . '/storage/theme-upload-tmp/' . $id), 'temporary package remains');
        assertTrue(!file_exists($root . '/storage/theme-upload.lock'), 'lock remains');
    }, $passes, $failures);

    check('owner mismatch', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'owner');
        $zip = $root . '/valid.zip';
        makeZip($zip, validEntries());
        [$id] = inspectZip($installer, $zip, 'owner-a');
        expectExceptionStage(function () use ($installer, $id): void {
            $installer->apply($id, 'owner-b');
        }, 'record');
        assertNoInstallArtifacts($root);
    }, $passes, $failures);

    check('expired confirmation', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'expired');
        $zip = $root . '/valid.zip';
        makeZip($zip, validEntries());
        [$id] = inspectZip($installer, $zip, 'owner-a');
        $recordPath = $root . '/storage/theme-upload-tmp/' . $id . '/record.json';
        $record = json_decode((string) file_get_contents($recordPath), true);
        $record['expires_at'] = time() - 1;
        file_put_contents($recordPath, json_encode($record));
        expectExceptionStage(function () use ($installer, $id): void {
            $installer->apply($id, 'owner-a');
        }, 'record');
        assertNoInstallArtifacts($root);
    }, $passes, $failures);

    check('tampered package record', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'tampered-record');
        $zip = $root . '/valid.zip';
        makeZip($zip, validEntries());
        [$id] = inspectZip($installer, $zip, 'owner-a');
        file_put_contents($root . '/storage/theme-upload-tmp/' . $id . '/package.zip', 'tampered', FILE_APPEND);
        expectExceptionStage(function () use ($installer, $id): void {
            $installer->apply($id, 'owner-a');
        }, 'record');
        assertNoInstallArtifacts($root);
    }, $passes, $failures);

    check('lock acquisition failure', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'lock');
        $zip = $root . '/valid.zip';
        makeZip($zip, validEntries());
        [$id] = inspectZip($installer, $zip, 'owner-a');
        $lock = fopen($root . '/storage/theme-upload.lock', 'x');
        flock($lock, LOCK_EX);
        fwrite($lock, json_encode(['started_at' => time(), 'owner_hash' => hash('sha256', 'other')]));
        expectExceptionStage(function () use ($installer, $id): void {
            $installer->apply($id, 'owner-a');
        }, 'lock');
        assertTrue(!is_dir($root . '/themes/tomos-test'), 'theme installed while lock held');
        assertTrue(!is_dir($root . '/storage/theme-upload-tmp/' . $id), 'temporary package remains');
        flock($lock, LOCK_UN);
        fclose($lock);
        unlink($root . '/storage/theme-upload.lock');
    }, $passes, $failures);

    check('unheld stale lock is replaced', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'stale-lock');
        $zip = $root . '/valid.zip';
        makeZip($zip, validEntries());
        [$id] = inspectZip($installer, $zip, 'owner-a');
        file_put_contents($root . '/storage/theme-upload.lock', json_encode([
            'started_at' => time() - 601,
            'owner_hash' => hash('sha256', 'old-owner'),
        ]));
        $result = $installer->apply($id, 'owner-a');
        assertSame('tomos-test', $result['theme_id']);
        assertTrue(!file_exists($root . '/storage/theme-upload.lock'), 'replacement lock remains');
    }, $passes, $failures);

    if (function_exists('pcntl_fork')) {
        check('hidden staging validation failure rolls back', function () use ($testRoot): void {
            [$root, $installer] = environment($testRoot, 'hidden-validation');
            $zip = $root . '/valid.zip';
            makeZip($zip, validEntries());
            [$id] = inspectZip($installer, $zip, 'owner-a');
            withWatcher(function () use ($root): bool {
                $matches = glob($root . '/themes/.tomos-theme-staging-*/tomos-test/theme.json') ?: [];
                if ($matches === []) {
                    return false;
                }
                file_put_contents($matches[0], '{invalid');
                return true;
            }, function () use ($installer, $id): void {
                try {
                    $installer->apply($id, 'owner-a');
                } catch (ThemePackageException $exception) {
                    assertTrue(in_array($exception->stage(), ['theme_json', 'validator', 'record'], true), 'unexpected staging failure');
                    return;
                }
                throw new RuntimeException('staging mutation was not rejected');
            });
            assertNoInstallArtifacts($root);
            assertNoHiddenStaging($root);
        }, $passes, $failures);

        check('rename failure leaves no incomplete theme', function () use ($testRoot): void {
            [$root, $installer] = environment($testRoot, 'rename-failure');
            $zip = $root . '/valid.zip';
            makeZip($zip, validEntries());
            [$id] = inspectZip($installer, $zip, 'owner-a');
            try {
                withWatcher(function () use ($root): bool {
                    $matches = glob($root . '/themes/.tomos-theme-staging-*/tomos-test/theme.json') ?: [];
                    if ($matches === []) {
                        return false;
                    }
                    chmod($root . '/themes', 0555);
                    usleep(20000);
                    chmod($root . '/themes', 0755);
                    return true;
                }, function () use ($installer, $id): void {
                    expectExceptionStage(function () use ($installer, $id): void {
                        $installer->apply($id, 'owner-a');
                    }, 'rename');
                });
            } finally {
                chmod($root . '/themes', 0755);
            }
            assertNoInstallArtifacts($root);
            assertNoHiddenStaging($root);
        }, $passes, $failures);

        check('post-placement validation failure removes new theme', function () use ($testRoot): void {
            [$root, $installer] = environment($testRoot, 'post-validation');
            $zip = $root . '/valid.zip';
            makeZip($zip, validEntries());
            [$id] = inspectZip($installer, $zip, 'owner-a');
            withWatcher(function () use ($root): bool {
                $path = $root . '/themes/tomos-test/theme.json';
                if (!is_file($path)) {
                    return false;
                }
                unlink($path);
                return true;
            }, function () use ($installer, $id): void {
                expectExceptionStage(function () use ($installer, $id): void {
                    $installer->apply($id, 'owner-a');
                }, 'post_validation');
            });
            assertNoInstallArtifacts($root);
            assertNoHiddenStaging($root);
        }, $passes, $failures);
    }

    check('stale temporary cleanup', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'cleanup');
        $stale = $root . '/storage/theme-upload-tmp/' . str_repeat('a', 32);
        mkdir($stale, 0700, true);
        file_put_contents($stale . '/record.json', '{}');
        touch($stale, time() - 3700);
        $installer->cleanupStaleTemporaryFiles();
        assertTrue(!is_dir($stale), 'stale package remains');
    }, $passes, $failures);

    check('upload error mapping', function () use ($testRoot): void {
        [, $installer] = environment($testRoot, 'upload-error');
        expectExceptionStage(function () use ($installer): void {
            $installer->stageUpload(['error' => UPLOAD_ERR_INI_SIZE], 'owner');
        }, 'upload_size');
        expectExceptionStage(function () use ($installer): void {
            $installer->stageUpload(['error' => UPLOAD_ERR_NO_FILE], 'owner');
        }, 'upload');
    }, $passes, $failures);

    check('non-ZIP filename', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'non-zip');
        $plain = $root . '/plain.txt';
        file_put_contents($plain, 'plain');
        expectExceptionStage(function () use ($installer, $plain): void {
            $installer->stageUpload([
                'error' => UPLOAD_ERR_OK,
                'name' => 'theme.txt',
                'tmp_name' => $plain,
                'size' => 5,
            ], 'owner');
        }, 'upload_type');
    }, $passes, $failures);

    check('upload ZIP size limit', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'upload-size');
        $plain = $root . '/large.zip';
        file_put_contents($plain, 'x');
        expectExceptionStage(function () use ($installer, $plain): void {
            $installer->stageUpload([
                'error' => UPLOAD_ERR_OK,
                'name' => 'theme.zip',
                'tmp_name' => $plain,
                'size' => Tomos\ThemePackagePolicy::MAX_ZIP_BYTES + 1,
            ], 'owner');
        }, 'upload_size');
    }, $passes, $failures);

    check('invalid ZIP body', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'invalid-zip');
        $zip = $root . '/invalid.zip';
        file_put_contents($zip, 'not a zip');
        expectInspectFailure($installer, $zip, 'zip_open');
        assertNoInstallArtifacts($root);
    }, $passes, $failures);

    check('empty ZIP', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'empty-zip');
        $zipPath = $root . '/empty.zip';
        file_put_contents($zipPath, "PK\x05\x06" . str_repeat("\0", 18));
        expectInspectFailure($installer, $zipPath, 'zip_empty');
        assertNoInstallArtifacts($root);
    }, $passes, $failures);

    check('encrypted ZIP entry', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'encrypted-zip');
        $zipPath = $root . '/encrypted.zip';
        makeZip($zipPath, validEntries());
        $zip = new ZipArchive();
        $zip->open($zipPath);
        if (!$zip->setEncryptionName('tomos-test/assets/style.css', ZipArchive::EM_AES_256, 'password')) {
            $zip->close();
            return;
        }
        $zip->close();
        expectInspectFailure($installer, $zipPath, 'encryption');
        assertNoInstallArtifacts($root);
    }, $passes, $failures);

    check('unsupported compression method', function () use ($testRoot): void {
        [$root, $installer] = environment($testRoot, 'compression');
        $zipPath = $root . '/compression.zip';
        makeZip($zipPath, validEntries());
        $zip = new ZipArchive();
        $zip->open($zipPath);
        if (!$zip->setCompressionName('tomos-test/assets/style.css', ZipArchive::CM_BZIP2)) {
            $zip->close();
            return;
        }
        $zip->close();
        expectInspectFailure($installer, $zipPath, 'compression');
        assertNoInstallArtifacts($root);
    }, $passes, $failures);
}

function validEntries(): array
{
    return [
        'tomos-test/theme.json' => themeJson('tomos-test'),
        'tomos-test/templates/layout.html' => '<!doctype html><html><body>{{{ page.body }}}</body></html>',
        'tomos-test/templates/page.html' => '<article><h1>{{ page.title }}</h1>{{{ page.content }}}</article>',
        'tomos-test/templates/list.html' => '<main><h1>{{ page.title }}</h1>{{{ list.pages }}}</main>',
        'tomos-test/assets/style.css' => 'body { color: #222; }',
        'tomos-test/preview.png' => pngBytes(),
        'tomos-test/README.md' => "# Test theme\n",
        'tomos-test/LICENSE' => "Test license\n",
    ];
}

function themeJson(string $name, string $version = '1.0.0'): string
{
    return (string) json_encode([
        'name' => $name,
        'display_name' => 'Tomos Test',
        'version' => $version,
        'description' => 'Theme package test fixture.',
        'author' => 'Tomos',
    ], JSON_UNESCAPED_SLASHES);
}

function pngBytes(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
}

function environment(string $testRoot, string $name): array
{
    $root = $testRoot . DIRECTORY_SEPARATOR . $name;
    mkdir($root . '/storage', 0700, true);
    mkdir($root . '/themes', 0755, true);
    return [$root, new ThemePackageInstaller($root, $root . '/themes')];
}

function inspectZip(ThemePackageInstaller $installer, string $zipPath, string $owner): array
{
    $id = bin2hex(random_bytes(16));
    $rootProperty = new ReflectionProperty($installer, 'temporaryRoot');
    $temporaryRoot = (string) $rootProperty->getValue($installer);
    mkdir($temporaryRoot . '/' . $id, 0700, true);
    copy($zipPath, $temporaryRoot . '/' . $id . '/package.zip');
    $method = new ReflectionMethod($installer, 'inspectPackage');
    try {
        $summary = $method->invoke($installer, $id, $owner, true);
        return [$id, $summary];
    } catch (Throwable $exception) {
        $installer->discard($id);
        throw $exception;
    }
}

function expectInspectFailure(ThemePackageInstaller $installer, string $zipPath, ?string $stage): void
{
    try {
        inspectZip($installer, $zipPath, 'owner');
    } catch (ThemePackageException $exception) {
        if ($stage !== null) {
            assertSame($stage, $exception->stage());
        }
        return;
    }
    throw new RuntimeException('package was accepted');
}

function makeZip(string $path, array $entries, array $modes = []): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('cannot create ZIP');
    }
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, (string) $content);
        $mode = $modes[$name] ?? 0100644;
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16);
    }
    $zip->close();
}

function replaceZipName(string $path, string $from, string $to): void
{
    if (strlen($from) !== strlen($to)) {
        throw new RuntimeException('ZIP replacement names must have equal length');
    }
    $raw = (string) file_get_contents($path);
    $replaced = str_replace($from, $to, $raw, $count);
    if ($count < 2) {
        throw new RuntimeException('ZIP filename was not found in local and central records');
    }
    file_put_contents($path, $replaced);
}

function prefixEntries(array $entries, string $prefix): array
{
    $result = [];
    foreach ($entries as $name => $content) {
        $result[$prefix . $name] = $content;
    }
    return $result;
}

function without(array $entries, string $key): array
{
    unset($entries[$key]);
    return $entries;
}

function safeName(string $name): string
{
    return preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?: 'case';
}

function assertNoInstallArtifacts(string $root): void
{
    assertTrue(!is_dir($root . '/themes/tomos-test'), 'incomplete theme remains');
    assertTrue(!file_exists($root . '/storage/theme-upload.lock'), 'lock remains');
    $temporary = $root . '/storage/theme-upload-tmp';
    $items = is_dir($temporary) ? array_values(array_diff(scandir($temporary) ?: [], ['.', '..'])) : [];
    assertSame([], $items);
}

function expectExceptionStage(callable $callback, string $stage): void
{
    try {
        $callback();
    } catch (ThemePackageException $exception) {
        assertSame($stage, $exception->stage());
        return;
    }
    throw new RuntimeException('expected exception was not thrown');
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

function check(string $name, callable $callback, int &$passes, array &$failures): void
{
    try {
        $callback();
        $passes++;
    } catch (Throwable $exception) {
        $failures[] = $name . ': ' . $exception->getMessage();
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

function assertNoHiddenStaging(string $root): void
{
    $matches = glob($root . '/themes/.tomos-theme-staging-*') ?: [];
    assertSame([], $matches);
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
