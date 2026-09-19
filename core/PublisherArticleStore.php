<?php

declare(strict_types=1);

namespace Tomos;

final class PublisherArticleStore
{
    private string $dir;

    public function __construct(array $config, string $rootDir)
    {
        $inboxDir = rtrim((string) (($config['paths']['inbox_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'inbox')), DIRECTORY_SEPARATOR);
        $this->dir = $inboxDir . DIRECTORY_SEPARATOR . '.publisher-articles';
    }

    public function markManaged(string $contentPath, string $requestId): bool
    {
        $contentPath = $this->normalizeContentPath($contentPath);
        if ($contentPath === '' || !PublisherStatusStore::isValidRequestId($requestId) || !$this->ensureDirectory()) {
            return false;
        }

        $payload = [
            'content_path' => $contentPath,
            'last_request_id' => $requestId,
            'updated_at' => gmdate('c'),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        $path = $this->path($contentPath);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0600);
        return @rename($tmp, $path);
    }

    public function isManaged(string $contentPath): bool
    {
        $contentPath = $this->normalizeContentPath($contentPath);
        if ($contentPath === '' || !$this->ensureDirectory()) {
            return false;
        }

        $raw = @file_get_contents($this->path($contentPath));
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data)
            && is_string($data['content_path'] ?? null)
            && hash_equals((string) $data['content_path'], $contentPath);
    }

    private function normalizeContentPath(string $contentPath): string
    {
        $contentPath = str_replace('\\', '/', trim($contentPath));
        $contentPath = preg_replace('#/+#', '/', $contentPath) ?? '';
        return trim($contentPath, '/');
    }

    private function path(string $contentPath): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . hash('sha256', $contentPath) . '.json';
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
