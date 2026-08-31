<?php

declare(strict_types=1);

namespace Tomos;

/**
 * Stores Publisher image uploads privately until the corresponding Markdown
 * has been completely received and accepted into the existing Inbox flow.
 */
final class PostInboxImageStore
{
    private const MAX_IMAGE_COUNT = 5;
    private const MAX_IMAGE_BYTES = 10485760;
    private const CHUNK_BYTES = 524288;
    private const TTL_SECONDS = 86400;
    /** @var array<string,string> */
    private const MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    private string $assetDir;
    private string $inboxDir;

    public function __construct(string $inboxDir)
    {
        $this->inboxDir = rtrim($inboxDir, DIRECTORY_SEPARATOR);
        $this->assetDir = $this->inboxDir . DIRECTORY_SEPARATOR . '.publisher-assets';
    }

    /** @param string[] $expectedImages @return array<string,mixed> */
    public function begin(string $fileName, string $content, array $expectedImages): array
    {
        $this->cleanupExpired();
        if (!$this->ensureDirectories()) {
            return $this->result(false, 500, '画像の受信準備を開始できませんでした。');
        }
        $expected = array_values(array_unique(array_map('strtolower', $expectedImages)));
        if ($expected === [] || count($expected) > self::MAX_IMAGE_COUNT) {
            return $this->result(false, 400, '画像は1点以上5点以下で送信してください。');
        }
        foreach ($expected as $name) {
            if (!$this->safeImageName($name)) {
                return $this->result(false, 400, '投稿する画像を確認できませんでした。');
            }
        }

        try {
            $uploadId = bin2hex(random_bytes(24));
        } catch (\Throwable $exception) {
            return $this->result(false, 500, '画像の受信準備を開始できませんでした。');
        }
        $dir = $this->pendingPath($uploadId);
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return $this->result(false, 500, '画像の受信準備を開始できませんでした。');
        }
        $record = [
            'file_name' => $fileName,
            'expected_images' => $expected,
            'received_images' => [],
            'created_at' => time(),
            'expires_at' => time() + self::TTL_SECONDS,
        ];
        if (!$this->writeRecord($dir . DIRECTORY_SEPARATOR . 'meta.json', $record)
            || @file_put_contents($dir . DIRECTORY_SEPARATOR . 'content.md', $content, LOCK_EX) !== strlen($content)
        ) {
            $this->deleteTree($dir);
            return $this->result(false, 500, '画像の受信準備を開始できませんでした。');
        }
        @chmod($dir . DIRECTORY_SEPARATOR . 'content.md', 0600);
        return $this->result(true, 201, '画像の受信準備を開始しました。', $uploadId);
    }

    /** @return array<string,mixed> */
    public function receiveChunk(string $uploadId, string $imageName, string $body, int $chunkIndex, int $chunkCount, int $totalSize): array
    {
        if (!$this->validUploadId($uploadId) || !$this->ensureDirectories()) {
            return $this->result(false, 404, '画像の受信準備が見つからないか、有効期限が切れました。');
        }
        $dir = $this->pendingPath($uploadId);
        $lock = @fopen($dir . DIRECTORY_SEPARATOR . '.lock', 'c');
        if ($lock === false || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) @fclose($lock);
            return $this->result(false, 500, '画像の受信状態を確認できませんでした。');
        }
        try {
            $record = $this->loadRecord($dir . DIRECTORY_SEPARATOR . 'meta.json');
            $imageName = strtolower($imageName);
            if ($record === null || !$this->safeImageName($imageName) || !in_array($imageName, (array) ($record['expected_images'] ?? []), true)) {
                return $this->result(false, 400, 'この画像は投稿対象として確認できませんでした。');
            }
            if ($chunkCount < 1 || $chunkIndex < 0 || $chunkIndex >= $chunkCount || $chunkCount > 100) {
                return $this->result(false, 400, '画像の送信順序を確認できませんでした。');
            }
            if (strlen($body) > self::CHUNK_BYTES || $totalSize <= 0 || $totalSize > self::MAX_IMAGE_BYTES || $body === '') {
                return $this->result(false, 413, '画像は10MB以下、512KB単位で送信してください。');
            }
            $state = is_array($record['chunks'][$imageName] ?? null) ? $record['chunks'][$imageName] : [
                'next_index' => 0,
                'chunk_count' => $chunkCount,
                'total_size' => $totalSize,
                'received_bytes' => 0,
            ];
            $next = (int) ($state['next_index'] ?? 0);
            if ($chunkIndex < $next) return $this->result(true, 200, '画像の受信済みチャンクです。', $uploadId);
            if ($chunkIndex !== $next || (int) ($state['chunk_count'] ?? 0) !== $chunkCount || (int) ($state['total_size'] ?? 0) !== $totalSize) {
                return $this->result(false, 400, '画像の送信順序を確認できませんでした。');
            }
            $part = $dir . DIRECTORY_SEPARATOR . $imageName . '.part';
            if ($chunkIndex === 0) @unlink($part);
            $written = @file_put_contents($part, $body, FILE_APPEND | LOCK_EX);
            if ($written !== strlen($body)) return $this->result(false, 500, '画像を一時保存できませんでした。');
            $state['next_index'] = $chunkIndex + 1;
            $state['received_bytes'] = (int) $state['received_bytes'] + strlen($body);
            $record['chunks'][$imageName] = $state;
            if ($chunkIndex + 1 === $chunkCount) {
                if ((int) $state['received_bytes'] !== $totalSize || (int) (@filesize($part) ?: 0) !== $totalSize) {
                    @unlink($part);
                    return $this->result(false, 400, '画像の受信サイズを確認できませんでした。');
                }
                $hash = hash_file('sha256', $part);
                $expectedHash = substr($imageName, 4, 16);
                if (!is_string($hash) || !hash_equals($expectedHash, substr($hash, 0, 16))) {
                    @unlink($part);
                    return $this->result(false, 400, 'Markdownと一致する画像を確認できませんでした。');
                }
                $extension = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));
                $info = @getimagesize($part);
                $mime = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';
                if (($this->mimeFor($extension) ?? '') !== $mime) {
                    @unlink($part);
                    return $this->result(false, 400, '画像の拡張子と画像データの形式が一致しません。');
                }
                if (!@rename($part, $dir . DIRECTORY_SEPARATOR . $imageName)) {
                    return $this->result(false, 500, '画像を一時保存できませんでした。');
                }
                $record['received_images'][$imageName] = true;
            }
            if (!$this->writeRecord($dir . DIRECTORY_SEPARATOR . 'meta.json', $record)) {
                return $this->result(false, 500, '画像の受信状態を保存できませんでした。');
            }
            return $this->result(true, 200, '画像を受信しました。', $uploadId);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /** @return array<string,mixed> */
    public function finalize(string $uploadId): array
    {
        if (!$this->validUploadId($uploadId)) return $this->result(false, 404, '画像の受信準備が見つかりません。');
        $dir = $this->pendingPath($uploadId);
        $record = $this->loadRecord($dir . DIRECTORY_SEPARATOR . 'meta.json');
        if ($record === null || (int) ($record['expires_at'] ?? 0) < time()) {
            $this->deleteTree($dir);
            return $this->result(false, 404, '画像の受信準備が見つからないか、有効期限が切れました。');
        }
        $expected = (array) ($record['expected_images'] ?? []);
        $received = array_keys(array_filter((array) ($record['received_images'] ?? [])));
        sort($expected); sort($received);
        if ($record === null || $expected !== $received) {
            $this->deleteTree($dir);
            return $this->result(false, 400, 'すべての画像を受信できていないため、投稿を確定できません。');
        }
        $itemDir = $this->itemPath($uploadId);
        if (!@rename($dir, $itemDir)) return $this->result(false, 500, '画像付き投稿を確定できませんでした。');
        return $this->result(true, 201, '画像付き投稿を確定しました。', $uploadId, (string) $record['file_name'], (string) @file_get_contents($itemDir . DIRECTORY_SEPARATOR . 'content.md'));
    }

    /** @return array<string,array<int,mixed>> */
    public function stagedFiles(string $fileName): array
    {
        $this->cleanupExpired();
        $items = [];
        $directories = glob($this->assetDir . DIRECTORY_SEPARATOR . 'items' . DIRECTORY_SEPARATOR . '*') ?: [];
        usort($directories, static fn (string $left, string $right): int => (@filemtime($right) ?: 0) <=> (@filemtime($left) ?: 0));
        foreach ($directories as $dir) {
            if (!is_dir($dir) || is_link($dir)) continue;
            $record = $this->loadRecord($dir . DIRECTORY_SEPARATOR . 'meta.json');
            if (!is_array($record) || (string) ($record['file_name'] ?? '') !== $fileName) continue;
            foreach ((array) ($record['received_images'] ?? []) as $imageName => $received) {
                if (!$received || !$this->safeImageName((string) $imageName)) continue;
                $path = $dir . DIRECTORY_SEPARATOR . $imageName;
                if (!is_file($path) || is_link($path)) continue;
                $items[] = ['name' => $imageName, 'type' => $this->mimeFor(pathinfo($imageName, PATHINFO_EXTENSION)) ?? '', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($path)];
            }
            if ($items !== []) break;
        }
        $byName = [];
        foreach ($items as $item) $byName[(string) $item['name']] = $item;
        $byName = array_values($byName);
        return [
            'name' => array_column($byName, 'name'),
            'type' => array_column($byName, 'type'),
            'tmp_name' => array_column($byName, 'tmp_name'),
            'error' => array_column($byName, 'error'),
            'size' => array_column($byName, 'size'),
        ];
    }

    public function deleteItem(string $uploadId): bool
    {
        if (!$this->validUploadId($uploadId)) return false;
        $dir = $this->itemPath($uploadId);
        return is_dir($dir) && $this->deleteTree($dir);
    }

    public function pruneForFile(string $fileName, string $keepUploadId): void
    {
        foreach (glob($this->assetDir . DIRECTORY_SEPARATOR . 'items' . DIRECTORY_SEPARATOR . '*') ?: [] as $dir) {
            $record = is_dir($dir) ? $this->loadRecord($dir . DIRECTORY_SEPARATOR . 'meta.json') : null;
            if (is_array($record) && (string) ($record['file_name'] ?? '') === $fileName && basename($dir) !== $keepUploadId) $this->deleteTree($dir);
        }
    }

    public function deleteForFile(string $fileName): void
    {
        foreach (glob($this->assetDir . DIRECTORY_SEPARATOR . 'items' . DIRECTORY_SEPARATOR . '*') ?: [] as $dir) {
            $record = is_dir($dir) ? $this->loadRecord($dir . DIRECTORY_SEPARATOR . 'meta.json') : null;
            if (is_array($record) && (string) ($record['file_name'] ?? '') === $fileName) $this->deleteTree($dir);
        }
    }

    public function cancel(string $uploadId): bool
    {
        if (!$this->validUploadId($uploadId)) return false;
        $dir = $this->pendingPath($uploadId);
        return is_dir($dir) && $this->deleteTree($dir);
    }

    private function ensureDirectories(): bool
    {
        return (is_dir($this->assetDir) || @mkdir($this->assetDir, 0700, true) || is_dir($this->assetDir))
            && (is_dir($this->assetDir . '/pending') || @mkdir($this->assetDir . '/pending', 0700, true) || is_dir($this->assetDir . '/pending'))
            && (is_dir($this->assetDir . '/items') || @mkdir($this->assetDir . '/items', 0700, true) || is_dir($this->assetDir . '/items'));
    }

    private function pendingPath(string $id): string { return $this->assetDir . DIRECTORY_SEPARATOR . 'pending' . DIRECTORY_SEPARATOR . $id; }
    private function itemPath(string $id): string { return $this->assetDir . DIRECTORY_SEPARATOR . 'items' . DIRECTORY_SEPARATOR . $id; }
    private function validUploadId(string $id): bool { return preg_match('/\A[a-f0-9]{48}\z/', $id) === 1; }
    private function safeImageName(string $name): bool { return preg_match('/\Atms-[a-f0-9]{16}\.(?:jpg|jpeg|png|gif|webp)\z/i', $name) === 1; }
    private function mimeFor(string $extension): ?string { return self::MIME_BY_EXTENSION[strtolower($extension)] ?? null; }

    /** @return array<string,mixed>|null */
    private function loadRecord(string $path): ?array
    {
        $raw = @file_get_contents($path);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($record) ? $record : null;
    }

    /** @param array<string,mixed> $record */
    private function writeRecord(string $path, array $record): bool
    {
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return false;
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !@rename($tmp, $path)) { @unlink($tmp); return false; }
        @chmod($path, 0600);
        return true;
    }

    private function cleanupExpired(): void
    {
        foreach (glob($this->assetDir . DIRECTORY_SEPARATOR . 'pending' . DIRECTORY_SEPARATOR . '*') ?: [] as $dir) {
            $record = is_dir($dir) ? $this->loadRecord($dir . DIRECTORY_SEPARATOR . 'meta.json') : null;
            if (!is_array($record) || (int) ($record['expires_at'] ?? 0) < time()) $this->deleteTree($dir);
        }
        foreach (glob($this->assetDir . DIRECTORY_SEPARATOR . 'items' . DIRECTORY_SEPARATOR . '*') ?: [] as $dir) {
            $record = is_dir($dir) ? $this->loadRecord($dir . DIRECTORY_SEPARATOR . 'meta.json') : null;
            $fileName = is_array($record) ? (string) ($record['file_name'] ?? '') : '';
            $source = $fileName !== '' ? $this->inboxDir . DIRECTORY_SEPARATOR . $fileName : '';
            if (!is_array($record) || $source === '' || !is_file($source) || is_link($source)) $this->deleteTree($dir);
        }
    }

    private function deleteTree(string $path): bool
    {
        if (!is_dir($path) || is_link($path)) return false;
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child) && !is_link($child)) $this->deleteTree($child); else @unlink($child);
        }
        return @rmdir($path);
    }

    /** @return array<string,mixed> */
    private function result(bool $ok, int $status, string $message, string $uploadId = '', string $fileName = '', string $content = ''): array
    {
        return ['ok' => $ok, 'status' => $status, 'message' => $message, 'upload_id' => $uploadId, 'file_name' => $fileName, 'content' => $content];
    }
}
