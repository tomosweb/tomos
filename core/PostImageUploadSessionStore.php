<?php

declare(strict_types=1);

namespace Tomos;

final class PostImageUploadSessionStore
{
    private const TTL_SECONDS = 86400;
    private string $dir;

    public function __construct(string $cacheDir)
    {
        $this->dir = rtrim($cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'post-upload-sessions';
    }

    /** @param string[] $expectedImages */
    public function create(string $ownerSessionId, array $expectedImages): ?array
    {
        if (!$this->ensureDir()) {
            return null;
        }
        $this->cleanupExpired();
        $expected = [];
        foreach ($expectedImages as $name) {
            if (!is_string($name)) {
                return null;
            }
            $expected[] = strtolower($name);
        }
        $expected = array_values(array_unique($expected));
        if (count($expected) > PostUploadCapabilities::MAX_IMAGES) {
            return null;
        }
        foreach ($expected as $name) {
            if (!$this->safeImageName($name)) {
                return null;
            }
        }

        $id = bin2hex(random_bytes(24));
        $now = time();
        $record = [
            'id' => $id,
            'owner_hash' => hash('sha256', $ownerSessionId),
            'created_at' => $now,
            'expires_at' => $now + self::TTL_SECONDS,
            'state' => 'open',
            'expected_images' => $expected,
            'received_images' => [],
            'errors' => [],
        ];
        if (!$this->write($id, $record)) {
            return null;
        }
        return $record;
    }

    public function receive(string $id, string $ownerSessionId, string $imageName, array $file, int $effectiveMax, int $chunkIndex = 0, int $chunkCount = 1, int $totalSize = 0): array
    {
        if (!$this->validId($id) || !$this->ensureDir()) {
            return ['ok' => false, 'message' => '投稿の準備情報を確認できませんでした。'];
        }
        $lock = @fopen($this->lockPath($id), 'c');
        if ($lock === false || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) @fclose($lock);
            return ['ok' => false, 'message' => '画像の受信準備を完了できませんでした。'];
        }

        try {
            return $this->receiveLocked($id, $ownerSessionId, $imageName, $file, $effectiveMax, $chunkIndex, $chunkCount, $totalSize);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private function receiveLocked(string $id, string $ownerSessionId, string $imageName, array $file, int $effectiveMax, int $chunkIndex, int $chunkCount, int $totalSize): array
    {
        $record = $this->loadOwned($id, $ownerSessionId);
        if ($record === null || ($record['state'] ?? '') !== 'open') {
            return ['ok' => false, 'message' => '投稿の準備情報が見つからないか、有効期限が切れました。'];
        }
        $imageName = strtolower($imageName);
        if (!$this->safeImageName($imageName) || !in_array($imageName, (array) $record['expected_images'], true)) {
            return ['ok' => false, 'message' => 'この画像は投稿対象として確認できませんでした。'];
        }
        if ($chunkCount < 1 || $chunkCount > 100 || $chunkIndex < 0 || $chunkIndex >= $chunkCount) {
            return ['ok' => false, 'message' => '画像の送信順序を確認できませんでした。'];
        }
        if ($totalSize <= 0 || $totalSize > PostUploadCapabilities::TOMOS_IMAGE_MAX_BYTES) {
            return ['ok' => false, 'capacity' => true, 'message' => 'この画像は、現在の公開先で扱える容量を超えています。別の画像を選んでください。'];
        }
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return ['ok' => false, 'capacity' => true, 'message' => 'この画像は、現在の公開先で扱える容量を超えています。別の画像を選んでください。'];
        }
        if ($uploadError !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => '画像の送信を完了できませんでした。'];
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmpPath === '' || !is_uploaded_file($tmpPath) || $size <= 0) {
            return ['ok' => false, 'message' => '送信された画像を確認できませんでした。'];
        }
        if ($size > $effectiveMax) {
            return ['ok' => false, 'capacity' => true, 'message' => 'この画像は、現在の公開先で扱える容量を超えています。別の画像を選んでください。'];
        }
        $imageDir = $this->imageDir($id);
        if (!is_dir($imageDir) && !@mkdir($imageDir, 0775, true) && !is_dir($imageDir)) {
            return ['ok' => false, 'message' => '画像の一時保存先を準備できませんでした。'];
        }
        $target = $imageDir . DIRECTORY_SEPARATOR . $imageName;
        $partial = $target . '.part';
        $chunkState = is_array($record['image_chunks'][$imageName] ?? null)
            ? $record['image_chunks'][$imageName]
            : ['next_index' => 0, 'chunk_count' => $chunkCount, 'total_size' => $totalSize, 'received_bytes' => 0];
        $nextIndex = (int) ($chunkState['next_index'] ?? 0);
        if ($chunkIndex < $nextIndex) {
            return ['ok' => true, 'received' => count($record['received_images']), 'total' => count($record['expected_images'])];
        }
        if ($chunkIndex !== $nextIndex || (int) ($chunkState['chunk_count'] ?? 0) !== $chunkCount || (int) ($chunkState['total_size'] ?? 0) !== $totalSize) {
            return ['ok' => false, 'message' => '画像の送信順序を確認できませんでした。'];
        }
        if ($chunkIndex === 0) {
            @unlink($partial);
        }
        $input = @fopen($tmpPath, 'rb');
        $output = @fopen($partial, 'ab');
        $copied = is_resource($input) && is_resource($output) ? @stream_copy_to_stream($input, $output) : false;
        if (is_resource($input)) @fclose($input);
        if (is_resource($output)) @fclose($output);
        if ($copied === false || (int) $copied !== $size) {
            return ['ok' => false, 'message' => '画像を一時保存できませんでした。'];
        }
        $chunkState['next_index'] = $chunkIndex + 1;
        $chunkState['received_bytes'] = (int) ($chunkState['received_bytes'] ?? 0) + $size;
        $record['image_chunks'][$imageName] = $chunkState;

        if ($chunkIndex + 1 === $chunkCount) {
            if ((int) $chunkState['received_bytes'] !== $totalSize || (int) @filesize($partial) !== $totalSize) {
                @unlink($partial);
                unset($record['image_chunks'][$imageName]);
                $this->write($id, $record);
                return ['ok' => false, 'message' => '画像の受信サイズを確認できませんでした。'];
            }
            $hash = hash_file('sha256', $partial);
            $expectedHash = substr($imageName, 4, 16);
            if (!is_string($hash) || !hash_equals($expectedHash, substr($hash, 0, 16))) {
                @unlink($partial);
                unset($record['image_chunks'][$imageName]);
                $this->write($id, $record);
                return ['ok' => false, 'message' => 'Markdownと一致する画像を確認できませんでした。'];
            }
            if (!@rename($partial, $target)) {
                return ['ok' => false, 'message' => '画像を一時保存できませんでした。'];
            }
            @chmod($target, 0600);
            $record['received_images'][$imageName] = ['size' => $totalSize, 'received_at' => time()];
        }
        if (!$this->write($id, $record)) {
            return ['ok' => false, 'message' => '画像の受信状態を保存できませんでした。'];
        }
        return ['ok' => true, 'received' => count($record['received_images']), 'total' => count($record['expected_images'])];
    }

