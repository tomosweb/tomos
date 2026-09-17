<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PasskeyCredentialStore.php';

use Tomos\PasskeyCredentialStore;

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-passkey-store-' . bin2hex(random_bytes(6));
$config = ['paths' => ['storage_dir' => $tmp . DIRECTORY_SEPARATOR . 'storage']];
$store = new PasskeyCredentialStore($config, $tmp);

try {
    assertSame([], $store->all(), 'empty store must list no credentials');

    $credentialA = [
        'credential_id' => 'abc_DEF-123',
        'public_key' => "-----BEGIN PUBLIC KEY-----\nTEST-A\n-----END PUBLIC KEY-----",
        'sign_count' => 0,
        'transports' => ['internal', 'hybrid', 'internal', '../bad'],
        'label' => 'iPhone',
        'created_at' => 1786000000,
        'last_used_at' => null,
        'rp_id' => 'example.com',
    ];
    assertSame(true, $store->save($credentialA), 'valid credential must be saved');
    assertSame(true, is_dir($store->storageDirectory()), 'persistent passkey directory must be created');
    assertSame(true, is_file($store->storageDirectory() . DIRECTORY_SEPARATOR . '.htaccess'), 'storage directory must block direct web access on Apache');

    $files = glob($store->storageDirectory() . DIRECTORY_SEPARATOR . '*.json') ?: [];
    assertSame(1, count($files), 'one credential JSON must be stored');
    assertSame(hash('sha256', $credentialA['credential_id']) . '.json', basename($files[0]), 'credential ID must be hashed for the filename');
    assertSame(false, strpos(basename($files[0]), $credentialA['credential_id']) !== false, 'raw credential ID must not appear in filename');

    $loadedA = $store->load($credentialA['credential_id']);
    assertSame('iPhone', $loadedA['label'] ?? null, 'label must round-trip');
    assertSame(['internal', 'hybrid'], $loadedA['transports'] ?? null, 'transports must be sanitized and deduplicated');
    assertSame('example.com', $loadedA['rp_id'] ?? null, 'RP ID must round-trip');
    assertSame(PasskeyCredentialStore::SCHEMA_VERSION, $loadedA['schema_version'] ?? null, 'schema version must be stored');

    $credentialB = [
        'credential_id' => 'secondCredential_456',
        'public_key' => 'PUBLIC-KEY-B',
        'sign_count' => 2,
        'transports' => [],
        'label' => 'MacBook',
        'created_at' => 1786000100,
        'last_used_at' => 1786000200,
        'rp_id' => 'example.com',
    ];
    assertSame(true, $store->save($credentialB), 'second credential must be saved independently');
    $all = $store->all();
    assertSame(2, count($all), 'multiple passkeys must be supported');
    assertSame('abc_DEF-123', $all[0]['credential_id'] ?? null, 'listing must be stable by creation time');
    assertSame('secondCredential_456', $all[1]['credential_id'] ?? null, 'second credential must be listed');

    assertSame(true, $store->updateUsage($credentialA['credential_id'], 7, 1786000300), 'usage metadata must update');
    $updated = $store->load($credentialA['credential_id']);
    assertSame(7, $updated['sign_count'] ?? null, 'sign count must update');
    assertSame(1786000300, $updated['last_used_at'] ?? null, 'last used time must update');

    assertSame(false, $store->save(['credential_id' => '../escape', 'public_key' => 'x', 'rp_id' => 'example.com']), 'unsafe credential ID must be rejected');
    assertSame(false, $store->save(['credential_id' => 'valid', 'public_key' => '', 'rp_id' => 'example.com']), 'empty public key must be rejected');
    assertSame(false, $store->save(['credential_id' => 'valid', 'public_key' => 'x', 'rp_id' => 'bad host']), 'invalid RP ID must be rejected');
    assertSame(null, $store->load('../escape'), 'unsafe load key must be rejected');

    assertSame(true, $store->delete($credentialB['credential_id']), 'credential must be deletable');
    assertSame(null, $store->load($credentialB['credential_id']), 'deleted credential must not load');
    assertSame(1, count($store->all()), 'deleting one credential must preserve other credentials');

    echo "passkey_credential_store_check: OK\n";
} finally {
    removeTree($tmp);
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($child) ? removeTree($child) : unlink($child);
    }
    rmdir($path);
}
