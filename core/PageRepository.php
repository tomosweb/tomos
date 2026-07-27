<?php

declare(strict_types=1);

namespace Tomos;

final class PageLookupResult
{
    public string $status;
    public ?array $page;

    public function __construct(string $status, ?array $page = null)
    {
        $this->status = $status;
        $this->page = $page;
    }
}

final class PageRepository
{
    private string $contentDir;
    private FrontMatterParser $frontMatterParser;

    public function __construct(string $contentDir, ?FrontMatterParser $frontMatterParser = null)
    {
        $realContentDir = realpath($contentDir);
        if ($realContentDir === false || !is_dir($realContentDir)) {
            throw new \RuntimeException('Content directory does not exist.');
        }

        $this->contentDir = rtrim($realContentDir, DIRECTORY_SEPARATOR);
        $this->frontMatterParser = $frontMatterParser ?? new FrontMatterParser();
    }

    public function findByRoute(Route $route): PageLookupResult
    {
        if (!$route->isValid) {
            return new PageLookupResult($route->reason);
        }

        foreach ($route->contentPathCandidates as $contentPath) {
            $candidate = $this->readCandidate($contentPath);
            if ($candidate->status === 'not_found') {
                continue;
            }

            return $candidate;
        }

        return new PageLookupResult('not_found');
    }

    public function virtualFolderForRoute(Route $route, array $pages): ?array
    {
        if (!$route->isValid || $route->urlPath === '/' || substr($route->urlPath, -1) !== '/') {
            return null;
        }

        $folder = trim($route->urlPath, '/');
        if ($folder === '' || !Security::isSafeRelativePath($folder)) {
            return null;
        }

        $prefix = $folder . '/';
        $indexPath = $prefix . 'index.md';
        $hasPublicChild = false;

        foreach ($pages as $page) {
            if (!is_array($page) || !empty($page['draft'])) {
                continue;
            }

            $path = (string) ($page['path'] ?? '');
            if ($path === $indexPath) {
                return null;
            }
            $relative = strpos($path, $prefix) === 0 ? substr($path, strlen($prefix)) : '';
            if ($relative !== '' && strpos($relative, '/') === false && substr($relative, -3) === '.md') {
                $hasPublicChild = true;
            }
        }

        if (!$hasPublicChild) {
            return null;
        }

        $segments = explode('/', $folder);
        $title = (string) end($segments);

        return [
            'page_type' => 'virtual_folder_index',
            'folder_path' => $folder,
            'path' => '',
            'url' => $route->urlPath,
            'title' => $title,
            'description' => '',
            'description_explicit' => true,
            'date' => '',
            'updated' => '',
            'tags' => [],
            'draft' => false,
            'content_raw' => '',
            'body' => '',
            'markdown' => '',
            'metadata' => [],
        ];
    }

    private function readCandidate(string $contentPath): PageLookupResult
    {
        if (!Security::isSafeRelativePath($contentPath)) {
            return new PageLookupResult('forbidden_path');
        }

        if (!Security::hasAllowedExtension($contentPath, ['md'])) {
            return new PageLookupResult('forbidden_extension');
        }

        $fullPath = $this->contentDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $contentPath);
        $realPath = realpath($fullPath);

        if ($realPath === false || !is_file($realPath) || is_link($realPath)) {
            return new PageLookupResult('not_found');
        }

        if (!Security::isPathInside($realPath, $this->contentDir)) {
            return new PageLookupResult('forbidden_path');
        }

        $markdown = @file_get_contents($realPath);
        if ($markdown === false) {
            return new PageLookupResult('not_found');
        }

        $parsed = $this->frontMatterParser->parse($markdown);
        $metadata = $this->frontMatterParser->buildPageMetadata($parsed['metadata'], $parsed['body'], $contentPath);
        if ($metadata['draft']) {
            return new PageLookupResult('draft');
        }

        return new PageLookupResult('ok', [
            'path' => $contentPath,
            'url' => $this->urlFromContentPath($contentPath),
            'title' => $metadata['title'],
            'description' => $metadata['description'],
            'description_explicit' => $metadata['description_explicit'] ?? false,
            'date' => $metadata['date'],
            'updated' => $metadata['updated'],
            'tags' => $metadata['tags'],
            'draft' => $metadata['draft'],
            'file' => $realPath,
            'content_raw' => $parsed['body'],
            'body' => $parsed['body'],
            'markdown' => $markdown,
            'metadata' => $metadata,
        ]);
    }

    public function urlFromContentPath(string $contentPath): string
    {
        if ($contentPath === 'index.md') {
            return '/';
        }

        if (substr($contentPath, -9) === '/index.md') {
            return '/' . substr($contentPath, 0, -8);
        }

        return '/' . substr($contentPath, 0, -3);
    }
}
