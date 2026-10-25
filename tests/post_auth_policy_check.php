<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostAuthPolicy.php';

use Tomos\PostAuthPolicy;

function checkPolicy(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$both = ['security' => ['post_login_mode' => 'both', 'post_auth_epoch' => 'epoch-a']];
$only = ['security' => ['post_login_mode' => 'passkey_only', 'post_auth_epoch' => 'epoch-b']];

$_SESSION = [];
checkPolicy(PostAuthPolicy::allowsPassphrase($both), 'both mode permits passphrase');
checkPolicy(!PostAuthPolicy::allowsPassphrase($only), 'passkey-only rejects passphrase');
checkPolicy(!PostAuthPolicy::allowsPassphrase(['security' => ['post_login_mode' => 'unexpected']]), 'unknown mode must fail closed');
checkPolicy(!PostAuthPolicy::isAuthenticated($both), 'anonymous session denied');

PostAuthPolicy::markAuthenticated($both, 'passphrase');
checkPolicy(PostAuthPolicy::isAuthenticated($both), 'passphrase session allowed in both mode');
checkPolicy(!PostAuthPolicy::isAuthenticated($only), 'passphrase session rejected in passkey-only mode');
checkPolicy(empty($_SESSION['tomos_post_authenticated']), 'rejected session is cleared');

$rejected = false;
try {
    PostAuthPolicy::markAuthenticated($only, 'passphrase');
} catch (RuntimeException $exception) {
    $rejected = true;
}
checkPolicy($rejected, 'creating passphrase session in passkey-only mode must fail');

PostAuthPolicy::markAuthenticated($only, 'passkey');
$_SESSION['tomos_passkey_authenticated_at'] = time();
checkPolicy(PostAuthPolicy::isAuthenticated($only), 'passkey session allowed');
checkPolicy(PostAuthPolicy::recentlyVerifiedPasskey($only), 'fresh passkey session valid for sensitive operations');

$rotated = $only;
$rotated['security']['post_auth_epoch'] = 'epoch-c';
checkPolicy(!PostAuthPolicy::isAuthenticated($rotated), 'rotating auth epoch revokes existing session');
checkPolicy(empty($_SESSION['tomos_post_authenticated']), 'revoked session flag cleared');

PostAuthPolicy::markAuthenticated($only, 'passkey');
$_SESSION['tomos_passkey_authenticated_at'] = time() - 360;
checkPolicy(!PostAuthPolicy::recentlyVerifiedPasskey($only), 'stale verification does not authorize sensitive operation');
PostAuthPolicy::clearSession();
checkPolicy(!PostAuthPolicy::isAuthenticated($only), 'logout clears session');

// An anonymous registration grant must not survive a generation rotation.
$_SESSION = ['tomos_passkey_registration_authorized_until' => time() + 300,
    'tomos_passkey_registration_epoch' => 'epoch-a'];
PostAuthPolicy::isAuthenticated($only);
checkPolicy(!isset($_SESSION['tomos_passkey_registration_authorized_until']), 'anonymous stale registration grant revoked');
PostAuthPolicy::markAuthenticated($only, 'passkey');
$_SESSION['tomos_passkey_authenticated_at'] = time() + 1;
checkPolicy(!PostAuthPolicy::recentlyVerifiedPasskey($only), 'future verification rejected');
$_SESSION['tomos_passkey_authenticated_at'] = time() - 301;
checkPolicy(!PostAuthPolicy::recentlyVerifiedPasskey($only), '301-second verification rejected');
PostAuthPolicy::clearSession();
echo "post_auth_policy_check: OK\n";
