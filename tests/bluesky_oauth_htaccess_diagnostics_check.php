<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/BlueskyOAuthHtaccessDiagnostics.php';

use Tomos\BlueskyOAuthHtaccessDiagnostics;

function htaccessDiagnosticsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function htaccessDiagnosticsFixture(string $contents): string
{
    $root = sys_get_temp_dir() . '/tomos-oauth-htaccess-' . bin2hex(random_bytes(6));
    htaccessDiagnosticsAssert(mkdir($root, 0700, true), 'fixture root could not be created');
    if ($contents !== '') {
        htaccessDiagnosticsAssert(file_put_contents($root . '/.htaccess', $contents, LOCK_EX) !== false, 'fixture .htaccess could not be written');
    }
    return $root;
}

function htaccessDiagnosticsRemoveTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        htaccessDiagnosticsRemoveTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

function htaccessDiagnosticsAssertState(string $name, array $result, string $status, bool $safe, array $issues = []): void
{
    htaccessDiagnosticsAssert(($result['status'] ?? null) === $status, $name . ': status mismatch');
    htaccessDiagnosticsAssert(($result['safe_for_managed_update'] ?? null) === $safe, $name . ': safety mismatch');
    foreach ($issues as $issue) {
        htaccessDiagnosticsAssert(in_array($issue, $result['issues'] ?? [], true), $name . ': missing issue ' . $issue);
    }
}

$managed = "<IfModule mod_rewrite.c>\nRewriteEngine On\n# BEGIN TOMOS MANAGED OAUTH\nRewriteRule ^oauth-client-metadata\\.json$ oauth-client-metadata.json.php [L]\nRewriteRule ^\\.well-known/tomos-bluesky-jwks\\.json$ tomos-bluesky-jwks.json.php [L]\n# END TOMOS MANAGED OAUTH\nRewriteRule ^ index.php [L]\n</IfModule>\n";
$staticManaged = "<IfModule mod_rewrite.c>\nRewriteEngine On\n"
    . implode("\n", BlueskyOAuthHtaccessDiagnostics::managedBlockLines())
    . "\nRewriteRule ^ index.php [L]\n</IfModule>\n";
$legacyManaged = "<IfModule mod_rewrite.c>\nRewriteEngine On\n"
    . implode("\n", BlueskyOAuthHtaccessDiagnostics::legacyManagedBlockLines())
    . "\nRewriteRule ^ index.php [L]\n</IfModule>\n";
$legacy = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^oauth-client-metadata\\.json$ oauth-client-metadata.json.php [L]\nRewriteRule ^\\.well-known/tomos-bluesky-jwks\\.json$ tomos-bluesky-jwks.json.php [L]\nRewriteRule ^ index.php [L]\n</IfModule>\n";
$noRules = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^ index.php [L]\n</IfModule>\n";
$beginOnly = str_replace(BlueskyOAuthHtaccessDiagnostics::END_MARKER . "\n", '', $managed);
$endOnly = str_replace(BlueskyOAuthHtaccessDiagnostics::BEGIN_MARKER . "\n", '', $managed);
$duplicate = $managed . $managed;
$metadataOnly = str_replace("RewriteRule ^\\.well-known/tomos-bluesky-jwks\\.json$ tomos-bluesky-jwks.json.php [L]\n", '', $legacy);
$jwksOnly = str_replace("RewriteRule ^oauth-client-metadata\\.json$ oauth-client-metadata.json.php [L]\n", '', $legacy);
$outsideModule = "# BEGIN TOMOS MANAGED OAUTH\nRewriteRule ^oauth-client-metadata\\.json$ oauth-client-metadata.json.php [L]\nRewriteRule ^\\.well-known/tomos-bluesky-jwks\\.json$ tomos-bluesky-jwks.json.php [L]\n# END TOMOS MANAGED OAUTH\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^ index.php [L]\n</IfModule>\n";
$afterCatchAll = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^ index.php [L]\n" . str_replace("<IfModule mod_rewrite.c>\nRewriteEngine On\n", '', $managed);
$ruleOutsideMarker = str_replace(
    "RewriteRule ^ index.php [L]\n</IfModule>\n",
    "RewriteRule ^oauth-client-metadata\\.json$ oauth-client-metadata.json.php [L]\nRewriteRule ^ index.php [L]\n</IfModule>\n",
    $managed
);

