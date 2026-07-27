<?php

declare(strict_types=1);

namespace Tomos;

final class MetadataIndex
{
    private string $contentDir;
    private string $cacheDir;
    private string $indexFile;
    private bool $includeDrafts;
    private FrontMatterParser $frontMatterParser;
    private PageRepository $pageRepository;
    private LinkAliasIndex $linkAliasIndex;

    public function __construct(
        string $contentDir,
        string $cacheDir,
        ?FrontMatterParser $frontMatterParser = null,
        bool $includeDrafts = false
    ) {
        $realContentDir = realpath($contentDir);
        if ($realContentDir === false || !is_dir($realContentDir)) {
            throw new \RuntimeException('Content directory does not exist.');
        }

        $this->contentDir = rtrim($realContentDir, DIRECTORY_SEPARATOR);
        $this->cacheDir = rtrim($cacheDir, DIRECTORY_SEPARATOR);
        $this->indexFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'pages.json';
        $this->includeDrafts = $includeDrafts;
        $this->frontMatterParser = $frontMatterParser ?? new FrontMatterParser();
        $this->pageRepository = new PageRepository($this->contentDir, $this->frontMatterParser);
        $this->linkAliasIndex = new LinkAliasIndex($this->cacheDir);
    }

    public function build(): array
    {
        $pages = [];

        foreach ($this->markdownFiles() as $filePath) {
            try {
                $page = $this->buildPageEntry($filePath);
            } catch (\Throwable $exception) {
                $page = null;
            }
            if ($page === null) {
                continue;
            }

            $pages[] = $page;
        }

        usort($pages, function (array $a, array $b): int {
            return strcmp($a['path'], $b['path']);
        });

        return $pages;
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

        $tmpFile = $this->indexFile . '.tmp';
        if (file_put_contents($tmpFile, $json . "\n", LOCK_EX) === false) {
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

            if (!$this->isIndexableRelativePath((string) $page['path'])) {
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

            $indexedPaths[$path] = true;
        }

        if ($this->hasMissingPublicContentFile($indexedPaths)) {
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

    private function buildPageEntry(string $filePath): ?array
    {
        if (!is_file($filePath) || is_link($filePath) || !Security::isPathInside($filePath, $this->contentDir)) {
            return null;
        }

        $relativePath = $this->relativePath($filePath);
        if (!$this->isIndexableRelativePath($relativePath)) {
            return null;
        }

        $markdown = @file_get_contents($filePath);
        if ($markdown === false) {
            return null;
        }

        $parsed = $this->frontMatterParser->parse($markdown);
        $metadata = $this->frontMatterParser->buildPageMetadata($parsed['metadata'], $parsed['body'], $relativePath);

        if ($metadata['draft'] && !$this->includeDrafts) {
            return null;
        }

        $mtime = @filemtime($filePath);
        $size = @filesize($filePath);
        if ($mtime === false || $size === false) {
            return null;
        }

        return [
            'path' => $relativePath,
            'url' => $this->pageRepository->urlFromContentPath($relativePath),
            'title' => $metadata['title'],
            'description' => $metadata['description'],
            'description_explicit' => $metadata['description_explicit'] ?? false,
            'date' => $metadata['date'],
            'updated' => $metadata['updated'],
            'tags' => $metadata['tags'],
            'excerpt' => $this->frontMatterParser->excerptFromMarkdown($parsed['body']),
            'search_text' => $this->searchText($metadata, $parsed['body']),
            'mtime' => $mtime,
            'size' => $size,
            'draft' => $metadata['draft'],
        ];
    }

    private function searchText(array $metadata, string $body): string
    {
        $text = preg_replace('/```.*?```/s', ' ', $body) ?? $body;
        $text = preg_replace('/!\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/u', ' $1 $2 ', $text) ?? $text;
        $text = preg_replace('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/u', ' $1 $2 ', $text) ?? $text;
        $text = preg_replace('/!\[([^\]]*)\]\([^)]+\)/u', ' $1 ', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', ' $1 ', $text) ?? $text;
        $text = preg_replace('/^#{1,6}\s*/m', ' ', $text) ?? $text;
        $text = preg_replace('/^\s*[-*+]\s+/m', ' ', $text) ?? $text;
        $text = preg_replace('/^\s*\d+\.\s+/m', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = implode(' ', [
            (string) ($metadata['title'] ?? ''),
            (string) ($metadata['description'] ?? ''),
            implode(' ', array_map('strval', is_array($metadata['tags'] ?? null) ? $metadata['tags'] : [])),
            $text,
        ]);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > 4000 ? mb_substr($text, 0, 4000, 'UTF-8') : $text;
        }

        return strlen($text) > 4000 ? substr($text, 0, 4000) : $text;
    }

    private function markdownFiles(): array
    {
        $files = [];
        $directories = [$this->contentDir];

        while ($directories !== []) {
            $directory = array_pop($directories);
            if (!is_string($directory) || $directory === '' || is_link($directory)) {
                continue;
            }

            $realDirectory = realpath($directory);
            if ($realDirectory === false || !is_dir($realDirectory)) {
                continue;
            }

            if ($realDirectory !== $this->contentDir && !Security::isPathInside($realDirectory, $this->contentDir)) {
                continue;
            }

            $items = @scandir($realDirectory);
            if ($items === false) {
                continue;
            }

            foreach ($items as $item) {
                if ($item === '' || $item === '.' || $item === '..' || $item[0] === '.') {
                    continue;
                }

                $path = $realDirectory . DIRECTORY_SEPARATOR . $item;
                if (is_link($path)) {
                    continue;
                }

                if (is_dir($path)) {
                    $directories[] = $path;
                    continue;
                }

                if (!is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'md') {
                    continue;
                }

                $realPath = realpath($path);
                if ($realPath === false || !Security::isPathInside($realPath, $this->contentDir)) {
                    continue;
                }

                $files[] = $realPath;
            }
        }

        sort($files);
        return $files;
    }

    private function hasMissingPublicContentFile(array $indexedPaths): bool
    {
        foreach ($this->markdownFiles() as $filePath) {
            $relativePath = $this->relativePath($filePath);
            if ($relativePath === null || isset($indexedPaths[$relativePath])) {
                continue;
            }

            if (!$this->isIndexableRelativePath($relativePath)) {
                continue;
            }

            $markdown = @file_get_contents($filePath);
            if ($markdown === false) {
                return true;
            }

            $parsed = $this->frontMatterParser->parse($markdown);
            $metadata = $this->frontMatterParser->buildPageMetadata($parsed['metadata'], $parsed['body'], $relativePath);
            if ($metadata['draft'] && !$this->includeDrafts) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function isIndexableRelativePath(?string $relativePath): bool
    {
        return $relativePath !== null
            && Security::isSafeRelativePath($relativePath)
            && Security::hasAllowedExtension($relativePath, ['md']);
    }

    private function relativePath(string $filePath): ?string
    {
        $realPath = realpath($filePath);
        if ($realPath === false || !Security::isPathInside($realPath, $this->contentDir)) {
            return null;
        }

        $relative = substr($realPath, strlen($this->contentDir) + 1);
        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }
}
