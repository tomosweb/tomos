<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/BlueskyOAuthMetadata.php';
require_once dirname(__DIR__) . '/core/BlueskyOAuthKeyStore.php';
require_once dirname(__DIR__) . '/core/BlueskyOAuthStaticBacking.php';
require_once dirname(__DIR__) . '/core/BlueskyOAuthHtaccessDiagnostics.php';
require_once dirname(__DIR__) . '/core/BlueskyOAuthHtaccessMigration.php';
require_once dirname(__DIR__) . '/core/BlueskyOAuthPublicEndpointPreparationException.php';
require_once dirname(__DIR__) . '/core/BlueskyOAuthPublicEndpointPreparation.php';

use Tomos\BlueskyOAuthHtaccessDiagnostics;
use Tomos\BlueskyOAuthKeyStore;
use Tomos\BlueskyOAuthMetadata;
use Tomos\BlueskyOAuthPublicEndpointPreparation;
use Tomos\BlueskyOAuthPublicEndpointPreparationException;

function publicEndpointPreparationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function publicEndpointPreparationRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        publicEndpointPreparationRemoveTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

function publicEndpointPreparationRoot(string $htaccess): string
{
    $root = sys_get_temp_dir() . '/tomos-oauth-preparation-' . bin2hex(random_bytes(6));
    publicEndpointPreparationAssert(mkdir($root . '/storage/update-backups', 0700, true), 'fixture root could not be created');
    publicEndpointPreparationAssert(file_put_contents($root . '/.htaccess', $htaccess, LOCK_EX) !== false, 'fixture .htaccess could not be written');
    return $root;
}

function publicEndpointPreparationManagedHtaccess(bool $legacy): string
{
    $block = $legacy
        ? BlueskyOAuthHtaccessDiagnostics::legacyManagedBlockLines()
        : BlueskyOAuthHtaccessDiagnostics::managedBlockLines();
    return "<IfModule mod_rewrite.c>\nRewriteEngine On\n"
        . implode("\n", $block)
        . "\nRewriteRule ^ index.php [L]\n</IfModule>\n";
}

function publicEndpointPreparationBackupDirectories(string $root): array
{
    $directories = [];
    foreach (glob($root . '/storage/update-backups/bluesky-oauth-htaccess-*', GLOB_ONLYDIR) ?: [] as $path) {
        $directories[] = $path;
    }
    sort($directories);
    return $directories;
}

/** @return array<string,mixed> */
function publicEndpointPreparationJson(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true);
    publicEndpointPreparationAssert(is_array($decoded), 'invalid JSON fixture: ' . basename($path));
    return $decoded;
}

function publicEndpointPreparationExpectFailure(string $root, array $config, string $code): void
{
    try {
        BlueskyOAuthPublicEndpointPreparation::prepare($root, $config);
    } catch (BlueskyOAuthPublicEndpointPreparationException $exception) {
        publicEndpointPreparationAssert($exception->diagnosticCode() === $code, 'unexpected preparation failure code');
        return;
    }
    throw new RuntimeException('preparation unexpectedly succeeded');
}

$sourceRoot = dirname(__DIR__);
$config = [
    'site' => [
        'url' => 'https://example.test',
        'base_path' => '/tomos',
        'public_base_path' => '/tomos',
    ],
];
$roots = [];

