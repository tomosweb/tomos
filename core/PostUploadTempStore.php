<?php

declare(strict_types=1);

namespace Tomos;

final class PostUploadTempRecord
{
    public string $id;
    public string $markdown;
    public array $meta;
    /** @var array<string,string> */
    public array $imagePaths;

    /**
     * @param array<string,string> $imagePaths
     */
    public function __construct(string $id, string $markdown, array $meta, array $imagePaths = [])
    {
        $this->id = $id;
        $this->markdown = $markdown;
        $this->meta = $meta;
        $this->imagePaths = $imagePaths;
    }
}

final class PostUploadTempStore
{
    private const TTL_SECONDS = 1800;

    private string $dir;

    public function __construct(string $cacheDir)
    {
        $this->dir = rtrim($cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'post-tmp';
    }

    /**
     * @param array<string,string> $images map of image filename to source path
     */
    public function create(string $markdown, array $meta, array $images = []): ?PostUploadTempRecord
    {
        if (!$this->ensureDir()) {
            return null;
        }

        $this->cleanupExpired();

        $id = bin2hex(random_bytes(24));
        $now = time();
        $meta = $meta + [
            'created_at' => $now,
            'expires_at' => $now + self::TTL_SECONDS,
        ];
        $meta['content_hash'] = hash('sha256', $markdown);

        $markdownPath = $this->markdownPath($id);
        $metaPath = $this->metaPath($id);
        if ($images !== []) {
            $meta['image_files'] = array_keys($images);
        }

        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($metaJson === false) {
            return null;
        }

        if (@file_put_contents($markdownPath, $markdown, LOCK_EX) === false) {
            return null;
        }
        @chmod($markdownPath, 0600);

        if (@file_put_contents($metaPath, $metaJson . "\n", LOCK_EX) === false) {
            @unlink($markdownPath);
            return null;
        }
        @chmod($metaPath, 0600);

        $imagePaths = [];
        if ($images !== []) {
            $imageDir = $this->imageDir($id);
            if (!is_dir($imageDir) && !@mkdir($imageDir, 0775, true) && !is_dir($imageDir)) {
                $this->delete($id);
                return null;
            }

            foreach ($images as $fileName => $sourcePath) {
                if (!$this->isSafeImageFileName((string) $fileName) || !is_file($sourcePath)) {
                    $this->delete($id);
                    return null;
                }

                $targetPath = $imageDir . DIRECTORY_SEPARATOR . $fileName;
                if (!@copy($sourcePath, $targetPath)) {
                    $this->delete($id);
                    return null;
                }
                @chmod($targetPath, 0600);
                $imagePaths[(string) $fileName] = $targetPath;
            }
        }

        return new PostUploadTempRecord($id, $markdown, $meta, $imagePaths);
    }

    public function load(string $id, ?string $sessionId = null): ?PostUploadTempRecord
    {
        if (!$this->isValidId($id)) {
            return null;
        }

        $markdownPath = $this->markdownPath($id);
        $metaPath = $this->metaPath($id);
        if (!is_file($markdownPath) || !is_file($metaPath)) {
            return null;
        }

        $metaRaw = @file_get_contents($metaPath);
        $markdown = @file_get_contents($markdownPath);
        if ($metaRaw === false || $markdown === false) {
            $this->delete($id);
            return null;
        }

        $meta = json_decode($metaRaw, true);
        if (!is_array($meta)) {
            $this->delete($id);
            return null;
        }

        if ((int) ($meta['expires_at'] ?? 0) < time()) {
            $this->delete($id);
            return null;
        }

        if ($sessionId !== null && isset($meta['session_id']) && !hash_equals((string) $meta['session_id'], $sessionId)) {
            return null;
        }

        $hash = (string) ($meta['content_hash'] ?? '');
        if ($hash === '' || !hash_equals($hash, hash('sha256', $markdown))) {
            $this->delete($id);
            return null;
        }

        $imagePaths = [];
        foreach ((array) ($meta['image_files'] ?? []) as $fileName) {
            $fileName = (string) $fileName;
            if (!$this->isSafeImageFileName($fileName)) {
                $this->delete($id);
                return null;
            }

            $path = $this->imageDir($id) . DIRECTORY_SEPARATOR . $fileName;
            if (!is_file($path)) {
                $this->delete($id);
                return null;
            }
            $imagePaths[$fileName] = $path;
        }

        return new PostUploadTempRecord($id, $markdown, $meta, $imagePaths);
    }

    public function delete(string $id): void
    {
        if (!$this->isValidId($id)) {
            return;
        }

        @unlink($this->markdownPath($id));
        @unlink($this->metaPath($id));
        $imageDir = $this->imageDir($id);
        if (is_dir($imageDir)) {
            $files = glob($imageDir . DIRECTORY_SEPARATOR . '*');
            if ($files !== false) {
                foreach ($files as $file) {
                    is_file($file) && @unlink($file);
                }
            }
            @rmdir($imageDir);
        }
    }

    public function cleanupExpired(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $id = basename($file, '.json');
            if (!$this->isValidId($id)) {
                continue;
            }

            $metaRaw = @file_get_contents($file);
            $meta = is_string($metaRaw) ? json_decode($metaRaw, true) : null;
            if (!is_array($meta) || (int) ($meta['expires_at'] ?? 0) < time()) {
                $this->delete($id);
            }
        }
    }

    private function ensureDir(): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return false;
        }

        $htaccess = $this->dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            $rules = "Options -Indexes\n\nOrder allow,deny\nDeny from all\nRequire all denied\n";
            @file_put_contents($htaccess, $rules, LOCK_EX);
        }

        $gitkeep = $this->dir . DIRECTORY_SEPARATOR . '.gitkeep';
        if (!is_file($gitkeep)) {
            @file_put_contents($gitkeep, '');
        }

        return true;
    }

    private function markdownPath(string $id): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $id . '.md.tmp';
    }

    private function metaPath(string $id): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $id . '.json';
    }

    private function imageDir(string $id): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $id . '-images';
    }

    private function isValidId(string $id): bool
    {
        return preg_match('/\A[a-f0-9]{48}\z/', $id) === 1;
    }

    private function isSafeImageFileName(string $fileName): bool
    {
        return preg_match('/\Atms-[a-f0-9]{16}\.(jpg|jpeg|png|gif|webp)\z/i', $fileName) === 1;
    }
}
