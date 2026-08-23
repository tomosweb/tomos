<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$postIndex = (string) file_get_contents($root . '/post/index.php');
$flatEntry = $root . '/post/site-settings.php';
$legacyEntry = $root . '/post/settings/index.php';
$htaccess = (string) file_get_contents($root . '/.htaccess');

$failures = [];

if (!is_file($flatEntry)) {
    $failures[] = 'flat site settings entrypoint is missing';
}
if (!is_file($legacyEntry)) {
    $failures[] = 'legacy site settings entrypoint is missing';
}

$canonical = '/post/site-settings.php';
$legacy = '/post/settings/';

if (substr_count($postIndex, $canonical) !== 3) {
    $failures[] = 'Tomos Post must use the canonical flat site settings entrypoint for all three routes';
}
if (strpos($postIndex, $legacy) !== false) {
    $failures[] = 'Tomos Post still depends on the legacy directory site settings URL';
}

$flatSource = is_file($flatEntry) ? (string) file_get_contents($flatEntry) : '';
if (strpos($flatSource, "require __DIR__ . '/settings/index.php';") === false) {
    $failures[] = 'flat site settings entrypoint does not delegate to the settings implementation';
}

if (strpos($htaccess, 'RewriteRule ^post/settings/?$ post/site-settings.php [R=302,L]') === false) {
    $failures[] = 'legacy directory URL is not retained as a compatibility redirect';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "site_settings_canonical_entry_check: OK\n";
