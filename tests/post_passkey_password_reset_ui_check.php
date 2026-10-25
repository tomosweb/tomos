<?php

declare(strict_types=1);

function checkResetUi(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$reset = (string) file_get_contents($root . '/post/passkey/password-reset/index.php');
$recovery = (string) file_get_contents($root . '/post/passkey/recovery/index.php');
$service = (string) file_get_contents($root . '/core/PasskeyPasswordResetService.php');

foreach (['id="generate-button"', 'id="show-button"', 'id="copy-button"',
    'window.crypto.getRandomValues', 'navigator.clipboard.writeText',
    '1文字以上・4096バイト以内', 'パスワード管理アプリなどへ保存してください。',
    "tomosPostReturnLink((string) \$postUrl, 'Tomos Postを開く')"] as $text) {
    checkResetUi(strpos($reset, $text) !== false, 'password-reset UI is missing: ' . $text);
}
checkResetUi(strpos($reset, 'セキュリティへ戻る') === false, 'password-reset shows improper back link');
checkResetUi(strpos($recovery, "tomosPostReturnLink((string) \$postUrl, 'Tomos Postを開く')") !== false, 'recovery link must return to Post');
checkResetUi(strpos($recovery, 'セキュリティへ戻る') === false, 'recovery shows improper back link');
checkResetUi(strpos($service, 'strlen($newPassphrase) > 4096') !== false, 'UI byte limit must agree with service');
// Both reauthentication and successful reset rotate the PHP session ID.
// A subdirectory installation requires the replacement cookie to use Tomos Post's path.
checkResetUi(substr_count($reset, '$remember->refreshSessionCookiePath();') === 2,
    'both password-reset session rotations must refresh the Post cookie path');

echo "post_passkey_password_reset_ui_check: PASS\n";
