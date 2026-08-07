<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PasskeyEnvironment.php';
require_once dirname(__DIR__) . '/core/PasskeyCredentialStore.php';
require_once dirname(__DIR__) . '/core/PasskeyChallengeStore.php';
require_once dirname(__DIR__) . '/core/PasskeyWebAuthnClient.php';
require_once dirname(__DIR__) . '/core/PasskeyServerRecoveryService.php';

use Tomos\PasskeyChallengeStore;
use Tomos\PasskeyCredentialStore;
use Tomos\PasskeyEnvironment;
use Tomos\PasskeyServerRecoveryService;
use Tomos\PasskeyWebAuthnClient;

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-passkey-recovery-' . bin2hex(random_bytes(6));
$config = [
    'site' => ['url' => 'https://example.com/tomos'],
    'paths' => ['storage_dir' => $tmp . DIRECTORY_SEPARATOR . 'storage'],
];
$environment = new PasskeyEnvironment($config, ['HTTPS' => 'on', 'SERVER_PORT' => 443], true, '8.2.0', true, true);
$store = new PasskeyCredentialStore($config, $tmp);
$challenges = new PasskeyChallengeStore(1786000000);
$client = new class implements PasskeyWebAuthnClient {
    public function createRegistrationOptions(string $rpId, array $excludeCredentialIds): array
    {
        return [
            'public_key' => ['challenge' => 'fake-public-key-challenge'],
            'challenge' => 'binary-registration-challenge',
        ];
    }

    public function verifyRegistration(string $rpId, string $expectedOrigin, array $payload, string $challenge): array
    {
        if ($rpId !== 'example.com' || $expectedOrigin !== 'https://example.com' || $challenge !== 'binary-registration-challenge') {
            throw new RuntimeException('registration verification inputs are invalid');
        }
        return [
            'credential_id' => 'cmVjb3ZlcnktY3JlZGVudGlhbA',
            'public_key' => base64_encode('recovery-public-key'),
            'sign_count' => 0,
            'transports' => ['internal'],
        ];
    }

    public function createAuthenticationOptions(string $rpId, array $allowCredentialIds): array
    {
        return ['public_key' => [], 'challenge' => 'unused'];
    }

    public function verifyAuthentication(string $rpId, string $expectedOrigin, array $payload, string $challenge, string $publicKey, int $storedSignCount): array
    {
        return ['sign_count' => $storedSignCount];
    }
};
$service = new PasskeyServerRecoveryService($environment, $store, $challenges, $client, $tmp, 1786000000);
$session = [];

try {
    $issued = $service->issueChallenge($session, 600);
    assertTrue((bool) preg_match('/\A[a-f0-9]{64}\z/', $issued), 'recovery challenge must be 64 hex characters');

    $recoveryPath = $tmp . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'recovery-request.txt';
    if (!is_dir(dirname($recoveryPath)) && !mkdir(dirname($recoveryPath), 0700, true) && !is_dir(dirname($recoveryPath))) {
        throw new RuntimeException('could not create recovery directory');
    }

    file_put_contents($recoveryPath, "wrong-code\n");
    assertThrows(function () use ($service, &$session): void {
        $service->verifyServerAccess($session, 300);
    }, 'wrong recovery code must be rejected');
    assertTrue(is_file($recoveryPath), 'wrong recovery file must remain for correction');

    file_put_contents($recoveryPath, $issued . "\n");
    $service->verifyServerAccess($session, 300);
    assertTrue(!is_file($recoveryPath), 'verified recovery file must be deleted');
    assertTrue($service->isRegistrationAuthorized($session), 'registration must be temporarily authorized');

    $options = $service->beginRegistration($session);
    assertTrue(isset($options['public_key']), 'registration options must be returned');

    $credential = $service->completeRegistration($session, ['dummy' => true], 'Recovery Mac');
    assertSame('Recovery Mac', $credential['label'] ?? null, 'recovery credential label must be saved');
    assertSame('example.com', $credential['rp_id'] ?? null, 'recovery credential RP ID must be saved');
    assertTrue(!$service->isRegistrationAuthorized($session), 'registration authorization must be consumed after one credential');
    assertSame(1, count($store->all()), 'exactly one credential must be registered');

    assertThrows(function () use ($service, &$session): void {
        $service->issueChallenge($session, 600);
    }, 'server recovery must be disabled after a passkey exists');

    echo "passkey_server_recovery_service_check: OK\n";
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
