<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/BlueskyOAuthHtaccessDiagnostics.php';
require_once dirname(__DIR__) . '/core/BlueskyOAuthHtaccessMigration.php';

use Tomos\BlueskyOAuthHtaccessDiagnostics;
use Tomos\BlueskyOAuthHtaccessMigration;

function htaccessMigrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function htaccessMigrationRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        htaccessMigrationRemoveTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

function htaccessMigrationRoot(string $contents, bool $backupReady = true): string
{
    $root = sys_get_temp_dir() . '/tomos-oauth-migration-' . bin2hex(random_bytes(6));
    htaccessMigrationAssert(mkdir($root, 0700, true), 'fixture root could not be created');
    htaccessMigrationAssert(file_put_contents($root . '/.htaccess', $contents, LOCK_EX) !== false, 'fixture .htaccess could not be written');
    if ($backupReady) {
        htaccessMigrationAssert(mkdir($root . '/storage/update-backups', 0700, true), 'backup directory could not be created');
    }
    return $root;
}

function htaccessMigrationManaged(): string
{
    return "<IfModule mod_rewrite.c>\nRewriteEngine On\n"
        . implode("\n", BlueskyOAuthHtaccessDiagnostics::managedBlockLines())
        . "\nRewriteRule ^ index.php [L]\n</IfModule>\n";
}

function htaccessMigrationLegacyManaged(): string
{
    return "<IfModule mod_rewrite.c>\nRewriteEngine On\n"
        . "# preserve before managed block\n"
        . implode("\n", BlueskyOAuthHtaccessDiagnostics::legacyManagedBlockLines())
        . "\n# preserve after managed block\nRewriteRule ^ index.php [L]\n</IfModule>\n";
}

function htaccessMigrationLegacy(): string
{
    return "<IfModule mod_rewrite.c>\nRewriteEngine On\n"
        . "RewriteBase /tomos/\n"
        . "# preserve this comment\n"
        . "RewriteCond %{HTTPS} !=on\n"
        . "RewriteRule ^force-https$ https://example.test%{REQUEST_URI} [R=301,L]\n"
        . BlueskyOAuthHtaccessDiagnostics::METADATA_RULE . "\n"
        . "# preserve between OAuth rules\n"
        . BlueskyOAuthHtaccessDiagnostics::JWKS_RULE . "\n"
        . "AuthType Basic\n"
        . "RewriteRule ^ index.php [L]\n"
        . "</IfModule>\n";
}

function htaccessMigrationWithoutOAuth(): string
{
    return "<IfModule mod_rewrite.c>\nRewriteEngine On\n"
        . "RewriteBase /tomos/\n"
        . "# preserve this comment\n"
        . "AuthType Basic\n"
        . "RewriteRule ^ index.php [L]\n"
        . "</IfModule>\n";
}

function htaccessMigrationAssertState(string $name, array $result, string $status, bool $canMigrate, array $issues = []): void
{
    htaccessMigrationAssert(($result['status'] ?? null) === $status, $name . ': status mismatch');
    htaccessMigrationAssert(($result['can_migrate'] ?? null) === $canMigrate, $name . ': can_migrate mismatch');
    foreach ($issues as $issue) {
        htaccessMigrationAssert(in_array($issue, $result['issues'] ?? [], true), $name . ': missing issue ' . $issue);
    }
}

