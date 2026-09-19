<?php

declare(strict_types=1);

namespace Tomos;

final class PublisherStatusStore
{
    private string $dir;

    public function __construct(array $config, string $rootDir)
    {
        $inboxDir = rtrim((string) (($config['paths']['inbox_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'inbox')), DIRECTORY_SEPARATOR);
        $this->dir = $inboxDir . DIRECTORY_SEPARATOR . '.publisher-status';
    }

    public static function isValidRequestId(string $requestId): bool
    {
        return preg_match('/\A[A-Za-z0-9._-]{16,128}\z/', $requestId) === 1;
    }

    /** @param array<string,mixed> $status */
    public function save(string $requestId, array $status): bool
    {
        if (!self::isValidRequestId($requestId) || !$this->ensureDirectory()) {
            return false;
        }

        $payload = array_merge([
            'request_id' => $requestId,
            'updated_at' => gmdate('c'),
        ], $status);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        $path = $this->path($requestId);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0600);
        return @rename($tmp, $path);
    }

    /** @return array<string,mixed>|null */
    public function load(string $requestId): ?array
    {
        if (!self::isValidRequestId($requestId) || !$this->ensureDirectory()) {
            return null;
        }
        $raw = @file_get_contents($this->path($requestId));
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function path(string $requestId): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . hash('sha256', $requestId) . '.json';
    }

    private function ensureDirectory(): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            return false;
        }
        $htaccess = $this->dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Options -Indexes\n\nOrder allow,deny\nDeny from all\nRequire all denied\n", LOCK_EX);
        }
        return true;
    }
}
