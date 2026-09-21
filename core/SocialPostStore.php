<?php

declare(strict_types=1);

namespace Tomos;

final class SocialPostStore
{
    private string $dir;

    public function __construct(array $config, string $rootDir)
    {
        $this->dir = rtrim($rootDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'social'
            . DIRECTORY_SEPARATOR . 'posts';
    }

    public function hasSuccessful(string $articleId, string $provider): bool
    {
        $record = $this->load($articleId, $provider);
        if ($record === null) {
            return false;
        }

        foreach ($record['attempts'] ?? [] as $attempt) {
            if (is_array($attempt) && ($attempt['status'] ?? '') === SocialPublishResult::SUCCESS) {
                return true;
            }
        }

        return false;
    }

    public function append(string $articleId, SocialPublishResult $result, string $postText): bool
    {
        if (!$this->ensureDir()) {
            return false;
        }

        $provider = strtolower(trim($result->provider));
        if ($articleId === '' || $provider === '') {
            return false;
        }

        $key = $this->key($articleId, $provider);
        $lock = @fopen($this->lockPath($key), 'c');
        if ($lock === false || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                @fclose($lock);
            }
            return false;
        }

        try {
            $record = $this->loadByKey($key);
            if ($record === null) {
                $record = [
                    'article_id' => $articleId,
                    'provider' => $provider,
                    'attempts' => [],
                ];
            }

            $record['attempts'][] = [
                'status' => $result->status,
                'code' => $result->code,
                'message' => $result->message,
                'post_text' => $postText,
                'remote_uri' => $result->remoteUri,
                'remote_cid' => $result->remoteCid,
                'posted_at' => gmdate('c'),
            ];

            return $this->writeByKey($key, $record);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    public function storageDirectory(): string
    {
        return $this->dir;
    }

    private function load(string $articleId, string $provider): ?array
    {
        return $this->loadByKey($this->key($articleId, strtolower(trim($provider))));
    }

    private function loadByKey(string $key): ?array
    {
        $raw = @file_get_contents($this->recordPath($key));
        $record = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($record) ? $record : null;
    }

    private function writeByKey(string $key, array $record): bool
    {
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return false;
        }

        $path = $this->recordPath($key);
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
            @file_put_contents(
                $rules,
                "Options -Indexes\n\nOrder allow,deny\nDeny from all\nRequire all denied\n",
                LOCK_EX
            );
        }

        return true;
    }

    private function key(string $articleId, string $provider): string
    {
        return hash('sha256', $provider . "\n" . $articleId);
    }

    private function recordPath(string $key): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $key . '.json';
    }

    private function lockPath(string $key): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $key . '.lock';
    }
}
