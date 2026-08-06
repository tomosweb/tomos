<?php

declare(strict_types=1);

namespace Tomos;

final class PostRateLimitResult
{
    public bool $allowed;
    public string $message;

    public function __construct(bool $allowed, string $message = '')
    {
        $this->allowed = $allowed;
        $this->message = $message;
    }
}

final class PostRateLimiter
{
    private const FAILURE_WINDOW_SECONDS = 600;
    private const FAILURE_LIMIT = 5;
    private const BLOCK_SECONDS = 900;
    private const RESET_MIN_INTERVAL_SECONDS = 60;
    private const CLEANUP_MAX_AGE_SECONDS = 86400;
    private const AUTH_LIMIT_MESSAGE = '管理用合言葉の入力に複数回失敗したため、15分間操作を停止しています。';
    private const RESET_LIMIT_MESSAGE = '合言葉の再設定を続けて実行することはできません。しばらく時間をおいてから再度お試しください。';

    private string $dir;
    private string $path;
    private int $now;

    public function __construct(array $config, string $rootDir, string $clientIp, ?int $now = null)
    {
        $cacheDir = (string) (($config['paths']['cache_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'cache'));
        $this->dir = rtrim($cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'post-rate-limit';
        $this->now = $now ?? time();

        $security = is_array($config['security'] ?? null) ? $config['security'] : [];
        $site = is_array($config['site'] ?? null) ? $config['site'] : [];
        $salt = (string) ($security['rate_limit_salt'] ?? '');
        if ($salt === '') {
            $salt = (string) ($site['url'] ?? '') . '|' . $rootDir;
        }

        $ipHash = hash_hmac('sha256', $clientIp !== '' ? $clientIp : 'unknown', $salt);
        $this->path = $this->dir . DIRECTORY_SEPARATOR . $ipHash . '.json';

        $this->maybeCleanup();
    }

    public static function generateSalt(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function checkAuthAllowed(): PostRateLimitResult
    {
        $state = $this->load();
        if ($this->isBlocked($state)) {
            return new PostRateLimitResult(false, self::AUTH_LIMIT_MESSAGE);
        }

        return new PostRateLimitResult(true);
    }

    public function recordFailure(): void
    {
        $state = $this->load();
        $failures = $this->recentFailures($state);
        $failures[] = $this->now;
        $state['failures'] = $failures;

        if (count($failures) >= self::FAILURE_LIMIT) {
            $state['blocked_until'] = $this->now + self::BLOCK_SECONDS;
        }

        $this->save($state);
    }

    public function clearFailures(): void
    {
        $state = $this->load();
        $state['failures'] = [];
        $state['blocked_until'] = 0;
        $this->save($state);
    }

    public function checkResetAllowed(): PostRateLimitResult
    {
        $state = $this->load();
        $lastResetAt = (int) ($state['last_reset_at'] ?? 0);
        if ($lastResetAt > 0 && ($this->now - $lastResetAt) < self::RESET_MIN_INTERVAL_SECONDS) {
            return new PostRateLimitResult(false, self::RESET_LIMIT_MESSAGE);
        }

        return new PostRateLimitResult(true);
    }

    public function recordResetAttempt(): void
    {
        $state = $this->load();
        $state['last_reset_at'] = $this->now;
        $this->save($state);
    }

    private function isBlocked(array &$state): bool
    {
        $blockedUntil = (int) ($state['blocked_until'] ?? 0);
        if ($blockedUntil > $this->now) {
            return true;
        }

        if ($blockedUntil !== 0) {
            $state['blocked_until'] = 0;
            $this->save($state);
        }

        return false;
    }

    private function recentFailures(array $state): array
    {
        $failures = is_array($state['failures'] ?? null) ? $state['failures'] : [];
        $threshold = $this->now - self::FAILURE_WINDOW_SECONDS;

        return array_values(array_map('intval', array_filter($failures, function ($timestamp) use ($threshold): bool {
            return is_numeric($timestamp) && (int) $timestamp >= $threshold;
        })));
    }

    private function load(): array
    {
        if (!is_file($this->path)) {
            return $this->defaultState();
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            return $this->defaultState();
        }

        $state = json_decode($raw, true);
        if (!is_array($state)) {
            return $this->defaultState();
        }

        $state['failures'] = $this->recentFailures($state);
        $state['blocked_until'] = (int) ($state['blocked_until'] ?? 0);
        $state['last_reset_at'] = (int) ($state['last_reset_at'] ?? 0);

        return $state;
    }

    private function save(array $state): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return;
        }

        $json = json_encode([
            'failures' => array_values(is_array($state['failures'] ?? null) ? $state['failures'] : []),
            'blocked_until' => (int) ($state['blocked_until'] ?? 0),
            'last_reset_at' => (int) ($state['last_reset_at'] ?? 0),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($json === false) {
            return;
        }

        @file_put_contents($this->path, $json . "\n", LOCK_EX);
    }

    private function defaultState(): array
    {
        return [
            'failures' => [],
            'blocked_until' => 0,
            'last_reset_at' => 0,
        ];
    }

    private function maybeCleanup(): void
    {
        if (random_int(1, 50) !== 1 || !is_dir($this->dir)) {
            return;
        }

        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            return;
        }

        $threshold = $this->now - self::CLEANUP_MAX_AGE_SECONDS;
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $threshold) {
                @unlink($file);
            }
        }
    }
}
