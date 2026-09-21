<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyAccountStore
{
    private string $dir;
    private string $path;

    public function __construct(string $rootDir)
    {
        $this->dir = rtrim($rootDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'social';
        $this->path = $this->dir . DIRECTORY_SEPARATOR . 'bluesky-account.json';
    }

    /** @return array<string,mixed>|null */
    public function load(): ?array
    {
        $raw = @file_get_contents($this->path);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($record) ? $record : null;
    }

    /** @param array<string,mixed> $record */
    public function save(array $record): bool
    {
        if (!$this->ensureDir()) {
            return false;
        }
        $record['provider'] = 'bluesky';
        $record['connected_at'] = (string) ($record['connected_at'] ?? gmdate('c'));
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return false;
        }
        $tmp = $this->path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $this->path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($this->path, 0600);
        return true;
    }

    public function delete(): bool
    {
        return !is_file($this->path) || @unlink($this->path);
    }

    /**
     * Execute an account-session update while holding an exclusive lock.
     *
     * @param callable(array<string,mixed>|null):array<string,mixed> $callback
     * @return array<string,mixed>
     */
    public function updateLocked(callable $callback): array
    {
        if (!$this->ensureDir()) {
            throw new \RuntimeException('Bluesky account storage is unavailable.');
        }

        $lockPath = $this->dir . DIRECTORY_SEPARATOR . 'bluesky-account.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                @fclose($lock);
            }
            throw new \RuntimeException('Bluesky account session could not be locked.');
        }

        try {
            $current = $this->load();
            $next = $callback($current);
            if (!is_array($next) || !$this->save($next)) {
                throw new \RuntimeException('Bluesky account session could not be saved.');
            }
            return $next;
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
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
}