    /** @return array<string,string>|null */
    public function readyImages(string $id, string $ownerSessionId): ?array
    {
        $record = $this->loadOwned($id, $ownerSessionId);
        if ($record === null || ($record['state'] ?? '') !== 'open') {
            return null;
        }
        $paths = [];
        foreach ((array) $record['expected_images'] as $name) {
            if (!isset($record['received_images'][$name])) {
                return null;
            }
            $path = $this->imageDir($id) . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                return null;
            }
            $paths[$name] = $path;
        }
        return $paths;
    }

    public function deleteOwned(string $id, string $ownerSessionId): bool
    {
        if ($this->loadOwned($id, $ownerSessionId) === null) {
            return false;
        }
        $this->delete($id);
        return true;
    }

    public function cleanupExpired(): void
    {
        if (!is_dir($this->dir)) return;
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $record = json_decode((string) @file_get_contents($file), true);
            if (!is_array($record) || (int) ($record['expires_at'] ?? 0) < time()) {
                $this->delete(basename($file, '.json'));
            }
        }
    }

    private function loadOwned(string $id, string $ownerSessionId): ?array
    {
        if (!$this->validId($id)) return null;
        $raw = @file_get_contents($this->metaPath($id));
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record) || (int) ($record['expires_at'] ?? 0) < time()) {
            if (is_array($record)) $this->delete($id);
            return null;
        }
        $ownerHash = (string) ($record['owner_hash'] ?? '');
        return $ownerHash !== '' && hash_equals($ownerHash, hash('sha256', $ownerSessionId)) ? $record : null;
    }

    private function write(string $id, array $record): bool
    {
        if (!$this->ensureDir() || !$this->validId($id)) return false;
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) return false;
        $tmp = $this->metaPath($id) . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $this->metaPath($id))) {
            @unlink($tmp);
            return false;
        }
        @chmod($this->metaPath($id), 0600);
        return true;
    }

    private function delete(string $id): void
    {
        if (!$this->validId($id)) return;
        @unlink($this->metaPath($id));
        $dir = $this->imageDir($id);
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
        @rmdir($dir);
        @unlink($this->lockPath($id));
    }

    private function ensureDir(): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) return false;
        $rules = $this->dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($rules)) @file_put_contents($rules, "Options -Indexes\n\nOrder allow,deny\nDeny from all\nRequire all denied\n", LOCK_EX);
        if (!is_file($this->dir . DIRECTORY_SEPARATOR . '.gitkeep')) @file_put_contents($this->dir . DIRECTORY_SEPARATOR . '.gitkeep', '');
        return true;
    }

    private function validId(string $id): bool { return preg_match('/\A[a-f0-9]{48}\z/', $id) === 1; }
    private function safeImageName(string $name): bool { return preg_match('/\Atms-[a-f0-9]{16}\.(jpg|jpeg|png|gif|webp)\z/i', $name) === 1; }
    private function metaPath(string $id): string { return $this->dir . DIRECTORY_SEPARATOR . $id . '.json'; }
    private function imageDir(string $id): string { return $this->dir . DIRECTORY_SEPARATOR . $id . '-images'; }
    private function lockPath(string $id): string { return $this->dir . DIRECTORY_SEPARATOR . $id . '.lock'; }
}
