<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PasskeyEnvironment.php';
require_once dirname(__DIR__) . '/core/PasskeyCredentialStore.php';
require_once dirname(__DIR__) . '/core/PasskeyChallengeStore.php';
require_once dirname(__DIR__) . '/core/PasskeyWebAuthnClient.php';
require_once dirname(__DIR__) . '/core/PasskeyRegistrationService.php';

use Tomos\PasskeyChallengeStore;
use Tomos\PasskeyCredentialStore;
use Tomos\PasskeyEnvironment;
use Tomos\PasskeyRegistrationService;
use Tomos\PasskeyWebAuthnClient;

final class FakeRegistrationClient implements PasskeyWebAuthnClient
{
    /** @var array<int,string> */
    public array $excluded = [];
    public string $verifiedChallenge = '';

    public function createRegistrationOptions(string $rpId, array $excludeCredentialIds): array
    {
        $this->excluded = $excludeCredentialIds;
        return [
            'public_key' => ['rp' => ['id' => $rpId], 'challenge' => 'fake'],
            'challenge' => 'binary-challenge',
        ];
    }

    public function verifyRegistration(string $rpId, array $payload, string $challenge): array
    {
        $this->verifiedChallenge = $challenge;
        return [
            'credential_id' => 'Y3JlZGVudGlhbC0x',
            'public_key' => base64_encode('public-key-1'),
            'sign_count' => 0,
            'transports' => ['internal'],
        ];
    }
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-passkey-registration-' . bin2hex(random_bytes(6));
$config = [
    'site' => ['url' => 'https://example.com/tomos'],
    'paths' => ['storage_dir' => $tmp . DIRECTORY_SEPARATOR . 'storage'],
];
$server = ['HTTPS' => 'on', 'SERVER_PORT' => 443];
$environment = new PasskeyEnvironment($config, $server, true, '8.2.0', true, true);
$credentialStore = new PasskeyCredentialStore($config, $tmp);
$challengeStore = new PasskeyChallengeStore(1786000000);
$client = new FakeRegistrationClient();
$service = new PasskeyRegistrationService($environment, $credentialStore, $challengeStore, $client, 1786000000);
$session = [];

try {
    assertThrows(function () use ($service, &$session): void {
        $service->begin($session);
    }, 'registration must require passphrase re-authentication');

    $service->authorizeAfterPassphrase($session);
    $options = $service->begin($session);
    assertSame('example.com', $options['public_key']['rp']['id'] ?? null, 'registration options must use configured RP ID');

    $record = $service->complete($session, ['clientDataJSON' => 'unused', 'attestationObject' => 'unused'], 'iPhone');
    assertSame('Y3JlZGVudGlhbC0x', $record['credential_id'] ?? null, 'verified credential must be persisted');
    assertSame('iPhone', $record['label'] ?? null, 'user label must be persisted');
    assertSame('example.com', $record['rp_id'] ?? null, 'credential must be bound to RP ID');
    assertSame('binary-challenge', $client->verifiedChallenge, 'verification must receive the issued challenge');
    assertSame(1, count($credentialStore->all()), 'one registered credential must exist');

    assertThrows(function () use ($service, &$session): void {
        $service->complete($session, [], 'duplicate attempt');
    }, 'registration challenge must be one-time use');

    $service->authorizeAfterPassphrase($session);
    $service->begin($session);
    assertSame(1, count($client->excluded), 'existing credentials must be excluded from a new registration');
    assertSame('credential-1', $client->excluded[0] ?? null, 'exclude list must contain binary credential ID');

    assertThrows(function () use ($service, &$session): void {
        $service->complete($session, [], 'duplicate');
    }, 'duplicate credential must be rejected');

    echo "passkey_registration_service_check: OK\n";
} finally {
    removeTree($tmp);
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
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
