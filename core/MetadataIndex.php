<?php

declare(strict_types=1);

namespace Tomos;

final class MetadataIndex
{
    private string $contentDir;
    private string $cacheDir;
    private string $indexFile;
    private string $managementIndexFile;
    private FrontMatterParser $frontMatterParser;
    private PageCatalogBuilder $catalogBuilder;
    private LinkAliasIndex $linkAliasIndex;

    public function __construct(
        string $contentDir,
        string $cacheDir,
        ?FrontMatterParser $frontMatterParser = null,
        bool $includeDrafts = false,
        string $defaultLanguage = 'ja'
    ) {
        $realContentDir = realpath($contentDir);
        if ($realContentDir === false || !is_dir($realContentDir)) {
            throw new \RuntimeException('Content directory does not exist.');
        }

        $this->contentDir = rtrim($realContentDir, DIRECTORY_SEPARATOR);
        $this->cacheDir = rtrim($cacheDir, DIRECTORY_SEPARATOR);
        $this->indexFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'pages.json';
        $this->managementIndexFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'post-articles.json';
        $this->frontMatterParser = $frontMatterParser ?? new FrontMatterParser();
        $this->catalogBuilder = new PageCatalogBuilder(
            $this->contentDir,
            $this->frontMatterParser,
            $includeDrafts,
            $defaultLanguage
        );
        $this->linkAliasIndex = new LinkAliasIndex($this->cacheDir);
    }

    public function build(): array
    {
        return $this->catalogBuilder->build();
    }

    public function save(array $pages): void
    {
        $indexDir = dirname($this->indexFile);
        if (!is_dir($indexDir) && !mkdir($indexDir, 0775, true) && !is_dir($indexDir)) {
            throw new \RuntimeException('Metadata index directory could not be created.');
        }

        $json = json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Metadata index could not be encoded.');
        }

        $tmpFile = $this->randomTemporaryPath($this->indexFile);
        if (file_put_contents($tmpFile, $json . "\n", LOCK_EX) === false) {
            @unlink($tmpFile);
            throw new \RuntimeException('Metadata index temporary file could not be written.');
        }

        if (!rename($tmpFile, $this->indexFile)) {
            @unlink($tmpFile);
            throw new \RuntimeException('Metadata index could not be saved.');
        }

