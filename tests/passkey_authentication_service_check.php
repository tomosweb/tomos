<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PasskeyEnvironment.php';
require_once dirname(__DIR__) . '/core/PasskeyCredentialStore.php';
require_once dirname(__DIR__) . '/core/PasskeyChallengeStore.php';
require_once dirname(__DIR__) . '/core/PasskeyWebAuthnClient.php';
require_once dirname(__DIR__) . '/core/PasskeyAuthenticationService.php';

use Tomos\PasskeyAuthenticationService;
use Tomos\PasskeyChallengeStore;
use Tomos\PasskeyCredentialStore;
use Tomos\PasskeyEnvironment;
use Tomos\PasskeyWebAuthnClient;

final class FakeAuthenticationClient implements PasskeyWebAuthnClient
{
    /** @var array<int,string> */
    public array $allowed = [];
    public string $verifiedChallenge = '';
    public string $verifiedOrigin = '';
    public string $verifiedPublicKey = '';
    public int $verifiedSignCount = -1;

    public function createRegistrationOptions(string $rpId, array $excludeCredentialIds): array
    {
        throw new RuntimeException('not used');
    }

    public function verifyRegistration(
        string $rpId,
        string $expectedOrigin,
        array $payload,
        string $challenge
    ): array {
        throw new RuntimeException('not used');
    }

    public function createAuthenticationOptions(string $rpId, array $allowCredentialIds): array
    {
        $this->allowed = $allowCredentialIds;
        return [
            'public_key' => ['rpId' => $rpId, 'challenge' => 'fake'],
            'challenge' => 'authentication-challenge',
        ];
    }

    public function verifyAuthentication(
        string $rpId,
        string $expectedOrigin,
        array $payload,
        string $challenge,
        string $publicKey,
        int $storedSignCount
    ): array {
        $this->verifiedChallenge = $challenge;
        $this->verifiedOrigin = $expectedOrigin;
        $this->verifiedPublicKey = $publicKey;
        $this->verifiedSignCount = $storedSignCount;
        return ['sign_count' => 8];
    }
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-passkey-authentication-' . bin2hex(random_bytes(6));
$config = [
    'site' => ['url' => 'https://example.com/tomos'],
    'paths' => ['storage_dir' => $tmp . DIRECTORY_SEPARATOR . 'storage'],
];
$server = ['HTTPS' => 'on', 'SERVER_PORT' => 443];
$environment = new PasskeyEnvironment($config, $server, true, '8.2.0', true, true);
$store = new PasskeyCredentialStore($config, $tmp);
$challengeStore = new PasskeyChallengeStore(1786000000);
$client = new FakeAuthenticationClient();
$service = new PasskeyAuthenticationService($environment, $store, $challengeStore, $client, 1786000000);
$session = [];
$credentialId = 'Y3JlZGVudGlhbC0x';

try {
    assertThrows(function () use ($service, &$session): void {
        $service->begin($session);
    }, 'authentication must require a registered passkey');

    assertSame(true, $store->save([
        'credential_id' => $credentialId,
        'public_key' => base64_encode('public-key-1'),
        'sign_count' => 4,
        'transports' => ['internal'],
        'label' => 'iPhone',
        'created_at' => 1785999900,
        'last_used_at' => null,
        'rp_id' => 'example.com',
    ]), 'test credential must be saved');

    $options = $service->begin($session);
    assertSame('example.com', $options['public_key']['rpId'] ?? null, 'authentication options must use configured RP ID');
    assertSame(1, count($client->allowed), 'registered credential must be in allowCredentials');
    assertSame('credential-1', $client->allowed[0] ?? null, 'allowCredentials must use binary credential ID');

    $record = $service->complete($session, [
        'credential_id' => $credentialId,
        'clientDataJSON' => 'unused',
        'authenticatorData' => 'unused',
        'signature' => 'unused',
    ]);
    assertSame(true, !empty($session['tomos_post_authenticated']), 'successful passkey authentication must set the existing Tomos Post session flag');
    assertSame(1786000000, $session[PasskeyAuthenticationService::AUTHENTICATED_AT_KEY] ?? null, 'fresh passkey authentication time must be recorded');
    assertSame('authentication-challenge', $client->verifiedChallenge, 'verification must receive issued challenge');
    assertSame('https://example.com', $client->verifiedOrigin, 'verification must receive exact configured origin');
    assertSame(base64_encode('public-key-1'), $client->verifiedPublicKey, 'verification must receive stored public key');
    assertSame(4, $client->verifiedSignCount, 'verification must receive stored signature counter');
    assertSame(8, $record['sign_count'] ?? null, 'verified signature counter must be persisted');
    assertSame(1786000000, $record['last_used_at'] ?? null, 'last used timestamp must be persisted');

    assertThrows(function () use ($service, &$session, $credentialId): void {
        $service->complete($session, ['credential_id' => $credentialId]);
    }, 'authentication challenge must be one-time use');

    $service->begin($session);
    assertThrows(function () use ($service, &$session): void {
        $service->complete($session, ['credential_id' => 'dW5rbm93bg']);
    }, 'unknown credential must be rejected');

    echo "passkey_authentication_service_check: OK\n";
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
