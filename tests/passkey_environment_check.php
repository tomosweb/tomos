<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PasskeyEnvironment.php';

use Tomos\PasskeyEnvironment;

$httpsConfig = ['site' => ['url' => 'https://example.com/tomos/']];
$available = new PasskeyEnvironment($httpsConfig, [], true, '8.2.32', true, true);
$diagnosis = $available->diagnose();
assertSame(true, $diagnosis['available'], 'supported environment must enable passkeys');
assertSame('example.com', $diagnosis['rp_id'], 'RP ID must come from configured site host');
assertSame('https://example.com', $diagnosis['origin'], 'origin must omit the Tomos subdirectory');
assertSame(true, $diagnosis['checks']['https'], 'configured HTTPS site must be secure');

$customPort = new PasskeyEnvironment(['site' => ['url' => 'https://example.com:8443/tomos/']], [], true, '8.2.32', true, true);
assertSame('example.com', $customPort->rpId(), 'RP ID must not contain a port');
assertSame('https://example.com:8443', $customPort->origin(), 'non-default HTTPS port must be retained in origin');

$php74 = new PasskeyEnvironment($httpsConfig, [], true, '7.4.33', true, true);
assertSame(false, $php74->isAvailable(), 'PHP 7.4 must keep Tomos usable while passkeys remain unavailable');
assertSame(false, $php74->diagnose()['checks']['php_8'], 'PHP 8 capability must be reported separately');

$missingLibrary = new PasskeyEnvironment($httpsConfig, [], false, '8.2.32', true, true);
assertSame(false, $missingLibrary->isAvailable(), 'missing WebAuthn library must disable passkeys only');

$missingExtension = new PasskeyEnvironment($httpsConfig, [], true, '8.2.32', false, true);
assertSame(false, $missingExtension->isAvailable(), 'missing OpenSSL must disable passkeys');

$httpConfig = ['site' => ['url' => 'http://example.com/tomos/']];
$http = new PasskeyEnvironment($httpConfig, ['HTTPS' => 'on'], true, '8.2.32', true, true);
assertSame(false, $http->isAvailable(), 'configured HTTP origin must not become a WebAuthn origin from request headers');
assertSame('', $http->origin(), 'HTTP site URL must not produce a WebAuthn origin');

$noUrl = new PasskeyEnvironment([], ['HTTPS' => 'on', 'HTTP_HOST' => 'attacker.example'], true, '8.2.32', true, true);
assertSame('', $noUrl->rpId(), 'Host header alone must not define RP ID');
assertSame(false, $noUrl->isAvailable(), 'missing configured site URL must disable passkeys safely');

echo "passkey_environment_check: OK\n";

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}