        $this->linkAliasIndex->save($this->linkAliasIndex->build($pages));
        (new HtmlCache($this->cacheDir, true))->clearGenerated();
    }

    public function rebuild(): array
    {
        $pages = $this->build();
        $this->save($pages);

        return $pages;
    }

    public function buildManagement(): array
    {
        $entries = [];
        foreach ($this->build() as $page) {
            $path = (string) ($page['path'] ?? '');
            $entries[] = [
                'path' => $path,
                'title' => (string) ($page['title'] ?? ''),
                'url' => (string) ($page['url'] ?? ''),
                'draft' => !empty($page['draft']),
                'mtime' => (int) ($page['mtime'] ?? 0),
                'filename' => basename($path),
            ];
        }

        return $entries;
    }

    public function saveManagement(array $entries): void
    {
        $this->saveJson($this->managementIndexFile, $entries);
    }

    public function rebuildManagement(): array
    {
        $entries = $this->buildManagement();
        $this->saveManagement($entries);

        return $entries;
    }

    public function loadFreshManagement(): ?array
    {
        if (!is_file($this->managementIndexFile)) {
            return null;
        }

        $json = @file_get_contents($this->managementIndexFile);
        if ($json === false) {
            return null;
        }

        $entries = json_decode($json, true);
        if (!is_array($entries)) {
            return null;
        }

        $indexedPaths = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                return null;
            }

            $path = (string) ($entry['path'] ?? '');
            if (
                !$this->catalogBuilder->isIndexableRelativePath($path)
                || !array_key_exists('title', $entry)
                || !array_key_exists('url', $entry)
                || !array_key_exists('draft', $entry)
                || !array_key_exists('filename', $entry)
                || !is_bool($entry['draft'])
                || (string) $entry['filename'] !== basename($path)
                || (string) $entry['url'] !== $this->catalogBuilder->urlFromContentPath($path)
            ) {
                return null;
            }

            $fullPath = $this->contentDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (
                !is_file($fullPath)
                || is_link($fullPath)
                || $this->catalogBuilder->hasSymlinkSegment($path)
                || !Security::isPathInside($fullPath, $this->contentDir)
            ) {
                return null;
            }

            $mtime = @filemtime($fullPath);
            if ($mtime === false || (int) ($entry['mtime'] ?? -1) !== $mtime) {
                return null;
            }

            $indexedPaths[$path] = true;
        }

        foreach ($this->catalogBuilder->markdownFiles() as $filePath) {
            $path = $this->catalogBuilder->relativePath($filePath);
            if ($path !== null && $this->catalogBuilder->isIndexableRelativePath($path) && !isset($indexedPaths[$path])) {
                return null;
            }
        }

        return $entries;
    }

    public function managementIndexFile(): string
    {
        return $this->managementIndexFile;
    }

    public function load(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $json = @file_get_contents($this->indexFile);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function loadCached(): ?array
    {
        if (!$this->exists() || !$this->linkAliasIndex->exists()) {
            return null;
        }

        $json = @file_get_contents($this->indexFile);
        if ($json === false) {
            return null;
        }

        $pages = json_decode($json, true);
        if (!is_array($pages)) {
            return null;
        }

        foreach ($pages as $page) {
            if (!is_array($page) || empty($page['path']) || !array_key_exists('search_text', $page)) {
                return null;
            }

            if (!array_key_exists('language', $page)) {
                return null;
            }

            foreach (['image', 'page_type', 'title_explicit'] as $requiredKey) {
                if (!array_key_exists($requiredKey, $page)) {
                    return null;
                }
            }

            if (LanguageTag::normalizeOrNull($page['language']) === null) {
                return null;
            }

            if (!$this->catalogBuilder->isIndexableRelativePath((string) $page['path'])) {
                return null;
            }
        }

        return $pages;
    }

    public function exists(): bool
    {
        return is_file($this->indexFile);
    }

    public function isFresh(): bool
    {
        return $this->loadFresh() !== null;
    }

    public function loadFresh(): ?array
    {
        $pages = $this->loadCached();
        if ($pages === null) {
            return null;
        }

        $indexedPaths = [];
        foreach ($pages as $page) {
            $path = (string) $page['path'];
            $fullPath = $this->contentDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (!is_file($fullPath) || is_link($fullPath)) {
                return null;
            }

            $mtime = @filemtime($fullPath);
            $size = @filesize($fullPath);
            if ($mtime === false || $size === false) {
                return null;
            }

            if ((int) ($page['mtime'] ?? -1) !== $mtime || (int) ($page['size'] ?? -1) !== $size) {
                return null;
            }

            $contentHash = @hash_file('sha256', $fullPath);
            if (!is_string($contentHash) || $contentHash === '' || !hash_equals((string) ($page['content_sha256'] ?? ''), $contentHash)) {
                return null;
            }

            $indexedPaths[$path] = true;
        }

        if ($this->catalogBuilder->hasMissingIncludedContentFile($indexedPaths)) {
            return null;
        }

        return $pages;
    }

    public function indexFile(): string
    {
        return $this->indexFile;
    }

    public function linkAliasIndexFile(): string
    {
        return $this->linkAliasIndex->indexFile();
    }

    private function saveJson(string $file, array $data): void
    {
        $indexDir = dirname($file);
        if (!is_dir($indexDir) && !mkdir($indexDir, 0775, true) && !is_dir($indexDir)) {
            throw new \RuntimeException('Metadata index directory could not be created.');
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Metadata index could not be encoded.');
        }

        $tmpFile = $this->randomTemporaryPath($file);
        if (file_put_contents($tmpFile, $json . "\n", LOCK_EX) === false) {
            @unlink($tmpFile);
            throw new \RuntimeException('Metadata index temporary file could not be written.');
        }

        if (!rename($tmpFile, $file)) {
            @unlink($tmpFile);
            throw new \RuntimeException('Metadata index could not be saved.');
        }
    }

    private function randomTemporaryPath(string $file): string
    {
        try {
            return $file . '.tmp-' . bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Metadata index temporary file could not be prepared.');
        }
    }

}
