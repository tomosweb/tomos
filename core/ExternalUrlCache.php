<?php

declare(strict_types=1);

namespace Tomos;

/** Small best-effort cache for external URL metadata. */
final class ExternalUrlCache
{
    public const TTL_SECONDS = 3600;

    private string $directory;

    public function __construct(string $cacheDir)
    {
        $this->directory = trim($cacheDir) === ''
            ? ''
            : rtrim($cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'external-url';
    }

    public function read(string $namespace, string $key): ?array
    {
        if ($this->directory === '') {
            return null;
        }
        $path = $this->path($namespace, $key);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $entry = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($entry) || !is_array($entry['value'] ?? null)) {
            return null;
        }

        $createdAt = (int) ($entry['created_at'] ?? 0);
        if ($createdAt <= 0 || $createdAt + self::TTL_SECONDS <= time()) {
            return null;
        }

        return $entry['value'];
    }

    public function write(string $namespace, string $key, array $value): void
    {
        if ($this->directory === '') {
            return;
        }
        $directory = $this->directory . DIRECTORY_SEPARATOR . $this->safePart($namespace);
        if (!@is_dir($directory) && !@mkdir($directory, 0700, true) && !@is_dir($directory)) {
            return;
        }

        $json = json_encode([
            'created_at' => time(),
            'value' => $value,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            return;
        }

        $path = $this->path($namespace, $key);
        $temporary = $path . '.tmp-' . $suffix;
        if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            @unlink($temporary);
            return;
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    private function path(string $namespace, string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->safePart($namespace)
            . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }

    private function safePart(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?: 'default';
    }
}
