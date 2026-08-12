<?php

declare(strict_types=1);

namespace Tomos;

final class PostSubmissionGuardResult
{
    public bool $allowed;
    public string $message;

    public function __construct(bool $allowed, string $message = '')
    {
        $this->allowed = $allowed;
        $this->message = $message;
    }
}

final class PostSubmissionGuard
{
    public const DUPLICATE_MESSAGE = 'この投稿はすでに受け付けています。投稿結果を確認してください。';
    public const PROCESSING_MESSAGE = '投稿処理中です。完了するまでそのままお待ちください。';
    private const RETENTION_SECONDS = 86400;

    private string $dir;
    private int $now;
    /** @var resource|null */
    private $lock = null;
    private string $lockedHash = '';

    public function __construct(array $config, string $rootDir, ?int $now = null)
    {
        $cacheDir = (string) (($config['paths']['cache_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'cache'));
        $this->dir = rtrim($cacheDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'security'
            . DIRECTORY_SEPARATOR . 'post-submissions';
        $this->now = $now ?? time();
        $this->maybeCleanup();
    }

    public static function issueId(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function acquire(string $submissionId): PostSubmissionGuardResult
    {
        if (!$this->validId($submissionId) || !$this->ensureDir()) {
            return new PostSubmissionGuardResult(false, '投稿の送信情報を確認できませんでした。画面を再読み込みしてください。');
        }

        $hash = hash('sha256', $submissionId);
        $lock = @fopen($this->lockPath($hash), 'c');
        if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                @fclose($lock);
            }
            return new PostSubmissionGuardResult(false, self::PROCESSING_MESSAGE);
        }

        $record = $this->load($hash);
        if ($record !== null) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
            return new PostSubmissionGuardResult(false, self::DUPLICATE_MESSAGE);
        }

        $this->lock = $lock;
        $this->lockedHash = $hash;
        return new PostSubmissionGuardResult(true);
    }

    public function markCompleted(): bool
    {
        if (!is_resource($this->lock) || $this->lockedHash === '') {
            return false;
        }

        $record = [
            'completed_at' => $this->now,
            'expires_at' => $this->now + self::RETENTION_SECONDS,
        ];
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return false;
        }

        $path = $this->recordPath($this->lockedHash);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, 0600);
        return true;
    }

    public function release(): void
    {
        if (is_resource($this->lock)) {
            @flock($this->lock, LOCK_UN);
            @fclose($this->lock);
        }
        $this->lock = null;
        $this->lockedHash = '';
    }

    public function __destruct()
    {
        $this->release();
    }

    public function storageDirectory(): string
    {
        return $this->dir;
    }

    public function cleanupExpired(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $threshold = $this->now - self::RETENTION_SECONDS;
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            $record = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($record) || (int) ($record['completed_at'] ?? 0) <= $threshold) {
                @unlink($file);
                @unlink(substr($file, 0, -5) . '.lock');
            }
        }
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.lock') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime <= $threshold) {
                @unlink($file);
            }
        }
    }

    private function load(string $hash): ?array
    {
        $raw = @file_get_contents($this->recordPath($hash));
        $record = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($record) ? $record : null;
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

    private function validId(string $submissionId): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $submissionId) === 1;
    }

    private function recordPath(string $hash): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $hash . '.json';
    }

    private function lockPath(string $hash): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $hash . '.lock';
    }

    private function maybeCleanup(): void
    {
        if (!is_dir($this->dir) || random_int(1, 50) !== 1) {
            return;
        }

        $this->cleanupExpired();
    }
}
