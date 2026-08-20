<?php

declare(strict_types=1);

namespace Tomos;

final class HtmlCache
{
    private const CACHE_VERSION = '8';

    private string $htmlDir;
    private bool $enabled;

    public function __construct(string $cacheDir, bool $enabled)
    {
        $this->htmlDir = rtrim($cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'html';
        $this->enabled = $enabled;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function makeKey(string $sourcePath): string
    {
        return sha1(str_replace('\\', '/', $sourcePath));
    }

    public function getPath(string $sourcePath): string
    {
        return $this->htmlDir . DIRECTORY_SEPARATOR . $this->makeKey($sourcePath) . '.html';
    }

    public function isFresh(string $sourcePath, string $sourceFile): bool
    {
        if (!$this->enabled || !is_file($sourceFile)) {
            return false;
        }

        $htmlPath = $this->getPath($sourcePath);
        $metaPath = $this->getMetaPath($sourcePath);
        if (!is_file($htmlPath) || !is_file($metaPath)) {
            return false;
        }

        $metaRaw = @file_get_contents($metaPath);
        if ($metaRaw === false) {
            return false;
        }

        $meta = json_decode($metaRaw, true);
        if (!is_array($meta)) {
            return false;
        }

        $mtime = @filemtime($sourceFile);
        $size = @filesize($sourceFile);
        if ($mtime === false || $size === false) {
            return false;
        }

        $sourceHash = @hash_file('sha256', $sourceFile);
        if (!is_string($sourceHash) || $sourceHash === '') {
            return false;
        }

        $expectedHtmlHash = (string) ($meta['html_sha256'] ?? '');
        $actualHtmlHash = @hash_file('sha256', $htmlPath);
        if ($expectedHtmlHash === '' || !is_string($actualHtmlHash) || !hash_equals($expectedHtmlHash, $actualHtmlHash)) {
            return false;
        }

        if (!hash_equals((string) ($meta['source_sha256'] ?? ''), $sourceHash)) {
            return false;
        }

        return ($meta['source_path'] ?? '') === $sourcePath
            && (int) ($meta['source_mtime'] ?? -1) === (int) $mtime
            && (int) ($meta['source_size'] ?? -1) === (int) $size
            && ($meta['cache_version'] ?? '') === self::CACHE_VERSION;
    }

    public function read(string $sourcePath, string $sourceFile): ?string
    {
        if (!$this->isFresh($sourcePath, $sourceFile)) {
            return null;
        }

        $html = @file_get_contents($this->getPath($sourcePath));
        return is_string($html) ? $html : null;
    }

    public function write(string $sourcePath, string $sourceFile, string $html): bool
    {
        if (!$this->enabled || !is_file($sourceFile)) {
            return false;
        }

        if (!is_dir($this->htmlDir) && !@mkdir($this->htmlDir, 0775, true) && !is_dir($this->htmlDir)) {
            return false;
        }

        $mtime = @filemtime($sourceFile);
        $size = @filesize($sourceFile);
        if ($mtime === false || $size === false) {
            return false;
        }

        $sourceHash = @hash_file('sha256', $sourceFile);
        if (!is_string($sourceHash) || $sourceHash === '') {
            return false;
        }

        $htmlPath = $this->getPath($sourcePath);
        $metaPath = $this->getMetaPath($sourcePath);
        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            return false;
        }
        $htmlTmp = $htmlPath . '.tmp-' . $suffix;
        $metaTmp = $metaPath . '.tmp-' . $suffix;
        $meta = [
            'source_path' => $sourcePath,
            'source_mtime' => (int) $mtime,
            'source_size' => (int) $size,
            'source_sha256' => $sourceHash,
            'cache_version' => self::CACHE_VERSION,
            'html_sha256' => hash('sha256', $html),
            'created_at' => date('c'),
        ];
        $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($metaJson === false) {
            return false;
        }

        if (@file_put_contents($htmlTmp, $html, LOCK_EX) === false) {
            return false;
        }

        if (@file_put_contents($metaTmp, $metaJson . "\n", LOCK_EX) === false) {
            @unlink($htmlTmp);
            return false;
        }

        if (!@rename($htmlTmp, $htmlPath) || !@rename($metaTmp, $metaPath)) {
            @unlink($htmlTmp);
            @unlink($metaTmp);
            return false;
        }

        return true;
    }

    public function delete(string $sourcePath): bool
    {
        $ok = true;
        foreach ([$this->getPath($sourcePath), $this->getMetaPath($sourcePath)] as $path) {
            if (is_file($path) && !@unlink($path)) {
                $ok = false;
            }
        }

        return $ok;
    }

    public function clearGenerated(): bool
    {
        if (!is_dir($this->htmlDir)) {
            return true;
        }

        $ok = true;
        $files = glob($this->htmlDir . DIRECTORY_SEPARATOR . '*.{html,json}', GLOB_BRACE);
        if ($files === false) {
            return false;
        }

        foreach ($files as $file) {
            if (is_file($file) && !@unlink($file)) {
                $ok = false;
            }
        }

        return $ok;
    }

    private function getMetaPath(string $sourcePath): string
    {
        return $this->htmlDir . DIRECTORY_SEPARATOR . $this->makeKey($sourcePath) . '.json';
    }
}