try {
    $sourceHtaccess = (string) file_get_contents($sourceRoot . '/.htaccess');
    $staticBlock = implode("\n", BlueskyOAuthHtaccessDiagnostics::managedBlockLines());
    publicEndpointPreparationAssert(substr_count($sourceHtaccess, $staticBlock) === 1, 'static managed block fixture is unavailable');

    $freshRoot = publicEndpointPreparationRoot($sourceHtaccess);
    $roots[] = $freshRoot;
    $freshResult = BlueskyOAuthPublicEndpointPreparation::prepare($freshRoot, $config);
    publicEndpointPreparationAssert($freshResult['status'] === 'managed', 'fresh install status is not managed');
    publicEndpointPreparationAssert($freshResult['static_backing_enabled'] === true, 'fresh install static backing is disabled');
    publicEndpointPreparationAssert($freshResult['migration_status'] === 'not_needed', 'fresh install unexpectedly migrated');
    publicEndpointPreparationAssert(is_file($freshRoot . '/storage/social/oauth/bluesky-client-key.json'), 'fresh install did not create client key');
    publicEndpointPreparationAssert(is_file($freshRoot . '/oauth-client-metadata.static.json'), 'fresh install did not create static Metadata');
    publicEndpointPreparationAssert(is_file($freshRoot . '/tomos-bluesky-jwks.static.json'), 'fresh install did not create static JWKS');
    publicEndpointPreparationAssert(publicEndpointPreparationBackupDirectories($freshRoot) === [], 'fresh install created an unnecessary migration backup');

    $freshMetadata = publicEndpointPreparationJson($freshRoot . '/oauth-client-metadata.static.json');
    $freshJwks = publicEndpointPreparationJson($freshRoot . '/tomos-bluesky-jwks.static.json');
    $freshKey = publicEndpointPreparationJson($freshRoot . '/storage/social/oauth/bluesky-client-key.json');
    $freshKeyHash = hash_file('sha256', $freshRoot . '/storage/social/oauth/bluesky-client-key.json');
    $freshHtaccessHash = hash_file('sha256', $freshRoot . '/.htaccess');
    $freshSecond = BlueskyOAuthPublicEndpointPreparation::prepare($freshRoot, $config);
    publicEndpointPreparationAssert($freshSecond['migration_status'] === 'not_needed', 'already prepared install migrated unnecessarily');
    publicEndpointPreparationAssert(hash_file('sha256', $freshRoot . '/storage/social/oauth/bluesky-client-key.json') === $freshKeyHash, 'already prepared changed client key');
    publicEndpointPreparationAssert(hash_file('sha256', $freshRoot . '/.htaccess') === $freshHtaccessHash, 'already prepared changed .htaccess');
    publicEndpointPreparationAssert(publicEndpointPreparationJson($freshRoot . '/oauth-client-metadata.static.json') === $freshMetadata, 'already prepared changed Metadata');
    publicEndpointPreparationAssert(publicEndpointPreparationJson($freshRoot . '/tomos-bluesky-jwks.static.json') === $freshJwks, 'already prepared changed JWKS');

    $oldHtaccess = str_replace($staticBlock, implode("\n", BlueskyOAuthHtaccessDiagnostics::legacyManagedBlockLines()), $sourceHtaccess);
    $oldRoot = publicEndpointPreparationRoot($oldHtaccess);
    $roots[] = $oldRoot;
    $oldBefore = (string) file_get_contents($oldRoot . '/.htaccess');
    $oldResult = BlueskyOAuthPublicEndpointPreparation::prepare($oldRoot, $config);
    publicEndpointPreparationAssert($oldResult['status'] === 'managed', 'old managed install status is not managed');
    publicEndpointPreparationAssert($oldResult['migration_status'] === 'migrated', 'old managed install did not migrate');
    publicEndpointPreparationAssert(publicEndpointPreparationBackupDirectories($oldRoot) !== [], 'old managed install did not create backup');
    $backupDirectories = publicEndpointPreparationBackupDirectories($oldRoot);
    $backupPath = $backupDirectories[0] . '/files/.htaccess';
    publicEndpointPreparationAssert(is_file($backupPath), 'old managed install backup file is missing');
    publicEndpointPreparationAssert((string) file_get_contents($backupPath) === $oldBefore, 'migration backup does not preserve original .htaccess');
    publicEndpointPreparationAssert(BlueskyOAuthHtaccessDiagnostics::inspect($oldRoot)['static_backing_enabled'] === true, 'old managed install final diagnostic is not static');
    $oldSecond = BlueskyOAuthPublicEndpointPreparation::prepare($oldRoot, $config);
    publicEndpointPreparationAssert($oldSecond['migration_status'] === 'not_needed', 'old managed second prepare migrated unnecessarily');
    publicEndpointPreparationAssert(count(publicEndpointPreparationBackupDirectories($oldRoot)) === count($backupDirectories), 'old managed second prepare created another backup');

    $unsafeHtaccess = str_replace(
        BlueskyOAuthHtaccessDiagnostics::METADATA_RULE,
        'RewriteRule ^oauth-client-metadata\\.json$ other.php [L]',
        $oldHtaccess
    );
    $unsafeRoot = publicEndpointPreparationRoot($unsafeHtaccess);
    $roots[] = $unsafeRoot;
    $unsafeHash = hash_file('sha256', $unsafeRoot . '/.htaccess');
    publicEndpointPreparationExpectFailure($unsafeRoot, $config, 'htaccess_migration_not_safe');
    publicEndpointPreparationAssert(hash_file('sha256', $unsafeRoot . '/.htaccess') === $unsafeHash, 'unsafe .htaccess was changed');
    publicEndpointPreparationAssert(publicEndpointPreparationBackupDirectories($unsafeRoot) === [], 'unsafe .htaccess created a backup');

    $generationFailureRoot = publicEndpointPreparationRoot($sourceHtaccess);
    $roots[] = $generationFailureRoot;
    $generationKey = (new BlueskyOAuthKeyStore($generationFailureRoot))->loadOrCreate();
    publicEndpointPreparationAssert(is_array($generationKey), 'generation failure key fixture could not be created');
    $generationKeyHash = hash_file('sha256', $generationFailureRoot . '/storage/social/oauth/bluesky-client-key.json');
    publicEndpointPreparationAssert(mkdir($generationFailureRoot . '/oauth-client-metadata.static.json', 0700), 'generation failure target fixture could not be created');
    publicEndpointPreparationExpectFailure($generationFailureRoot, $config, 'static_backing_generation_failed');
    publicEndpointPreparationAssert(hash_file('sha256', $generationFailureRoot . '/storage/social/oauth/bluesky-client-key.json') === $generationKeyHash, 'static generation failure damaged existing key');

    $entrypoint = (string) file_get_contents($sourceRoot . '/post/social/bluesky/index.php');
    $prepareCall = strpos($entrypoint, 'BlueskyOAuthPublicEndpointPreparation::prepare($rootDir, $config);');
    $flowCall = strpos($entrypoint, 'new Tomos\\BlueskyOAuthFlow($config, $rootDir))->start($identifier);');
    publicEndpointPreparationAssert($prepareCall !== false && $flowCall !== false && $prepareCall < $flowCall, 'connect entrypoint does not prepare before OAuth start');
    publicEndpointPreparationAssert(strpos($entrypoint, 'Blueskyとの接続準備を完了できませんでした。') !== false, 'preparation failure UI message is missing');
    publicEndpointPreparationAssert(strpos($entrypoint, 'diagnosticCode()') !== false, 'preparation failure diagnostic code is missing');

    $metadata = BlueskyOAuthMetadata::clientMetadata($config);
    publicEndpointPreparationAssert($freshMetadata['client_id'] === $metadata['client_id'], 'client_id changed during preparation');
    publicEndpointPreparationAssert($freshMetadata['jwks_uri'] === $metadata['jwks_uri'], 'jwks_uri changed during preparation');
    publicEndpointPreparationAssert($freshMetadata['redirect_uris'] === $metadata['redirect_uris'], 'redirect_uris changed during preparation');
    publicEndpointPreparationAssert($freshMetadata['scope'] === BlueskyOAuthMetadata::SCOPE, 'scope changed during preparation');
    publicEndpointPreparationAssert(($freshJwks['keys'][0] ?? null) === ($freshKey['public_jwk'] ?? null), 'public JWK changed during preparation');
    publicEndpointPreparationAssert(strpos((string) file_get_contents($freshRoot . '/tomos-bluesky-jwks.static.json'), 'private_pem') === false, 'static JWKS contains private material');
} finally {
    foreach ($roots as $root) {
        publicEndpointPreparationRemoveTree($root);
    }
}

echo "bluesky_oauth_public_endpoint_preparation_check: passed\n";
