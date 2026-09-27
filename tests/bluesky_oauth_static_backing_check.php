<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/BlueskyOAuthMetadata.php';
require_once __DIR__ . '/../core/BlueskyOAuthKeyStore.php';
require_once __DIR__ . '/../core/BlueskyOAuthStaticBacking.php';

use Tomos\BlueskyOAuthKeyStore;
use Tomos\BlueskyOAuthStaticBacking;

function staticBackingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeStaticBackingTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        removeStaticBackingTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

function executeEndpointBody(string $path): array
{
    $runner = dirname($path) . '/endpoint-runner.php';
    file_put_contents($runner, "<?php require \$argv[1];\n", LOCK_EX);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($path);
    exec($command . ' 2>&1', $output, $status);
    @unlink($runner);
    if ($status !== 0) {
        throw new RuntimeException('legacy endpoint execution failed: ' . implode("\n", $output));
    }
    $body = implode("\n", $output);
    return [$body, json_decode($body, true)];
}

$root = sys_get_temp_dir() . '/tomos-bluesky-static-backing-' . bin2hex(random_bytes(6));
mkdir($root . '/storage', 0777, true);
$config = [
    'site' => [
        'url' => 'https://example.test',
        'base_path' => '/tomos',
        'public_base_path' => '/tomos',
    ],
];

try {
    $keyStore = new BlueskyOAuthKeyStore($root);
    $key = $keyStore->loadOrCreate();
    staticBackingAssert(is_array($key), 'OAuth key fixture could not be generated');
    $keyBefore = hash_file('sha256', $root . '/storage/social/oauth/bluesky-client-key.json');

    BlueskyOAuthStaticBacking::generate($root, $config);
    $keyAfter = hash_file('sha256', $root . '/storage/social/oauth/bluesky-client-key.json');
    staticBackingAssert($keyBefore === $keyAfter, 'static generation must not change the OAuth client key');

    $metadataPath = $root . '/oauth-client-metadata.static.json';
    $jwksPath = $root . '/tomos-bluesky-jwks.static.json';
    staticBackingAssert(is_file($metadataPath) && is_file($jwksPath), 'static backing files were not generated');
    $metadata = json_decode((string) file_get_contents($metadataPath), true);
    $jwks = json_decode((string) file_get_contents($jwksPath), true);
    staticBackingAssert(is_array($metadata) && is_array($jwks), 'static backing must be valid JSON');
    staticBackingAssert(($metadata['client_id'] ?? '') === 'https://example.test/tomos/oauth-client-metadata.json.php', 'static metadata changed client_id');
    staticBackingAssert(($metadata['jwks_uri'] ?? '') === 'https://example.test/tomos/tomos-bluesky-jwks.json.php', 'static metadata changed jwks_uri');
    staticBackingAssert(strpos((string) file_get_contents($jwksPath), 'private_pem') === false, 'static JWKS contains private key data');
    staticBackingAssert(strpos((string) file_get_contents($jwksPath), 'BEGIN EC PRIVATE KEY') === false, 'static JWKS contains PEM data');
    staticBackingAssert(glob($root . '/*.tmp-*') === [], 'static backing temporary files remain');

    copy(dirname(__DIR__) . '/oauth-client-metadata.json.php', $root . '/oauth-client-metadata.json.php');
    copy(dirname(__DIR__) . '/tomos-bluesky-jwks.json.php', $root . '/tomos-bluesky-jwks.json.php');
    mkdir($root . '/core', 0777, true);
    copy(dirname(__DIR__) . '/core/BlueskyOAuthMetadata.php', $root . '/core/BlueskyOAuthMetadata.php');
    copy(dirname(__DIR__) . '/core/BlueskyOAuthKeyStore.php', $root . '/core/BlueskyOAuthKeyStore.php');
    file_put_contents($root . '/config.php', '<?php return ' . var_export($config, true) . ';' . PHP_EOL, LOCK_EX);

    [, $legacyMetadata] = executeEndpointBody($root . '/oauth-client-metadata.json.php');
    [, $legacyJwks] = executeEndpointBody($root . '/tomos-bluesky-jwks.json.php');
    staticBackingAssert($metadata === $legacyMetadata, 'static Metadata differs from the legacy PHP Metadata');
    staticBackingAssert($jwks === $legacyJwks, 'static JWKS differs from the legacy PHP JWKS');

    $toolRoot = $root . '/tool-root';
    mkdir($toolRoot . '/storage', 0777, true);
    mkdir($toolRoot . '/core', 0777, true);
    copy(dirname(__DIR__) . '/core/BlueskyOAuthMetadata.php', $toolRoot . '/core/BlueskyOAuthMetadata.php');
    copy(dirname(__DIR__) . '/core/BlueskyOAuthKeyStore.php', $toolRoot . '/core/BlueskyOAuthKeyStore.php');
    copy(dirname(__DIR__) . '/core/BlueskyOAuthStaticBacking.php', $toolRoot . '/core/BlueskyOAuthStaticBacking.php');
    $toolKey = (new BlueskyOAuthKeyStore($toolRoot))->loadOrCreate();
    staticBackingAssert(is_array($toolKey), 'CLI tool key fixture could not be generated');
    file_put_contents($toolRoot . '/config.php', '<?php return ' . var_export($config, true) . ';' . PHP_EOL, LOCK_EX);
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(dirname(__DIR__) . '/tools/generate-bluesky-oauth-static-backing.php')
        . ' --root=' . escapeshellarg($toolRoot);
    exec($command . ' 2>&1', $output, $status);
    staticBackingAssert($status === 0, 'explicit CLI static generation failed: ' . implode('\n', $output));
    staticBackingAssert(is_file($toolRoot . '/oauth-client-metadata.static.json'), 'CLI did not generate static Metadata');
    staticBackingAssert(is_file($toolRoot . '/tomos-bluesky-jwks.static.json'), 'CLI did not generate static JWKS');
} finally {
    removeStaticBackingTree($root);
}

echo "bluesky_oauth_static_backing_check: passed\n";
