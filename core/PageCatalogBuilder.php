<?php

declare(strict_types=1);

namespace Tomos;

/**
 * Builds the logical public page catalog from Markdown content.
 *
 * Cache persistence and freshness storage intentionally live in MetadataIndex.
 * This class is shared publishing input logic that can also be reused by the
 * future GitHub edition build runtime.
 */
final class PageCatalogBuilder
{
    private string $contentDir;
    private bool $includeDrafts;
    private FrontMatterParser $frontMatterParser;
    private PageRepository $pageRepository;
    private string $defaultLanguage;

    public function __construct(
        string $contentDir,
        ?FrontMatterParser $frontMatterParser = null,
        bool $includeDrafts = false,
        string $defaultLanguage = 'ja'
    ) {
        $realContentDir = realpath($contentDir);
        if ($realContentDir === false || !is_dir($realContentDir)) {
            throw new \RuntimeException('Content directory does not exist.');
        }

        $this->contentDir = rtrim($realContentDir, DIRECTORY_SEPARATOR);
        $this->includeDrafts = $includeDrafts;
        $this->frontMatterParser = $frontMatterParser ?? new FrontMatterParser();
        $this->pageRepository = new PageRepository($this->contentDir, $this->frontMatterParser);
        $this->defaultLanguage = LanguageTag::fallback($defaultLanguage);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(): array
    {
        $pages = [];

        foreach ($this->markdownFiles() as $filePath) {
            try {
                $page = $this->buildPageEntry($filePath);
            } catch (\Throwable $exception) {
                $page = null;
            }

            if ($page !== null) {
                $pages[] = $page;
            }
        }

        return PageSorter::sort($pages);
    }

    /**
     * @return array<int, string>
     */
    public function markdownFiles(): array
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

    public function relativePath(string $filePath): ?string
    {
        $realPath = realpath($filePath);
        if ($realPath === false || !Security::isPathInside($realPath, $this->contentDir)) {
            return null;
        }

        $relative = substr($realPath, strlen($this->contentDir) + 1);

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    public function isIndexableRelativePath(?string $relativePath): bool
    {
        return $relativePath !== null
            && Security::isSafeRelativePath($relativePath)
            && Security::hasAllowedExtension($relativePath, ['md']);
    }

    public function hasSymlinkSegment(string $relativePath): bool
    {
        $current = $this->contentDir;
        foreach (explode('/', $relativePath) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                return true;
            }
        }

        return false;
    }

    public function urlFromContentPath(string $contentPath): string
    {
        return $this->pageRepository->urlFromContentPath($contentPath);
    }

    /**
     * Return true when the filesystem contains a page that should be in the
     * current catalog but is missing from the cached path set.
     *
     * @param array<string, bool> $indexedPaths
     */
    public function hasMissingIncludedContentFile(array $indexedPaths): bool
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
            $metadata = $this->frontMatterParser->buildPageMetadata(
                $parsed['metadata'],
                $parsed['body'],
                $relativePath
            );

            if ($metadata['draft'] && !$this->includeDrafts) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
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
        $metadata = $this->frontMatterParser->buildPageMetadata(
            $parsed['metadata'],
            $parsed['body'],
            $relativePath
        );

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
            'page_type' => $this->pageRepository->pageTypeFromContentPath($relativePath),
            'title' => $metadata['title'],
            'title_explicit' => $metadata['title_explicit'] ?? false,
            'description' => $metadata['description'],
            'description_explicit' => $metadata['description_explicit'] ?? false,
            'date' => $metadata['date'],
            'published' => $metadata['published'],
            'updated' => $metadata['updated'],
            'image' => $metadata['image'],
            'tags' => $metadata['tags'],
            'excerpt' => $this->frontMatterParser->excerptFromMarkdown($parsed['body']),
            'search_text' => $this->searchText($metadata, $parsed['body']),
            'mtime' => $mtime,
            'size' => $size,
            'content_sha256' => hash('sha256', $markdown),
            'draft' => $metadata['draft'],
            'language' => LanguageTag::fallback($metadata['language'], $this->defaultLanguage),
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
}
