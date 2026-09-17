<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PasskeyEnvironment.php';
require_once dirname(__DIR__) . '/core/PasskeyCredentialStore.php';
require_once dirname(__DIR__) . '/core/PasskeyManagementService.php';

use Tomos\PasskeyCredentialStore;
use Tomos\PasskeyEnvironment;
use Tomos\PasskeyManagementService;

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-passkey-management-' . bin2hex(random_bytes(6));
$config = [
    'site' => ['url' => 'https://example.com/tomos'],
    'paths' => ['storage_dir' => $tmp . DIRECTORY_SEPARATOR . 'storage'],
];
$environment = new PasskeyEnvironment($config, ['HTTPS' => 'on', 'SERVER_PORT' => 443], true, '8.2.0', true, true);
$store = new PasskeyCredentialStore($config, $tmp);
$service = new PasskeyManagementService($environment, $store);

try {
    assertTrue($store->save([
        'credential_id' => 'Y3JlZGVudGlhbC0x',
        'public_key' => base64_encode('public-key-1'),
        'sign_count' => 0,
        'transports' => ['internal'],
        'label' => 'iPhone',
        'created_at' => 1786000000,
        'last_used_at' => null,
        'rp_id' => 'example.com',
    ]), 'first credential must be saved');
    assertTrue($store->save([
        'credential_id' => 'Y3JlZGVudGlhbC0y',
        'public_key' => base64_encode('public-key-2'),
        'sign_count' => 0,
        'transports' => ['internal'],
        'label' => 'Other RP',
        'created_at' => 1786000001,
        'last_used_at' => null,
        'rp_id' => 'other.example.com',
    ]), 'other RP credential must be saved');

    assertSame(1, count($service->all()), 'management list must only include current RP credentials');
    $renamed = $service->rename('Y3JlZGVudGlhbC0x', 'MacBook');
    assertSame('MacBook', $renamed['label'] ?? null, 'credential label must be updated');

    assertThrows(function () use ($service): void {
        $service->rename('Y3JlZGVudGlhbC0x', '');
    }, 'empty label must be rejected');

    assertThrows(function () use ($service): void {
        $service->delete('Y3JlZGVudGlhbC0y');
    }, 'credential from another RP must not be deleted');

    $service->delete('Y3JlZGVudGlhbC0x');
    assertSame(0, count($service->all()), 'last credential may be deleted because passphrase fallback remains');
    assertSame(1, count($store->all()), 'other RP credential must remain untouched');

    echo "passkey_management_service_check: OK\n";
} finally {
    removeTree($tmp);
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function assertTrue(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function assertThrows(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        return;
    }
    throw new RuntimeException($message);
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
