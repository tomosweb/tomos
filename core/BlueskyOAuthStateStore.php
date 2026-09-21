<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyOAuthStateStore
{
    private const RETENTION_SECONDS = 900;
    private string $dir;

    public function __construct(string $rootDir)
    {
        $this->dir = rtrim($rootDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'social'
            . DIRECTORY_SEPARATOR . 'oauth'
            . DIRECTORY_SEPARATOR . 'states';
    }

    /** @param array<string,mixed> $record */
    public function save(string $state, array $record): bool
    {
        if (!$this->validState($state) || !$this->ensureDir()) {
            return false;
        }
        $record['created_at'] = time();
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return false;
        }
        $path = $this->path($state);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0600);
        return true;
    }

    /** @return array<string,mixed>|null */
    public function load(string $state): ?array
    {
        if (!$this->validState($state)) {
            return null;
        }
        $raw = @file_get_contents($this->path($state));
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record)) {
            return null;
        }
        $createdAt = (int) ($record['created_at'] ?? 0);
        if ($createdAt <= 0 || $createdAt < time() - self::RETENTION_SECONDS) {
            @unlink($this->path($state));
            return null;
        }
        return $record;
    }

    public function delete(string $state): void
    {
        if ($this->validState($state)) {
            @unlink($this->path($state));
        }
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

    private function path(string $state): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . hash('sha256', $state) . '.json';
    }

    private function validState(string $state): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $state) === 1;
    }
}
