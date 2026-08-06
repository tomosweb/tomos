<?php

declare(strict_types=1);

namespace Tomos;

final class PostAuthRememberToken
{
    public const COOKIE_NAME = 'tomos_post_remember';
    public const LIFETIME_SECONDS = 2592000;

    private string $dir;
    private string $cookiePath;
    private bool $secure;
    private int $now;

    public function __construct(
        array $config,
        string $rootDir,
        ?int $now = null,
        ?string $cookiePath = null,
        ?bool $secure = null
    ) {
        $cacheDir = (string) (($config['paths']['cache_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'cache'));
        $this->dir = rtrim($cacheDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'security'
            . DIRECTORY_SEPARATOR . 'post-auth';
        $this->cookiePath = $cookiePath ?? $this->configuredCookiePath($config);
        $this->secure = $secure ?? $this->requestIsSecure($config);
        $this->now = $now ?? time();

        $this->maybeCleanup();
    }

    public function restoreSession(): bool
    {
        if (!empty($_SESSION['tomos_post_authenticated'])) {
            return true;
        }

        $token = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');
        if (!$this->validToken($token)) {
            if ($token !== '') {
                $this->clearCookie();
            }
            return false;
        }

        $tokenHash = hash('sha256', $token);
        $record = $this->load($tokenHash);
        if ($record === null
            || !hash_equals((string) ($record['token_hash'] ?? ''), $tokenHash)
            || (int) ($record['expires_at'] ?? 0) <= $this->now
        ) {
            @unlink($this->path($tokenHash));
            $this->clearCookie();
            return false;
        }

        $_SESSION['tomos_post_authenticated'] = true;
        return true;
    }

    public function rememberCurrentBrowser(): bool
    {
        if (empty($_SESSION['tomos_post_authenticated']) || !$this->ensureDir()) {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $record = [
            'token_hash' => $tokenHash,
            'created_at' => $this->now,
            'expires_at' => $this->now + self::LIFETIME_SECONDS,
        ];

        if (!$this->write($tokenHash, $record)) {
            return false;
        }

        if (!setcookie(self::COOKIE_NAME, $token, $this->cookieOptions($record['expires_at']))) {
            @unlink($this->path($tokenHash));
            return false;
        }

        $_COOKIE[self::COOKIE_NAME] = $token;
        return true;
    }

    public function forgetCurrentBrowser(): void
    {
        $token = (string) ($_COOKIE[self::COOKIE_NAME] ?? '');
        if ($this->validToken($token)) {
            @unlink($this->path(hash('sha256', $token)));
        }

        unset($_SESSION['tomos_post_authenticated']);
        $this->clearCookie();
    }

    public function invalidateAll(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
                @unlink($file);
            }
        }

        unset($_SESSION['tomos_post_authenticated']);
        $this->clearCookie();
    }

    /** @return array{expires:int,path:string,secure:bool,httponly:bool,samesite:string} */
    public function cookieOptions(int $expiresAt): array
    {
        return [
            'expires' => $expiresAt,
            'path' => $this->cookiePath,
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    public function storageDirectory(): string
    {
        return $this->dir;
    }

    private function load(string $tokenHash): ?array
    {
        $raw = @file_get_contents($this->path($tokenHash));
        $record = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($record) ? $record : null;
    }

    private function write(string $tokenHash, array $record): bool
    {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return false;
        }

        $path = $this->path($tokenHash);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0600);
        return true;
    }

    private function ensureDir(): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return false;
        }

        $rules = $this->dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($rules)) {
            @file_put_contents($rules, "Options -Indexes\n\nOrder allow,deny\nDeny from all\nRequire all denied\n", LOCK_EX);
        }
        return true;
    }

    private function clearCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', $this->cookieOptions($this->now - 3600));
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    private function path(string $tokenHash): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $tokenHash . '.json';
    }

    private function validToken(string $token): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
    }

    private function configuredCookiePath(array $config): string
    {
        $site = is_array($config['site'] ?? null) ? $config['site'] : [];
        $basePath = trim((string) (($site['public_base_path'] ?? '') ?: ($site['base_path'] ?? '')));
        $basePath = '/' . trim($basePath, '/');
        if ($basePath === '/') {
            $basePath = '';
        }
        return $basePath . '/post/';
    }

    private function requestIsSecure(array $config): bool
    {
        $site = is_array($config['site'] ?? null) ? $config['site'] : [];
        if (strtolower((string) parse_url((string) ($site['url'] ?? ''), PHP_URL_SCHEME)) === 'https') {
            return true;
        }
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $forwarded === 'https';
    }

    private function maybeCleanup(): void
    {
        if (!is_dir($this->dir) || random_int(1, 50) !== 1) {
            return;
        }

        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            $record = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($record) || (int) ($record['expires_at'] ?? 0) <= $this->now) {
                @unlink($file);
            }
        }
    }
}
