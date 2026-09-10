<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PasskeyEnvironment.php';

use Tomos\PasskeyEnvironment;

function failCheck(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$config = [
    'site' => [
        'url' => 'https://example.com/tomos/',
    ],
];
$server = [
    'HTTPS' => 'on',
    'SERVER_PORT' => '443',
];

$php74 = new PasskeyEnvironment($config, $server, false, '7.4.33', true, true);
$php74Status = $php74->diagnose();
if (!empty($php74Status['available'])) {
    failCheck('PHP 7.4 must not enable passkey authentication.');
}
if (!isset($php74Status['checks']['php_8']) || $php74Status['checks']['php_8'] !== false) {
    failCheck('PHP 7.4 must fail the php_8 environment check.');
}

$php80 = new PasskeyEnvironment($config, $server, true, '8.0.0', true, true);
$php80Status = $php80->diagnose();
if (empty($php80Status['available'])) {
    failCheck('PHP 8.0 with HTTPS, required extensions and library must enable passkeys.');
}

$php82 = new PasskeyEnvironment($config, $server, true, '8.2.0', true, true);
$php82Status = $php82->diagnose();
if (empty($php82Status['available'])) {
    failCheck('PHP 8.2 with HTTPS, required extensions and library must enable passkeys.');
}

$http = new PasskeyEnvironment(
    ['site' => ['url' => 'http://example.com/tomos/']],
    ['HTTPS' => 'off', 'SERVER_PORT' => '80'],
    true,
    '8.2.0',
    true,
    true
);
if (!empty($http->diagnose()['available'])) {
    failCheck('HTTP must not enable passkey authentication.');
}

$missingLibrary = new PasskeyEnvironment($config, $server, false, '8.2.0', true, true);
if (!empty($missingLibrary->diagnose()['available'])) {
    failCheck('Missing WebAuthn runtime must disable passkey authentication.');
}

$postIndex = file_get_contents(dirname(__DIR__) . '/post/index.php');
if (!is_string($postIndex)) {
    failCheck('Tomos Post source must be readable.');
}
foreach ([
    "function passkeyLoginAvailable(array \$config, string \$rootDir): bool",
    'PHP_VERSION_ID < 80000',
    "'/post/security/'",
    "'/post/passkey/login/'",
    'パスキーで開く',
    '合言葉を忘れた場合',
] as $requiredSource) {
    if (strpos($postIndex, $requiredSource) === false) {
        failCheck('Tomos Post passkey entry integration is missing: ' . $requiredSource);
    }
}

echo 'passkey_php_compatibility_check: OK' . PHP_EOL;
