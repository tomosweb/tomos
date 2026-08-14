<?php

declare(strict_types=1);

final class InstallerSecurity
{
    public const BOOTSTRAP_TTL = 900;
    private const SESSION_BOOTSTRAP = 'tomos_installer_bootstrap';
    private const SESSION_CSRF = 'tomos_installer_csrf';

    public static function startSession(bool $https = true): void
    {
        if (!$https) {
            self::fail('session', 'HTTPS is required.');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_secure', '1');
        @ini_set('session.cookie_samesite', 'Lax');
        if (!@session_start([
            'cookie_secure' => true,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ])) {
            self::fail('session', 'Could not start session.');
        }
    }

    public static function bootstrap(int $now = 0): array
    {
        self::requireSession();
        $now = $now > 0 ? $now : time();
        $state = $_SESSION[self::SESSION_BOOTSTRAP] ?? null;
        if (!is_array($state) || !is_string($state['id'] ?? null) || (int) ($state['expires_at'] ?? 0) < $now) {
            if (!@session_regenerate_id(true)) {
                self::fail('session', 'Could not rotate session ID.');
            }
            $state = [
                'id' => bin2hex(random_bytes(32)),
                'created_at' => $now,
                'expires_at' => $now + self::BOOTSTRAP_TTL,
            ];
            $_SESSION[self::SESSION_BOOTSTRAP] = $state;
        }
        if (!isset($_SESSION[self::SESSION_CSRF]) || !is_string($_SESSION[self::SESSION_CSRF])) {
            $_SESSION[self::SESSION_CSRF] = bin2hex(random_bytes(32));
        }
        return $state;
    }

    public static function csrfToken(): string
    {
        self::requireSession();
        $token = $_SESSION[self::SESSION_CSRF] ?? '';
        if (!is_string($token) || $token === '') {
            self::fail('csrf', 'CSRF token is unavailable.');
        }
        return $token;
    }

    public static function assertPostOwner(string $token, int $now = 0): void
    {
        self::requireSession();
        $now = $now > 0 ? $now : time();
        $state = $_SESSION[self::SESSION_BOOTSTRAP] ?? null;
        if (!is_array($state) || !is_string($state['id'] ?? null) || (int) ($state['expires_at'] ?? 0) < $now) {
            self::fail('bootstrap_owner', 'Installer bootstrap ownership is missing or expired.');
        }
        $csrf = $_SESSION[self::SESSION_CSRF] ?? '';
        if (!is_string($csrf) || $token === '' || !hash_equals($csrf, $token)) {
            self::fail('csrf', 'CSRF validation failed.');
        }
    }

    public static function validateUrl(string $url, array $allowedHosts, string $errorCode): void
    {
        if (strlen($url) > 2048) {
            self::fail($errorCode, 'URL is too long.');
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || isset($parts['query'])
            || ($port !== null && (int) $port !== 443)
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || !in_array($host, array_map('strtolower', $allowedHosts), true)
        ) {
            self::fail($errorCode, 'URL is not allowed.');
        }
    }

    private static function requireSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::fail('session', 'Session is not active.');
        }
    }

    private static function fail(string $code, string $message): void
    {
        throw new InstallManifestException($code, $message);
    }
}
