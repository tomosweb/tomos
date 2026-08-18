<?php

declare(strict_types=1);

namespace Tomos;

final class KnownPathMetadataUpdater
{
    private string $contentDir;
    private string $cacheDir;
    private FrontMatterParser $frontMatterParser;
    private bool $includeDrafts;

    public function __construct(
        string $contentDir,
        string $cacheDir,
        FrontMatterParser $frontMatterParser,
        bool $includeDrafts
    ) {
        $realContentDir = realpath($contentDir);
        if ($realContentDir === false || !is_dir($realContentDir)) {
            throw new \RuntimeException('Content directory does not exist.');
        }

        $this->contentDir = rtrim($realContentDir, DIRECTORY_SEPARATOR);
        $this->cacheDir = rtrim($cacheDir, DIRECTORY_SEPARATOR);
        $this->frontMatterParser = $frontMatterParser;
        $this->includeDrafts = $includeDrafts;
    }

    public function update(string $contentPath): bool
    {
        $contentPath = trim(str_replace('\\', '/', $contentPath));
        if (!Security::isSafeRelativePath($contentPath) || !Security::hasAllowedExtension($contentPath, ['md'])) {
            return false;
        }

        $index = new MetadataIndex(
            $this->contentDir,
            $this->cacheDir,
            $this->frontMatterParser,
            $this->includeDrafts
        );
        if (!$index->exists()) {
            return false;
        }

        $pages = $index->load();
        if (!$this->isUsableBase($pages)) {
            return false;
        }

        $oldPage = null;
        foreach ($pages as $page) {
            if (is_array($page) && (string) ($page['path'] ?? '') === $contentPath) {
                $oldPage = $page;
                break;
            }
        }

        $targetEntry = $this->buildTargetEntry($contentPath);
        if ($targetEntry === false) {
            return false;
        }

        $updated = [];
        foreach ($pages as $page) {
            if ((string) ($page['path'] ?? '') === $contentPath) {
                continue;
            }
            $updated[] = $page;
        }
        if (is_array($targetEntry)) {
            $updated[] = $targetEntry;
        }
        $updated = PageSorter::sort($updated);

        if (!$this->savePagesOnly($updated)) {
            return false;
        }

        try {
            $aliases = new LinkAliasIndex($this->cacheDir);
            if (!$aliases->updateKnownPage(
                $pages,
                $updated,
                $oldPage,
                is_array($targetEntry) ? $targetEntry : null
            )) {
                return false;
            }
            (new HtmlCache($this->cacheDir, true))->clearGenerated();
        } catch (\Throwable $exception) {
            return false;
        }

        return true;
    }

    private function isUsableBase(array $pages): bool
    {
        foreach ($pages as $page) {
            if (
                !is_array($page)
                || empty($page['path'])
                || !array_key_exists('search_text', $page)
                || !Security::isSafeRelativePath((string) $page['path'])
                || !Security::hasAllowedExtension((string) $page['path'], ['md'])
            ) {
                return false;
            }
        }

        return true;
    }

    private function savePagesOnly(array $pages): bool
    {
        $file = $this->cacheDir . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'pages.json';
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $json = json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return false;
        }

        try {
            $tmp = $file . '.tmp-' . bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            return false;
        }

        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /** @return array<string,mixed>|null|false */
    private function buildTargetEntry(string $contentPath)
    {
        $target = $this->contentDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $contentPath);
        if (!file_exists($target)) {
            return null;
        }
        if (!is_file($target) || is_link($target)) {
            return false;
        }

        $realTarget = realpath($target);
        if ($realTarget === false || !Security::isPathInside($realTarget, $this->contentDir)) {
            return false;
        }

        $tmpRoot = $this->temporaryRoot();
        if ($tmpRoot === null) {
            return false;
        }

        $tmpContent = $tmpRoot . DIRECTORY_SEPARATOR . 'content';
        $tmpCache = $tmpRoot . DIRECTORY_SEPARATOR . 'cache';
        $tmpTarget = $tmpContent . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $contentPath);

        try {
            if (!is_dir(dirname($tmpTarget)) && !@mkdir(dirname($tmpTarget), 0775, true) && !is_dir(dirname($tmpTarget))) {
                return false;
            }
            if (!@copy($realTarget, $tmpTarget)) {
                return false;
            }

            $mtime = @filemtime($realTarget);
            if ($mtime !== false) {
                @touch($tmpTarget, $mtime);
            }

            $single = new MetadataIndex(
                $tmpContent,
                $tmpCache,
                $this->frontMatterParser,
                $this->includeDrafts
            );
            $entries = $single->build();
            if ($entries === []) {
                return null;
            }
            if (count($entries) !== 1 || !is_array($entries[0]) || (string) ($entries[0]['path'] ?? '') !== $contentPath) {
                return false;
            }

            return $entries[0];
        } finally {
            $this->removeTree($tmpRoot);
        }
    }

    private function temporaryRoot(): ?string
    {
        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            return null;
        }

        $base = $this->cacheDir . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) {
            return null;
        }

        $root = $base . DIRECTORY_SEPARATOR . 'known-path-metadata-' . $suffix;
        if (!@mkdir($root, 0775, true)) {
            return null;
        }

        return $root;
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }

        $items = @scandir($path);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $this->removeTree($path . DIRECTORY_SEPARATOR . $item);
            }
        }
        @rmdir($path);
    }
}
