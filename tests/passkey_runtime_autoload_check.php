<?php

declare(strict_types=1);

$path = dirname(__DIR__) . '/core/PasskeyEnvironment.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "Could not read PasskeyEnvironment.php\n");
    exit(1);
}

$required = [
    'PHP_VERSION_ID >= 80000',
    "__DIR__ . '/webauthn/vendor/autoload.php'",
    'require_once $vendor',
    "class_exists('lbuchs\\\\WebAuthn\\\\WebAuthn')",
];

foreach ($required as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing runtime autoload guard: {$needle}\n");
        exit(1);
    }
}

echo "passkey_runtime_autoload_check: OK\n";