$roots = [];
try {
    $legacy = htaccessMigrationLegacy();
    $legacyRoot = htaccessMigrationRoot($legacy);
    $roots[] = $legacyRoot;
    $legacyPlan = BlueskyOAuthHtaccessMigration::inspectMigration($legacyRoot);
    htaccessMigrationAssertState('legacy inspect', $legacyPlan, 'legacy', true);
    htaccessMigrationAssert((string) file_get_contents($legacyRoot . '/.htaccess') === $legacy, 'legacy inspect changed .htaccess');

    $legacyResult = BlueskyOAuthHtaccessMigration::migrate($legacyRoot);
    htaccessMigrationAssertState('legacy migrate', $legacyResult, 'migrated', false);
    htaccessMigrationAssert(($legacyResult['previous_status'] ?? '') === 'legacy', 'legacy migration previous status');
    htaccessMigrationAssert(($legacyResult['current_status'] ?? '') === 'managed', 'legacy migration current status');
    htaccessMigrationAssert(($legacyResult['backup_created'] ?? false) === true, 'legacy migration backup missing');
    htaccessMigrationAssert(($legacyResult['rolled_back'] ?? false) === false, 'legacy migration unexpectedly rolled back');
    $legacyAfter = (string) file_get_contents($legacyRoot . '/.htaccess');
    htaccessMigrationAssert(BlueskyOAuthHtaccessDiagnostics::inspect($legacyRoot)['status'] === 'managed', 'legacy migration did not produce managed status');
    htaccessMigrationAssert(strpos($legacyAfter, 'RewriteBase /tomos/') !== false, 'RewriteBase was not preserved');
    htaccessMigrationAssert(strpos($legacyAfter, 'AuthType Basic') !== false, 'Basic auth line was not preserved');
    htaccessMigrationAssert(strpos($legacyAfter, '# preserve this comment') !== false, 'comments were not preserved');
    htaccessMigrationAssert(strpos($legacyAfter, 'https://example.test%{REQUEST_URI}') !== false, 'HTTPS redirect was not preserved');
    $backupId = (string) ($legacyResult['backup_id'] ?? '');
    htaccessMigrationAssert($backupId !== '', 'legacy migration backup id missing');
    $backupPath = $legacyRoot . '/storage/update-backups/' . $backupId . '/files/.htaccess';
    htaccessMigrationAssert(is_file($backupPath), 'legacy migration backup file missing');
    htaccessMigrationAssert((string) file_get_contents($backupPath) === $legacy, 'legacy backup does not preserve original bytes');

    $rollbackResult = BlueskyOAuthHtaccessMigration::rollback($legacyRoot, $backupId);
    htaccessMigrationAssert(($rollbackResult['status'] ?? '') === 'rolled_back', 'rollback did not succeed');
    htaccessMigrationAssert((string) file_get_contents($legacyRoot . '/.htaccess') === $legacy, 'rollback did not restore original bytes');
    htaccessMigrationAssert(BlueskyOAuthHtaccessDiagnostics::inspect($legacyRoot)['status'] === 'legacy', 'rollback status is not legacy');

    $unmanagedRoot = htaccessMigrationRoot(htaccessMigrationWithoutOAuth());
    $roots[] = $unmanagedRoot;
    htaccessMigrationAssertState('unmanaged inspect', BlueskyOAuthHtaccessMigration::inspectMigration($unmanagedRoot), 'unmanaged', true);
    $unmanagedResult = BlueskyOAuthHtaccessMigration::migrate($unmanagedRoot);
    htaccessMigrationAssertState('unmanaged migrate', $unmanagedResult, 'migrated', false);
    htaccessMigrationAssert(BlueskyOAuthHtaccessDiagnostics::inspect($unmanagedRoot)['status'] === 'managed', 'unmanaged migration did not produce managed status');

    $managedRoot = htaccessMigrationRoot(htaccessMigrationManaged());
    $roots[] = $managedRoot;
    $managedBefore = (string) file_get_contents($managedRoot . '/.htaccess');
    $managedResult = BlueskyOAuthHtaccessMigration::migrate($managedRoot);
    htaccessMigrationAssertState('managed no-op', $managedResult, 'not_needed', false);
    htaccessMigrationAssert(($managedResult['backup_created'] ?? true) === false, 'managed no-op created a backup');
    htaccessMigrationAssert((string) file_get_contents($managedRoot . '/.htaccess') === $managedBefore, 'managed no-op changed .htaccess');

    $legacyManaged = htaccessMigrationLegacyManaged();
    $legacyManagedRoot = htaccessMigrationRoot($legacyManaged);
    $roots[] = $legacyManagedRoot;
    $legacyManagedPlan = BlueskyOAuthHtaccessMigration::inspectMigration($legacyManagedRoot);
    htaccessMigrationAssertState('legacy managed inspect', $legacyManagedPlan, 'managed', true);
    htaccessMigrationAssert(($legacyManagedPlan['migration_mode'] ?? '') === 'managed_static_backing', 'legacy managed migration mode mismatch');
    $legacyManagedResult = BlueskyOAuthHtaccessMigration::migrate($legacyManagedRoot);
    htaccessMigrationAssertState('legacy managed migrate', $legacyManagedResult, 'migrated', false);
    $legacyManagedAfter = (string) file_get_contents($legacyManagedRoot . '/.htaccess');
    htaccessMigrationAssert(strpos($legacyManagedAfter, '# preserve before managed block') !== false, 'legacy managed prefix changed');
    htaccessMigrationAssert(strpos($legacyManagedAfter, '# preserve after managed block') !== false, 'legacy managed suffix changed');
    htaccessMigrationAssert(strpos($legacyManagedAfter, BlueskyOAuthHtaccessDiagnostics::STATIC_METADATA_RULE) !== false, 'legacy managed static metadata rule missing');
    htaccessMigrationAssert(BlueskyOAuthHtaccessDiagnostics::inspect($legacyManagedRoot)['static_backing_enabled'] === true, 'legacy managed migration did not enable static backing');
    $legacyManagedBackupId = (string) ($legacyManagedResult['backup_id'] ?? '');
    htaccessMigrationAssert($legacyManagedBackupId !== '', 'legacy managed backup id missing');
    $legacyManagedRollback = BlueskyOAuthHtaccessMigration::rollback($legacyManagedRoot, $legacyManagedBackupId);
    htaccessMigrationAssert(($legacyManagedRollback['status'] ?? '') === 'rolled_back', 'legacy managed rollback did not succeed');
    htaccessMigrationAssert((string) file_get_contents($legacyManagedRoot . '/.htaccess') === $legacyManaged, 'legacy managed rollback did not restore original bytes');

    $cliRoot = htaccessMigrationRoot(htaccessMigrationLegacyManaged());
    $roots[] = $cliRoot;
    htaccessMigrationAssert(mkdir($cliRoot . '/core', 0700, true), 'CLI fixture core directory could not be created');
    copy(dirname(__DIR__) . '/core/BlueskyOAuthHtaccessDiagnostics.php', $cliRoot . '/core/BlueskyOAuthHtaccessDiagnostics.php');
    copy(dirname(__DIR__) . '/core/BlueskyOAuthHtaccessMigration.php', $cliRoot . '/core/BlueskyOAuthHtaccessMigration.php');
    $cliCommand = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(dirname(__DIR__) . '/tools/migrate-bluesky-oauth-static-backing.php')
        . ' --root=' . escapeshellarg($cliRoot);
    exec($cliCommand . ' 2>&1', $cliOutput, $cliStatus);
    htaccessMigrationAssert($cliStatus === 0, 'explicit migration CLI failed: ' . implode("\n", $cliOutput));
    htaccessMigrationAssert(BlueskyOAuthHtaccessDiagnostics::inspect($cliRoot)['static_backing_enabled'] === true, 'explicit migration CLI did not enable static backing');

    $fixtures = [
        'begin_only' => [str_replace(BlueskyOAuthHtaccessDiagnostics::END_MARKER . "\n", '', htaccessMigrationManaged()), ['diagnostic_status_not_migratable']],
        'end_only' => [str_replace(BlueskyOAuthHtaccessDiagnostics::BEGIN_MARKER . "\n", '', htaccessMigrationManaged()), ['diagnostic_status_not_migratable']],
        'marker_duplicate' => [htaccessMigrationManaged() . htaccessMigrationManaged(), ['diagnostic_status_not_migratable']],
        'rewrite_duplicate' => [str_replace(BlueskyOAuthHtaccessDiagnostics::JWKS_RULE . "\n", BlueskyOAuthHtaccessDiagnostics::JWKS_RULE . "\n" . BlueskyOAuthHtaccessDiagnostics::JWKS_RULE . "\n", htaccessMigrationLegacy()), ['oauth_rule_incomplete_or_duplicate']],
        'metadata_only' => [str_replace(BlueskyOAuthHtaccessDiagnostics::JWKS_RULE . "\n", '', htaccessMigrationLegacy()), ['oauth_rule_incomplete_or_duplicate']],
        'jwks_only' => [str_replace(BlueskyOAuthHtaccessDiagnostics::METADATA_RULE . "\n", '', htaccessMigrationLegacy()), ['oauth_rule_incomplete_or_duplicate']],
        'modified_rule' => [str_replace(BlueskyOAuthHtaccessDiagnostics::METADATA_RULE, 'RewriteRule ^oauth-client-metadata\\.json$ other.php [L]', htaccessMigrationLegacy()), ['oauth_rule_modified']],
        'outside_module' => [BlueskyOAuthHtaccessDiagnostics::METADATA_RULE . "\n" . htaccessMigrationWithoutOAuth(), ['oauth_rule_outside_mod_rewrite']],
        'catch_all_ambiguous' => [str_replace("RewriteRule ^ index.php [L]\n", '', htaccessMigrationWithoutOAuth()), ['generic_catch_all_not_unique']],
        'multiple_modules' => [htaccessMigrationWithoutOAuth() . htaccessMigrationWithoutOAuth(), ['mod_rewrite_block_not_unique']],
    ];
    foreach ($fixtures as $name => [$contents, $issues]) {
        $root = htaccessMigrationRoot($contents);
        $roots[] = $root;
        $before = (string) file_get_contents($root . '/.htaccess');
        $plan = BlueskyOAuthHtaccessMigration::inspectMigration($root);
        htaccessMigrationAssertState($name, $plan, 'blocked', false, $issues);
        htaccessMigrationAssert((string) file_get_contents($root . '/.htaccess') === $before, $name . ': inspect changed .htaccess');
        $result = BlueskyOAuthHtaccessMigration::migrate($root);
        htaccessMigrationAssert(($result['status'] ?? '') === 'blocked', $name . ': migrate was not blocked');
        htaccessMigrationAssert(($result['backup_created'] ?? false) === false, $name . ': blocked migration created a backup');
        htaccessMigrationAssert((string) file_get_contents($root . '/.htaccess') === $before, $name . ': blocked migration changed .htaccess');
    }

    $symlinkRoot = htaccessMigrationRoot(htaccessMigrationManaged());
    $roots[] = $symlinkRoot;
    @unlink($symlinkRoot . '/.htaccess');
    htaccessMigrationAssert(symlink('/tmp/nonexistent-tomos-oauth-target', $symlinkRoot . '/.htaccess'), 'symlink fixture could not be created');
    $symlinkPlan = BlueskyOAuthHtaccessMigration::inspectMigration($symlinkRoot);
    htaccessMigrationAssertState('symlink', $symlinkPlan, 'blocked', false, ['diagnostic_status_not_migratable']);
    $symlinkResult = BlueskyOAuthHtaccessMigration::migrate($symlinkRoot);
    htaccessMigrationAssert(($symlinkResult['status'] ?? '') === 'blocked', 'symlink migration was not blocked');

    $backupFailureRoot = htaccessMigrationRoot(htaccessMigrationWithoutOAuth(), false);
    $roots[] = $backupFailureRoot;
    htaccessMigrationAssert(mkdir($backupFailureRoot . '/storage', 0700, true), 'backup failure storage directory could not be created');
    htaccessMigrationAssert(file_put_contents($backupFailureRoot . '/storage/update-backups', 'not a directory', LOCK_EX) !== false, 'backup failure fixture could not be created');
    $backupFailure = BlueskyOAuthHtaccessMigration::migrate($backupFailureRoot);
    htaccessMigrationAssert(($backupFailure['status'] ?? '') === 'failed', 'backup failure was not reported');
    htaccessMigrationAssert(in_array('backup_failed', $backupFailure['issues'] ?? [], true), 'backup failure issue missing');
    htaccessMigrationAssert((string) file_get_contents($backupFailureRoot . '/.htaccess') === htaccessMigrationWithoutOAuth(), 'backup failure changed .htaccess');

    $writeFailureRoot = htaccessMigrationRoot(htaccessMigrationWithoutOAuth());
    $roots[] = $writeFailureRoot;
    htaccessMigrationAssert(@chmod($writeFailureRoot, 0555), 'write failure fixture could not become read-only');
    $writeFailure = BlueskyOAuthHtaccessMigration::migrate($writeFailureRoot);
    @chmod($writeFailureRoot, 0700);
    htaccessMigrationAssert(($writeFailure['status'] ?? '') === 'failed', 'write failure was not reported');
    htaccessMigrationAssert(in_array('write_failed', $writeFailure['issues'] ?? [], true), 'write failure issue missing');
    htaccessMigrationAssert(in_array('rollback_failed', $writeFailure['issues'] ?? [], true), 'write failure rollback failure was not detected');
    htaccessMigrationAssert((string) file_get_contents($writeFailureRoot . '/.htaccess') === htaccessMigrationWithoutOAuth(), 'write failure changed .htaccess');

    $rollbackFailureRoot = htaccessMigrationRoot(htaccessMigrationWithoutOAuth());
    $roots[] = $rollbackFailureRoot;
    $rollbackFailureMigration = BlueskyOAuthHtaccessMigration::migrate($rollbackFailureRoot);
    $rollbackFailureId = (string) ($rollbackFailureMigration['backup_id'] ?? '');
    htaccessMigrationAssert($rollbackFailureId !== '', 'rollback failure backup id missing');
    $rollbackFailureBackup = $rollbackFailureRoot . '/storage/update-backups/' . $rollbackFailureId . '/files/.htaccess';
    htaccessMigrationAssert(file_put_contents($rollbackFailureBackup, 'tampered', LOCK_EX) !== false, 'rollback failure fixture could not be tampered');
    $rollbackFailure = BlueskyOAuthHtaccessMigration::rollback($rollbackFailureRoot, $rollbackFailureId);
    htaccessMigrationAssert(($rollbackFailure['status'] ?? '') === 'failed', 'rollback failure was not detected');
    htaccessMigrationAssert(in_array('backup_unavailable', $rollbackFailure['issues'] ?? [], true), 'rollback failure issue missing');
} finally {
    foreach ($roots as $root) {
        htaccessMigrationRemoveTree($root);
    }
}

echo "bluesky_oauth_htaccess_migration_check: passed\n";
