<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'core/webauthn/composer.json',
    'core/webauthn/composer.lock',
    'core/webauthn/vendor/autoload.php',
    'core/webauthn/vendor/lbuchs/webauthn/src/WebAuthn.php',
    'post/assets/tomos-post-security.css',
    'post/security/index.php',
    'post/passkey/login/index.php',
    'post/passkey/manage/index.php',
    'post/passkey/register/index.php',
    'post/passkey/password-reset/index.php',
    'post/passkey/recovery/index.php',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        fwrite(STDERR, 'Missing distribution file: ' . $relative . PHP_EOL);
        exit(1);
    }
}

$lock = json_decode((string) file_get_contents($root . '/core/webauthn/composer.lock'), true);
if (!is_array($lock) || !is_array($lock['packages'] ?? null)) {
    fwrite(STDERR, 'Invalid core/webauthn/composer.lock' . PHP_EOL);
    exit(1);
}

$version = null;
foreach ($lock['packages'] as $package) {
    if (is_array($package) && ($package['name'] ?? '') === 'lbuchs/webauthn') {
        $version = (string) ($package['version'] ?? '');
        break;
    }
}
if ($version !== 'v2.2.0') {
    fwrite(STDERR, 'Unexpected lbuchs/webauthn version: ' . (string) $version . PHP_EOL);
    exit(1);
}

require_once $root . '/core/webauthn/vendor/autoload.php';
if (!class_exists('lbuchs\\WebAuthn\\WebAuthn')) {
    fwrite(STDERR, 'lbuchs WebAuthn class could not be autoloaded.' . PHP_EOL);
    exit(1);
}

$requiredList = (string) file_get_contents($root . '/core/required-installed-files.txt');
foreach ([
    'core/webauthn/vendor/autoload.php',
    'core/webauthn/vendor/lbuchs/webauthn/src/WebAuthn.php',
] as $relative) {
    if (strpos($requiredList, $relative) === false) {
        fwrite(STDERR, 'Required installed files list is missing: ' . $relative . PHP_EOL);
        exit(1);
    }
}

echo 'passkey_distribution_check: OK' . PHP_EOL;
