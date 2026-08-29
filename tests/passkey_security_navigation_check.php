<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$targets = [
    'security' => $root . '/post/security/index.php',
    'manage' => $root . '/post/passkey/manage/index.php',
];

foreach ($targets as $name => $path) {
    $source = file_get_contents($path);
    if (!is_string($source) || $source === '') {
        fwrite(STDERR, $name . ": source missing\n");
        exit(1);
    }

    if (strpos($source, 'name="action" value="passphrase_auth"') === false) {
        fwrite(STDERR, $name . ": passphrase_auth form missing\n");
        exit(1);
    }

    if (strpos($source, '>管理用合言葉で認証</a>') !== false) {
        fwrite(STDERR, $name . ": passphrase authentication must not be a link back to Tomos Post\n");
        exit(1);
    }

    if (strpos($source, 'RP ID:') !== false) {
        fwrite(STDERR, $name . ": RP ID must not be shown in the normal security UI\n");
        exit(1);
    }
    if ($name === 'security') {
        foreach (['PostAuthReturnTo::normalize', 'PostAuthReturnTo::url', 'name="return_to"', 'passkeyUrl'] as $needle) {
            if (strpos($source, $needle) === false) {
                fwrite(STDERR, $name . ": return_to-aware Security flow is missing: {$needle}\n");
                exit(1);
            }
        }
    }
}

$passkeyLogin = file_get_contents($root . '/post/passkey/login/index.php');
if (!is_string($passkeyLogin)
    || strpos($passkeyLogin, 'PostAuthReturnTo::normalize') === false
    || strpos($passkeyLogin, 'returnUrl') === false
    || strpos($passkeyLogin, 'refreshSessionCookiePath') === false
) {
    fwrite(STDERR, "passkey login return flow is missing\n");
    exit(1);
}

echo "passkey_security_navigation_check: OK\n";
