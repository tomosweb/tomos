<?php

declare(strict_types=1);

namespace Tomos;

final class ImageDeletionRetryQueue
{
    private string $queueFile;
    private ImageReferenceIndex $index;

    public function __construct(string $cacheDir, ImageReferenceIndex $index)
    {
        $this->queueFile = rtrim($cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'image-deletion-retries.json';
        $this->index = $index;
    }

    /** @param string[] $imagePaths @return string[] */
    public function attempt(array $imagePaths = [], ?array $freshIndex = null, bool $includeQueued = true): array
    {
        $queue = $this->load();
        $newPaths = array_values(array_unique(array_map('strval', $imagePaths)));
        foreach ($newPaths as $path) {
            $queue[$path] = $queue[$path] ?? ['attempts' => 0, 'last_attempt_at' => ''];
        }
        $paths = $includeQueued ? array_keys($queue) : $newPaths;
        if ($paths === []) {
            return [];
        }

        try {
            $result = $this->index->deleteUnreferencedManagedImages($paths, $freshIndex);
        } catch (\Throwable $exception) {
            foreach ($paths as $path) {
                $queue[$path]['attempts'] = (int) ($queue[$path]['attempts'] ?? 0) + 1;
                $queue[$path]['last_attempt_at'] = gmdate('c');
            }
            $saved = $this->save($queue);
            return [$saved
                ? '使われなくなった画像を確認できませんでした。次回の投稿時に再試行します。'
                : '使われなくなった画像を確認できず、再試行情報も保存できませんでした。cache/index/ の書き込み権限を確認してください。'];
        }

        $failed = array_fill_keys($result['failed'], true);
        $next = $includeQueued ? [] : array_diff_key($queue, array_fill_keys($paths, true));
        foreach ($failed as $path => $_) {
            $next[$path] = [
                'attempts' => (int) ($queue[$path]['attempts'] ?? 0) + 1,
                'last_attempt_at' => gmdate('c'),
            ];
        }
        $saved = $this->save($next);

        if ($failed === []) {
            return $saved ? [] : ['画像削除の再試行情報を整理できませんでした。cache/index/ の書き込み権限を確認してください。'];
        }
        return [$saved
            ? '使われなくなった画像を削除できませんでした。次回の投稿時に再試行します。'
            : '使われなくなった画像を削除できず、再試行情報も保存できませんでした。cache/index/ の書き込み権限を確認してください。'];
    }

    public function queueFile(): string
    {
        return $this->queueFile;
    }

    private function load(): array
    {
        $raw = @file_get_contents($this->queueFile);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded['images'] ?? null) ? $decoded['images'] : [];
    }

    private function save(array $images): bool
    {
        if ($images === []) {
            if (is_file($this->queueFile) && !@unlink($this->queueFile)) {
                return false;
            }
            @unlink($this->queueFile . '.tmp');
            return true;
        }
        $dir = dirname($this->queueFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        ksort($images);
        $json = json_encode(['schema_version' => 1, 'images' => $images], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($this->queueFile . '.tmp', $json . "\n", LOCK_EX) === false) {
            return false;
        }
        if (!@rename($this->queueFile . '.tmp', $this->queueFile)) {
            @unlink($this->queueFile . '.tmp');
            return false;
        }
        return true;
    }
}
