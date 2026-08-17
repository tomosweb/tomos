<?php

declare(strict_types=1);

namespace Tomos;

final class ImageReferenceIndex
{
    /** @var string[] */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private string $contentDir;
    private string $cacheDir;
    private string $indexFile;
    private bool $includeDrafts;
    private FrontMatterParser $frontMatterParser;

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
        $this->indexFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'image-references.json';
        $this->includeDrafts = $includeDrafts;
        $this->frontMatterParser = $frontMatterParser ?? new FrontMatterParser();
    }

    public function rebuild(): array
    {
        $index = $this->build();
        $this->save($index);

        return $index;
    }

    public function build(): array
    {
        $pages = [];
        $images = [];
        $external = [];

        foreach ($this->markdownFiles() as $filePath) {
            $entry = $this->buildPageEntry($filePath);
            if ($entry === null) {
                continue;
            }

            $pages[$entry['path']] = [
                'images' => $entry['images'],
                'external_images' => $entry['external_images'],
            ];

            foreach ($entry['images'] as $imagePath) {
                if (!isset($images[$imagePath])) {
                    $images[$imagePath] = [
                        'pages' => [],
                        'count' => 0,
                    ];
                }
                $images[$imagePath]['pages'][] = $entry['path'];
            }

            foreach ($entry['external_images'] as $target) {
                if (!isset($external[$target])) {
                    $external[$target] = [
                        'pages' => [],
                        'count' => 0,
                    ];
                }
                $external[$target]['pages'][] = $entry['path'];
            }
        }

        foreach ($images as $imagePath => $imageEntry) {
            $pagePaths = array_values(array_unique(array_map('strval', $imageEntry['pages'])));
            sort($pagePaths);
            $images[$imagePath] = [
                'pages' => $pagePaths,
                'count' => count($pagePaths),
            ];
        }

        foreach ($external as $target => $externalEntry) {
            $pagePaths = array_values(array_unique(array_map('strval', $externalEntry['pages'])));
            sort($pagePaths);
            $external[$target] = [
                'pages' => $pagePaths,
                'count' => count($pagePaths),
            ];
        }

        ksort($pages);
        ksort($images);
        ksort($external);

        return [
            'schema_version' => 1,
            'generated_at' => gmdate('c'),
            'pages' => $pages,
            'images' => $images,
            'external_images' => $external,
        ];
    }

    public function save(array $index): void
    {
        $this->validateIndex($index);

        $indexDir = dirname($this->indexFile);
        if (!is_dir($indexDir) && !mkdir($indexDir, 0775, true) && !is_dir($indexDir)) {
            throw new \RuntimeException('Image reference index directory could not be created.');
        }

        $json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('Image reference index could not be encoded.');
        }

        try {
            $tmpFile = $this->indexFile . '.tmp-' . bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Image reference index temporary file could not be prepared.');
        }
        if (file_put_contents($tmpFile, $json . "\n", LOCK_EX) === false) {
            @unlink($tmpFile);
            throw new \RuntimeException('Image reference index temporary file could not be written.');
        }

        if (!rename($tmpFile, $this->indexFile)) {
            @unlink($tmpFile);
            throw new \RuntimeException('Image reference index could not be saved.');
        }
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

    public function exists(): bool
    {
        return is_file($this->indexFile);
    }

    public function indexFile(): string
    {
        return $this->indexFile;
    }

    /**
     * @return string[]
     */
    public function managedReferencesFromMarkdown(string $markdown, string $contentPath): array
    {
        $parsed = $this->frontMatterParser->parse($markdown);
        $references = $this->extractReferences($parsed['body'], $contentPath);

        return $references['managed'];
    }

    /**
     * @param string[] $imagePaths
     * @return array{deleted:string[],kept:string[],failed:string[]}
     */
    public function deleteUnreferencedManagedImages(array $imagePaths, ?array $freshIndex = null): array
    {
        $imagePaths = array_values(array_unique(array_map('strval', $imagePaths)));
        sort($imagePaths);

        if ($freshIndex === null) {
            $freshIndex = $this->build();
        } else {
            $this->validateIndex($freshIndex);
        }
        $referencedImages = is_array($freshIndex['images'] ?? null) ? $freshIndex['images'] : [];

        $deleted = [];
        $kept = [];
        $failed = [];

        foreach ($imagePaths as $imagePath) {
            if (!$this->isManagedContentImagePath($imagePath)) {
                $kept[] = $imagePath;
                continue;
            }

            if (isset($referencedImages[$imagePath])) {
                $kept[] = $imagePath;
                continue;
            }

            $fullPath = $this->contentDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagePath);
            if (is_link($fullPath)) {
                $failed[] = $imagePath;
                continue;
            }

            $realPath = realpath($fullPath);
            if ($realPath === false || !is_file($realPath)) {
                $deleted[] = $imagePath;
                continue;
            }

            if (!Security::isPathInside($realPath, $this->contentDir)) {
                $failed[] = $imagePath;
                continue;
            }

            if (!@unlink($realPath)) {
                $failed[] = $imagePath;
                continue;
            }

            $deleted[] = $imagePath;
        }

        return [
            'deleted' => $deleted,
            'kept' => $kept,
            'failed' => $failed,
        ];
    }

    private function buildPageEntry(string $filePath): ?array
    {
        if (!is_file($filePath) || is_link($filePath) || !Security::isPathInside($filePath, $this->contentDir)) {
            return null;
        }

        $relativePath = $this->relativePath($filePath);
        if ($relativePath === null || !Security::isSafeRelativePath($relativePath) || !Security::hasAllowedExtension($relativePath, ['md'])) {
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

        $references = $this->extractReferences($parsed['body'], $relativePath);

        return [
            'path' => $relativePath,
            'images' => $references['managed'],
            'external_images' => $references['external'],
        ];
    }

    /**
     * @return array{managed:string[],external:string[]}
     */
    private function extractReferences(string $markdown, string $currentPagePath): array
    {
        $managed = [];
        $external = [];

        if (preg_match_all('/!\[[^\]\n]*\]\(([^)\s]+)\)/u', $markdown, $matches) >= 1) {
            foreach ($matches[1] as $target) {
                $this->classifyTarget((string) $target, $currentPagePath, $managed, $external);
            }
        }

        if (preg_match_all('/!\[\[([^\]\n]+)\]\]/u', $markdown, $matches) >= 1) {
            foreach ($matches[1] as $source) {
                $parts = explode('|', (string) $source, 2);
                $this->classifyTarget(trim($parts[0]), $currentPagePath, $managed, $external);
            }
        }

        $managed = array_values(array_unique($managed));
        $external = array_values(array_unique($external));
        sort($managed);
        sort($external);

        return [
            'managed' => $managed,
            'external' => $external,
        ];
    }

    /**
     * @param string[] $managed
     * @param string[] $external
     */
    private function classifyTarget(string $target, string $currentPagePath, array &$managed, array &$external): void
    {
        $target = trim($target);
        if ($target === '') {
            return;
        }

        if ($this->isExternalImageUrl($target)) {
            if (Security::safeHref($target) !== '#') {
                $external[] = $target;
            }
            return;
        }

        $resolved = $this->resolveManagedImagePath($target, $currentPagePath);
        if ($resolved !== null) {
            $managed[] = $resolved;
        }
    }

    private function resolveManagedImagePath(string $target, string $currentPagePath): ?string
    {
        if (!$this->isSafeLocalTarget($target)) {
            return null;
        }

        $extension = strtolower(pathinfo(parse_url($target, PHP_URL_PATH) ?: $target, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return null;
        }

        $isRootRelative = strpos($target, '/') === 0;
        if (strpos($target, '#') !== false || strpos($target, '?') !== false) {
            $path = parse_url($target, PHP_URL_PATH);
            $target = is_string($path) ? $path : $target;
        }
        $target = ltrim($target, '/');

        $segments = explode('/', $target);
        $fileName = strtolower((string) end($segments));
        $parent = count($segments) >= 2 ? $segments[count($segments) - 2] : '';
        if ($parent !== 'images' || preg_match('/\Atms-[a-f0-9]{16}\.(jpg|jpeg|png|gif|webp)\z/', $fileName) !== 1) {
            return null;
        }

        if (!$isRootRelative && strpos($target, 'images/') === 0) {
            $pageDirectory = dirname(str_replace('\\', '/', $currentPagePath));
            $prefix = ($pageDirectory === '.' || $pageDirectory === DIRECTORY_SEPARATOR) ? '' : trim($pageDirectory, '/') . '/';
            return $prefix . $target;
        }

        return $target;
    }

    private function isSafeLocalTarget(string $target): bool
    {
        if ($target === '' || strpos($target, "\0") !== false || preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            return false;
        }

        if (strpos($target, '\\') !== false || strpos($target, ':') !== false || strpos($target, '//') === 0) {
            return false;
        }

        $path = parse_url($target, PHP_URL_PATH);
        $path = is_string($path) ? $path : $target;
        $path = ltrim($path, '/');
        if ($path === '' || !Security::isSafeRelativePath($path)) {
            return false;
        }

        return true;
    }

    private function isManagedContentImagePath(string $imagePath): bool
    {
        if (!Security::isSafeRelativePath($imagePath)) {
            return false;
        }

        $parts = explode('/', $imagePath);
        if (count($parts) < 2 || $parts[count($parts) - 2] !== 'images') {
            return false;
        }

        $fileName = strtolower((string) end($parts));
        return preg_match('/\Atms-[a-f0-9]{16}\.(jpg|jpeg|png|gif|webp)\z/', $fileName) === 1;
    }

    private function isExternalImageUrl(string $target): bool
    {
        $scheme = parse_url($target, PHP_URL_SCHEME);
        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private function validateIndex(array $index): void
    {
        foreach (['schema_version', 'generated_at', 'pages', 'images', 'external_images'] as $key) {
            if (!array_key_exists($key, $index)) {
                throw new \RuntimeException('Image reference index is incomplete.');
            }
        }

        if (!is_array($index['pages']) || !is_array($index['images']) || !is_array($index['external_images'])) {
            throw new \RuntimeException('Image reference index has invalid sections.');
        }
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
