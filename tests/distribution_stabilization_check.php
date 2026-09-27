<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$distribution = $root . '/build/tomos';
$version = trim((string) @file_get_contents($root . '/VERSION'));
$passes = 0;

require_once $root . '/core/BlueskyOAuthHtaccessDiagnostics.php';

function checkDistribution(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

function hasRemovedDocsReference(string $contents): bool
{
    return preg_match('/\]\(\s*(?:(?:\.\.\/)|(?:\.\/))?docs\/(?!theme\/theme-rules\.json)/i', $contents) === 1
        || preg_match('/`(?:(?:\.\.\/)|(?:\.\/))?docs\/(?!theme\/theme-rules\.json)[^`]*`/i', $contents) === 1;
}

try {
    checkDistribution(is_dir($distribution), 'fresh Distribution folder exists');
    checkDistribution(is_file($distribution . '/core/BlueskyOAuthHtaccessMigration.php'), 'Distribution contains Bluesky OAuth migration API');
    checkDistribution(
        hash_file('sha256', $root . '/core/BlueskyOAuthHtaccessMigration.php') === hash_file('sha256', $distribution . '/core/BlueskyOAuthHtaccessMigration.php'),
        'Distribution Bluesky OAuth migration API matches source'
    );
    checkDistribution(is_file($distribution . '/core/BlueskyOAuthStaticBacking.php'), 'Distribution contains Bluesky OAuth static backing API');
    checkDistribution(
        hash_file('sha256', $root . '/core/BlueskyOAuthStaticBacking.php') === hash_file('sha256', $distribution . '/core/BlueskyOAuthStaticBacking.php'),
        'Distribution Bluesky OAuth static backing API matches source'
    );
    checkDistribution(!is_file($distribution . '/oauth-client-metadata.static.json'), 'Distribution excludes generated OAuth Metadata backing');
    checkDistribution(!is_file($distribution . '/tomos-bluesky-jwks.static.json'), 'Distribution excludes generated OAuth JWKS backing');
    $sourceOAuthHtaccess = Tomos\BlueskyOAuthHtaccessDiagnostics::inspect($root);
    checkDistribution(($sourceOAuthHtaccess['status'] ?? '') === 'managed', 'source .htaccess has a managed OAuth block');
    checkDistribution(($sourceOAuthHtaccess['safe_for_managed_update'] ?? false) === true, 'source OAuth block is structurally safe');
    $distributionOAuthHtaccess = Tomos\BlueskyOAuthHtaccessDiagnostics::inspect($distribution);
    checkDistribution(($distributionOAuthHtaccess['status'] ?? '') === 'managed', 'Distribution .htaccess has a managed OAuth block');
    checkDistribution(($distributionOAuthHtaccess['safe_for_managed_update'] ?? false) === true, 'Distribution OAuth block is structurally safe');
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

    $markdownReferences = [];
    $markdownIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($distribution, FilesystemIterator::SKIP_DOTS));
    foreach ($markdownIterator as $file) {
        if (strtolower($file->getExtension()) !== 'md') {
            continue;
        }
        if (hasRemovedDocsReference((string) file_get_contents($file->getPathname()))) {
            $markdownReferences[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($distribution) + 1));
        }
    }
    checkDistribution($markdownReferences === [], 'Distribution Markdown has no relative links to removed docs');

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
        $zipMarkdownReferences = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (substr($name, -3) !== '.md') {
                continue;
            }
            $contents = $zip->getFromIndex($index);
            if ($contents !== false && hasRemovedDocsReference($contents)) {
                $zipMarkdownReferences[] = $name;
            }
        }
        checkDistribution($zipMarkdownReferences === [], 'Distribution ZIP Markdown has no relative links to removed docs');
        checkDistribution($zip->locateName('core/webauthn/vendor/lbuchs/webauthn/_test/server.php') === false, 'Distribution ZIP excludes WebAuthn _test');
        foreach (['core/.htaccess', 'cache/.htaccess', 'storage/.htaccess', 'trash/.htaccess'] as $guard) {
            checkDistribution($zip->locateName($guard) !== false, 'Distribution ZIP contains ' . $guard);
        }
        checkDistribution($zip->locateName('oauth-client-metadata.static.json') === false, 'Distribution ZIP excludes generated OAuth Metadata backing');
        checkDistribution($zip->locateName('tomos-bluesky-jwks.static.json') === false, 'Distribution ZIP excludes generated OAuth JWKS backing');
        $zip->close();
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo "distribution_stabilization_check: {$passes} checks passed\n";
