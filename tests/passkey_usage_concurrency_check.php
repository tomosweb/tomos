<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PasskeyCredentialStore.php';

use Tomos\PasskeyCredentialStore;

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-passkey-usage-' . bin2hex(random_bytes(6));
$config = ['paths' => ['storage_dir' => $tmp . DIRECTORY_SEPARATOR . 'storage']];
$store = new PasskeyCredentialStore($config, $tmp);
$credentialId = 'usageCredential_123';

try {
    $credential = [
        'credential_id' => $credentialId,
        'public_key' => 'PUBLIC-KEY',
        'sign_count' => 10,
        'transports' => ['internal'],
        'label' => 'Test passkey',
        'created_at' => 1786000000,
        'last_used_at' => 1786000100,
        'rp_id' => 'example.com',
    ];
    assertSame(true, $store->save($credential), 'fixture credential must save');

    assertSame(true, $store->updateUsage($credentialId, 12, 1786000200), 'higher usage update must save');
    assertSame(true, $store->updateUsage($credentialId, 8, 1786000150), 'older usage update must not fail');
    $record = $store->load($credentialId);
    assertSame(12, $record['sign_count'] ?? null, 'sign_count must never regress');
    assertSame(1786000200, $record['last_used_at'] ?? null, 'last_used_at must never regress');

    $lockPath = $store->storageDirectory() . DIRECTORY_SEPARATOR
        . '.usage-' . hash('sha256', $credentialId) . '.lock';
    assertSame(true, is_file($lockPath), 'per-credential usage lock must exist');
    assertSame(0600, fileperms($lockPath) & 0777, 'usage lock permissions must be private');

    if (function_exists('pcntl_fork')) {
        $parentLock = fopen($lockPath, 'c');
        if (!is_resource($parentLock) || !flock($parentLock, LOCK_EX)) {
            throw new RuntimeException('could not hold usage lock fixture');
        }

        $marker = $tmp . DIRECTORY_SEPARATOR . 'child-result';
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('could not fork usage update');
        }
        if ($pid === 0) {
            // Drop the inherited descriptor while leaving the parent's copy held.
            fclose($parentLock);
            $childStore = new PasskeyCredentialStore($config, $tmp);
            $ok = $childStore->updateUsage($credentialId, 11, 1786000250);
            file_put_contents($marker, $ok ? 'ok' : 'failed', LOCK_EX);
            exit($ok ? 0 : 2);
        }

        usleep(150000);
        assertSame(false, is_file($marker), 'concurrent usage update must wait for credential lock');

        // Simulate a newer authentication committing while the older request is
        // waiting. The waiting request must read this state only after it gets
        // the lock and therefore must not overwrite it with lower values.
        $jsonPath = $store->storageDirectory() . DIRECTORY_SEPARATOR . hash('sha256', $credentialId) . '.json';
        $latest = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($latest)) {
            throw new RuntimeException('credential fixture could not be read');
        }
        $latest['sign_count'] = 15;
        $latest['last_used_at'] = 1786000300;
        file_put_contents($jsonPath, json_encode($latest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n", LOCK_EX);

        flock($parentLock, LOCK_UN);
        fclose($parentLock);
        pcntl_waitpid($pid, $status);
        assertSame(0, pcntl_wexitstatus($status), 'waiting usage update must complete');
        assertSame('ok', file_get_contents($marker), 'waiting usage update result must be successful');

        $record = $store->load($credentialId);
        assertSame(15, $record['sign_count'] ?? null, 'serialized update must preserve newer sign_count');
        assertSame(1786000300, $record['last_used_at'] ?? null, 'serialized update must preserve newer last_used_at');
    }

    echo "passkey_usage_concurrency_check: OK\n";
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
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
