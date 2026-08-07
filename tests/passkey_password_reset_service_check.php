<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PasskeyEnvironment.php';
require_once dirname(__DIR__) . '/core/PasskeyCredentialStore.php';
require_once dirname(__DIR__) . '/core/PasskeyChallengeStore.php';
require_once dirname(__DIR__) . '/core/PasskeyWebAuthnClient.php';
require_once dirname(__DIR__) . '/core/PasskeyAuthenticationService.php';
require_once dirname(__DIR__) . '/core/PasskeyRegistrationService.php';
require_once dirname(__DIR__) . '/core/PostPasswordHashUpdater.php';
require_once dirname(__DIR__) . '/core/PasskeyPasswordResetService.php';

use Tomos\PasskeyChallengeStore;
use Tomos\PasskeyCredentialStore;
use Tomos\PasskeyEnvironment;
use Tomos\PasskeyPasswordResetService;
use Tomos\PasskeyWebAuthnClient;
use Tomos\PostPasswordHashUpdater;

final class FakeResetClient implements PasskeyWebAuthnClient
{
    public function createRegistrationOptions(string $rpId, array $excludeCredentialIds): array { return ['public_key'=>[], 'challenge'=>'unused']; }
    public function verifyRegistration(string $rpId, string $expectedOrigin, array $payload, string $challenge): array { return []; }
    public function createAuthenticationOptions(string $rpId, array $allowCredentialIds): array
    {
        return ['public_key' => ['rpId'=>$rpId], 'challenge' => 'reset-challenge'];
    }
    public function verifyAuthentication(string $rpId, string $expectedOrigin, array $payload, string $challenge, string $publicKey, int $storedSignCount): array
    {
        if ($expectedOrigin !== 'https://example.com' || $challenge !== 'reset-challenge') throw new RuntimeException('verification args mismatch');
        return ['sign_count' => $storedSignCount + 1];
    }
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-passkey-reset-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
file_put_contents($tmp . '/config.php', "<?php return ['security'=>['post_password_hash'=>'old-hash']];\n");
$config = ['site'=>['url'=>'https://example.com'], 'paths'=>['storage_dir'=>$tmp . '/storage']];
$environment = new PasskeyEnvironment($config, ['HTTPS'=>'on','SERVER_PORT'=>443], true, '8.2.0', true, true);
$store = new PasskeyCredentialStore($config, $tmp);
$credentialId = rtrim(strtr(base64_encode('credential-1'), '+/', '-_'), '=');
$store->save([
    'credential_id'=>$credentialId,
    'public_key'=>base64_encode('public-key'),
    'sign_count'=>0,
    'transports'=>['internal'],
    'label'=>'Mac',
    'created_at'=>1786000000,
    'last_used_at'=>null,
    'rp_id'=>'example.com',
]);
$session = [];
$service = new PasskeyPasswordResetService(
    $environment,
    $store,
    new PasskeyChallengeStore(1786000000),
    new FakeResetClient(),
    new PostPasswordHashUpdater($tmp),
    1786000000
);

try {
    assertThrows(fn() => $service->resetPassphrase($session, 'new-pass', 'new-pass'), 'reset must require fresh passkey authentication');
    $service->begin($session);
    $service->completeReauthentication($session, ['credential_id'=>$credentialId]);
    $service->resetPassphrase($session, 'new-pass', 'new-pass');

    $loaded = require $tmp . '/config.php';
    $hash = (string) ($loaded['security']['post_password_hash'] ?? '');
    if (!password_verify('new-pass', $hash)) throw new RuntimeException('new password hash was not persisted');
    if (isset($session[PasskeyPasswordResetService::AUTHORIZED_UNTIL_KEY])) throw new RuntimeException('reset authorization must be one-time');
    echo "passkey_password_reset_service_check: OK\n";
} finally {
    removeTree($tmp);
}

function assertThrows(callable $fn, string $message): void
{
    try { $fn(); } catch (RuntimeException $e) { return; }
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
