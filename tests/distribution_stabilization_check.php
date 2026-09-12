<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$distribution = $root . '/build/tomos';
$version = trim((string) @file_get_contents($root . '/VERSION'));
$passes = 0;

function checkDistribution(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

try {
    checkDistribution(is_dir($distribution), 'fresh Distribution folder exists');
    $guardContents = "Order allow,deny\nDeny from all\nRequire all denied\n";
    foreach (['core/.htaccess', 'cache/.htaccess', 'storage/.htaccess', 'trash/.htaccess', 'docs/theme/theme-rules.json'] as $required) {
        checkDistribution(is_file($distribution . '/' . $required), 'Distribution contains ' . $required);
    }
    foreach (['core', 'cache', 'storage', 'trash'] as $directory) {
        checkDistribution(file_get_contents($distribution . '/' . $directory . '/.htaccess') === $guardContents, $directory . ' guard is deny-only');
    }

    $docs = [];
    if (is_dir($distribution . '/docs')) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($distribution . '/docs', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $docs[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($distribution) + 1));
            }
        }
    }
    checkDistribution($docs === ['docs/theme/theme-rules.json'], 'Distribution docs contains only the runtime allowlist');

    foreach ([
        'core/webauthn/vendor/lbuchs/webauthn/_test',
        'tests',
        'tools',
        'build',
        'themes/tomos-creator',
        'themes/tomos-radical-poster',
    ] as $forbidden) {
        checkDistribution(!file_exists($distribution . '/' . $forbidden) && !is_link($distribution . '/' . $forbidden), 'Distribution excludes ' . $forbidden);
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($distribution, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        checkDistribution(!preg_match('/\.(tmp|log|bak|orig)\z/i', $file->getFilename()), 'Distribution excludes temporary suffixes');
    }

    checkDistribution(is_file($root . '/core/PerformanceLogger.php'), 'PerformanceLogger product code remains present');
    checkDistribution(is_file($distribution . '/core/PerformanceLogger.php'), 'PerformanceLogger remains in Distribution');
    $rules = json_decode((string) file_get_contents($distribution . '/docs/theme/theme-rules.json'), true);
    checkDistribution(is_array($rules), 'theme-rules runtime JSON remains valid');

    $zipPath = $root . '/build/tomos-' . $version . '.zip';
    if (class_exists(ZipArchive::class) && is_file($zipPath)) {
        $zip = new ZipArchive();
        checkDistribution($zip->open($zipPath) === true, 'Distribution ZIP opens');
        $zipDocs = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (strpos($name, 'docs/') === 0 && substr($name, -1) !== '/') {
                $zipDocs[] = $name;
            }
        }
        sort($zipDocs);
        checkDistribution($zipDocs === ['docs/theme/theme-rules.json'], 'Distribution ZIP docs matches the allowlist');
        checkDistribution($zip->locateName('core/webauthn/vendor/lbuchs/webauthn/_test/server.php') === false, 'Distribution ZIP excludes WebAuthn _test');
        foreach (['core/.htaccess', 'cache/.htaccess', 'storage/.htaccess', 'trash/.htaccess'] as $guard) {
            checkDistribution($zip->locateName($guard) !== false, 'Distribution ZIP contains ' . $guard);
        }
        $zip->close();
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo "distribution_stabilization_check: {$passes} checks passed\n";
