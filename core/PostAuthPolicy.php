<?php

declare(strict_types=1);

namespace Tomos;

/**
 * One server-side policy for Tomos Post authentication.
 * Never fall back to passphrase sign-in when passkey-only mode is active.
 */
final class PostAuthPolicy
{
    public const BOTH = 'both';
    public const PASSKEY_ONLY = 'passkey_only';

    public static function mode(array $config): string
    {
        return ($config['security']['post_login_mode'] ?? '') === self::PASSKEY_ONLY
            ? self::PASSKEY_ONLY : self::BOTH;
    }

    public static function allowsPassphrase(array $config): bool
    {
        return self::mode($config) === self::BOTH;
    }

    public static function isAuthenticated(array $config): bool
    {
        if (empty($_SESSION['tomos_post_authenticated'])) {
            return false;
        }
        $epoch = (string) ($config['security']['post_auth_epoch'] ?? '');
        $sessionEpoch = (string) ($_SESSION['tomos_post_auth_epoch'] ?? '');
        if (($epoch !== '' && !hash_equals($epoch, $sessionEpoch))
            || (!self::allowsPassphrase($config)
                && (string) ($_SESSION['tomos_post_auth_method'] ?? '') !== 'passkey')
        ) {
            self::clearSession();
            return false;
        }
        return true;
    }

    public static function markAuthenticated(array $config, string $method): void
    {
        if (!in_array($method, ['passphrase', 'passkey'], true)
            || ($method === 'passphrase' && !self::allowsPassphrase($config))
        ) {
            throw new \RuntimeException('この認証方式は現在利用できません。');
        }
        $_SESSION['tomos_post_authenticated'] = true;
        $_SESSION['tomos_post_auth_method'] = $method;
        $_SESSION['tomos_post_auth_epoch'] = (string) ($config['security']['post_auth_epoch'] ?? '');
    }

    public static function recentlyVerifiedPasskey(array $config, int $seconds = 300): bool
    {
        $at = (int) ($_SESSION['tomos_passkey_authenticated_at'] ?? 0);
        return self::isAuthenticated($config)
            && (string) ($_SESSION['tomos_post_auth_method'] ?? '') === 'passkey'
            && $at > 0 && $at <= time() && time() - $at <= $seconds;
    }

    public static function clearSession(): void
    {
        unset(
            $_SESSION['tomos_post_authenticated'],
            $_SESSION['tomos_post_auth_method'],
            $_SESSION['tomos_post_auth_epoch'],
            $_SESSION['tomos_passkey_authenticated_at'],
            $_SESSION['tomos_passkey_registration_authorized_until'],
            $_SESSION['tomos_passkey_password_reset_authorized_until']
        );
    }
}