$fixtures = [
    'managed' => [$managed, 'managed', true, []],
    'static_managed' => [$staticManaged, 'managed', true, []],
    'legacy_managed' => [$legacyManaged, 'managed', true, []],
    'legacy' => [$legacy, 'legacy', false, ['marker_missing']],
    'unmanaged' => [$noRules, 'unmanaged', false, ['oauth_rules_missing']],
    'begin_only' => [$beginOnly, 'invalid', false, ['marker_structure_invalid']],
    'end_only' => [$endOnly, 'invalid', false, ['marker_structure_invalid']],
    'duplicate' => [$duplicate, 'invalid', false, ['marker_structure_invalid']],
    'metadata_only' => [$metadataOnly, 'legacy', false, ['marker_missing']],
    'jwks_only' => [$jwksOnly, 'legacy', false, ['marker_missing']],
    'outside_module' => [$outsideModule, 'invalid', false, ['marker_outside_mod_rewrite']],
    'after_catch_all' => [$afterCatchAll, 'invalid', false, ['marker_after_generic_catch_all', 'oauth_rule_after_generic_catch_all']],
    'rule_outside_marker' => [$ruleOutsideMarker, 'invalid', false, ['metadata_rule_outside_marker']],
];

$roots = [];
try {
    foreach ($fixtures as $name => [$contents, $status, $safe, $issues]) {
        $root = htaccessDiagnosticsFixture($contents);
        $roots[] = $root;
        $before = (string) file_get_contents($root . '/.htaccess');
        $result = BlueskyOAuthHtaccessDiagnostics::inspect($root);
        htaccessDiagnosticsAssertState($name, $result, $status, $safe, $issues);
        if ($name === 'static_managed') {
            htaccessDiagnosticsAssert(($result['managed_block_version'] ?? '') === 'static', 'static managed: version mismatch');
            htaccessDiagnosticsAssert(!empty($result['static_backing_enabled']), 'static managed: static backing not reported');
        }
        if ($name === 'legacy_managed') {
            htaccessDiagnosticsAssert(($result['managed_block_version'] ?? '') === 'legacy', 'legacy managed: version mismatch');
            htaccessDiagnosticsAssert(!empty($result['safe_for_static_backing_migration']), 'legacy managed: safe migration not reported');
        }
        htaccessDiagnosticsAssert((string) file_get_contents($root . '/.htaccess') === $before, $name . ': diagnostic changed .htaccess');
    }

    $symlinkRoot = htaccessDiagnosticsFixture($managed);
    $roots[] = $symlinkRoot;
    unlink($symlinkRoot . '/.htaccess');
    htaccessDiagnosticsAssert(symlink('/tmp/nonexistent-tomos-oauth-target', $symlinkRoot . '/.htaccess'), 'symlink fixture could not be created');
    $symlinkResult = BlueskyOAuthHtaccessDiagnostics::inspect($symlinkRoot);
    htaccessDiagnosticsAssertState('symlink', $symlinkResult, 'symlink', false, ['htaccess_symlink']);

    $missingRoot = htaccessDiagnosticsFixture('');
    $roots[] = $missingRoot;
    $missingResult = BlueskyOAuthHtaccessDiagnostics::inspect($missingRoot);
    htaccessDiagnosticsAssertState('missing', $missingResult, 'missing', false, ['htaccess_missing']);
} finally {
    foreach ($roots as $root) {
        htaccessDiagnosticsRemoveTree($root);
    }
}

echo "bluesky_oauth_htaccess_diagnostics_check: passed\n";
