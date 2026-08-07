<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PasskeyChallengeStore.php';

use Tomos\PasskeyChallengeStore;

$session = [];
$store = new PasskeyChallengeStore(1786000000);

assertSame(true, $store->remember($session, 'register', 'abc123', 120), 'registration challenge must be stored');
assertSame('abc123', $store->consume($session, 'register'), 'stored challenge must be returned once');
assertSame(null, $store->consume($session, 'register'), 'challenge must be one-time use');

assertSame(true, $store->remember($session, 'authenticate', 'login-challenge', 30), 'authentication challenge must be stored independently');
assertSame(true, $store->remember($session, 'register', 'register-challenge', 30), 'registration challenge must coexist');
assertSame('register-challenge', $store->consume($session, 'register'), 'registration challenge must not consume authentication challenge');
assertSame('login-challenge', $store->consume($session, 'authenticate'), 'authentication challenge must remain available');

$expiredSession = [];
$writer = new PasskeyChallengeStore(1786000000);
assertSame(true, $writer->remember($expiredSession, 'register', 'expired', 10), 'expiring challenge must be stored');
$reader = new PasskeyChallengeStore(1786000011);
assertSame(null, $reader->consume($expiredSession, 'register'), 'expired challenge must be rejected and consumed');

assertSame(false, $store->remember($session, 'unknown', 'x', 60), 'unknown challenge purpose must be rejected');
assertSame(false, $store->remember($session, 'register', '', 60), 'empty challenge must be rejected');

echo "passkey_challenge_store_check: OK\n";

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}
