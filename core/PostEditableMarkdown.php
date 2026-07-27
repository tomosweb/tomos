<?php

declare(strict_types=1);

namespace Tomos;

final class PostEditableMarkdown
{
    private const HELPER_KEYS = [
        'tomos_asset_base_url',
        'tomos_source_path',
        'tomos_source_hash',
        'tomos_source_status',
    ];

    private string $contentDir;
    private string $cacheDir;
    private array $site;
    private FrontMatterParser $frontMatterParser;

    public function __construct(array $config, string $rootDir)
    {
        $contentDir = (string) (($config['paths']['content_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'content'));
        $realContentDir = realpath($contentDir);
        if ($realContentDir === false || !is_dir($realContentDir)) {
            throw new \RuntimeException('Content directory does not exist.');
        }

        $this->contentDir = rtrim($realContentDir, DIRECTORY_SEPARATOR);
        $this->cacheDir = (string) (($config['paths']['cache_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'cache'));
        $this->site = is_array($config['site'] ?? null) ? $config['site'] : [];
        $this->frontMatterParser = new FrontMatterParser();
    }

    public function search(string $query, int $page = 1, int $perPage = 30): array
    {
        $query = trim($query);
        if ($query === '') {
            return $this->searchResult([], '', 1, 0, $perPage);
        }

        if ($this->textLength($query) > 200) {
            return [
                'ok' => false,
                'error' => '検索語は200文字以内で入力してください。',
                'items' => [],
                'query' => $query,
                'page' => 1,
                'total' => 0,
                'total_pages' => 0,
            ];
        }

        try {
            $index = new MetadataIndex($this->contentDir, $this->cacheDir, $this->frontMatterParser, true);
            $entries = $index->loadFreshManagement();
            if ($entries === null) {
                $entries = $index->rebuildManagement();
            }
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'error' => '原稿の検索情報を準備できませんでした。Tomosのファイル構成を確認してください。',
                'items' => [],
                'query' => $query,
                'page' => 1,
                'total' => 0,
                'total_pages' => 0,
            ];
        }

        $matches = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $path = (string) ($entry['path'] ?? '');
            $internalUrl = (string) ($entry['url'] ?? '');
            $publicUrl = $this->publicUrl($internalUrl);
            $haystacks = [
                (string) ($entry['title'] ?? ''),
                $path,
                (string) ($entry['filename'] ?? ''),
                $internalUrl,
                $publicUrl,
            ];

            if (!$this->containsAny($haystacks, $query)) {
                continue;
            }

            $draft = !empty($entry['draft']);
            $fixed = in_array($path, ['index.md', 'about.md'], true);
            $matches[] = [
                'path' => $path,
                'title' => (string) ($entry['title'] ?? ''),
                'url' => $publicUrl,
                'draft' => $draft,
                'status' => $draft ? 'draft' : ($fixed ? 'fixed' : 'published'),
                'mtime' => (int) ($entry['mtime'] ?? 0),
                'filename' => (string) ($entry['filename'] ?? basename($path)),
            ];
        }

        return $this->searchResult($matches, $query, $page, count($matches), $perPage);
    }

    public function download(string $relativePath): array
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '' || !Security::isSafeRelativePath($relativePath)) {
            return $this->downloadError('原稿の保存先が正しくありません。');
        }
        if (!Security::hasAllowedExtension($relativePath, ['md'])) {
            return $this->downloadError('Markdownファイルではありません。');
        }

        $relativePath = str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $candidate = $this->contentDir . DIRECTORY_SEPARATOR . $relativePath;
        if ($this->hasSymlinkSegment($candidate)) {
            return $this->downloadError('この原稿は安全にダウンロードできません。');
        }

        $realPath = realpath($candidate);
        if (
            $realPath === false
            || !is_file($realPath)
            || !Security::isPathInside($realPath, $this->contentDir)
        ) {
            return $this->downloadError('原稿が見つかりません。削除されていないか確認してください。');
        }

        if (!is_readable($realPath)) {
            return $this->downloadError('原稿を読み込めません。Tomosのファイル構成を確認してください。');
        }

        $markdown = @file_get_contents($realPath);
        if ($markdown === false) {
            return $this->downloadError('原稿を読み込めません。Tomosのファイル構成を確認してください。');
        }

        if (strpos($markdown, "\0") !== false || preg_match('//u', $markdown) !== 1) {
            return $this->downloadError('Front Matterを安全に処理できませんでした。');
        }

        $sourcePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($realPath, strlen($this->contentDir) + 1));
        $parsed = $this->frontMatterParser->parse($markdown);
        $metadata = $this->frontMatterParser->buildPageMetadata($parsed['metadata'], $parsed['body'], $sourcePath);
        $helpers = [
            'tomos_asset_base_url' => $this->assetBaseUrl($sourcePath),
            'tomos_source_path' => $sourcePath,
            'tomos_source_hash' => hash('sha256', $markdown),
            'tomos_source_status' => !empty($metadata['draft']) ? 'draft' : 'published',
        ];

        $generated = $this->injectHelpers($markdown, $helpers);
        if ($generated === null) {
            return $this->downloadError('Front Matterを安全に処理できませんでした。');
        }

        return [
            'ok' => true,
            'error' => '',
            'content' => $generated,
            'download_name' => basename($sourcePath),
            'source_path' => $sourcePath,
            'source_hash' => $helpers['tomos_source_hash'],
        ];
    }

    private function searchResult(array $matches, string $query, int $page, int $total, int $perPage): array
    {
        $perPage = max(1, min(30, $perPage));
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;
        $page = $totalPages > 0 ? max(1, min($page, $totalPages)) : 1;

        return [
            'ok' => true,
            'error' => '',
            'items' => array_slice($matches, ($page - 1) * $perPage, $perPage),
            'query' => $query,
            'page' => $page,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    private function publicUrl(string $internalUrl): string
    {
        $siteUrl = (string) ($this->site['url'] ?? '');
        $sitePath = parse_url($siteUrl, PHP_URL_PATH);
        $publicBasePath = (string) (($this->site['public_base_path'] ?? '') ?: ($this->site['base_path'] ?? ''));
        $absolutePath = is_string($sitePath) && trim($sitePath, '/') !== ''
            ? $internalUrl
            : Security::publicUrl($internalUrl, $publicBasePath);
        $absolute = Security::absoluteUrl($siteUrl, $absolutePath);
        if ($absolute !== '') {
            return $absolute;
        }

        return Security::publicUrl($internalUrl, $publicBasePath);
    }

    private function assetBaseUrl(string $sourcePath): string
    {
        $directory = str_replace('\\', '/', dirname($sourcePath));
        $internalUrl = $directory === '.' ? '/' : '/' . trim($directory, '/') . '/';

        return $this->publicUrl($internalUrl);
    }

    private function containsAny(array $haystacks, string $needle): bool
    {
        foreach ($haystacks as $haystack) {
            if (function_exists('mb_stripos')) {
                if (mb_stripos((string) $haystack, $needle, 0, 'UTF-8') !== false) {
                    return true;
                }
            } elseif (stripos((string) $haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function hasSymlinkSegment(string $candidate): bool
    {
        $relative = substr($candidate, strlen($this->contentDir) + 1);
        if ($relative === false || $relative === '') {
            return true;
        }

        $current = $this->contentDir;
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                return true;
            }
        }

        return false;
    }

    private function injectHelpers(string $markdown, array $helpers): ?string
    {
        $lineEnding = $this->lineEnding($markdown);
        if (preg_match('/\A---(\r\n|\n|\r)/', $markdown, $opening) === 1) {
            $frontStart = strlen($opening[0]);
            if (
                preg_match('/(\r\n|\n|\r)---[ \t]*(?=(?:\r\n|\n|\r)|\z)/', $markdown, $closing, PREG_OFFSET_CAPTURE, $frontStart) !== 1
            ) {
                return null;
            }

            $closingOffset = (int) $closing[0][1];
            $frontMatter = substr($markdown, $frontStart, $closingOffset - $frontStart);
            $updated = $this->replaceHelperLines($frontMatter, $helpers, $lineEnding);

            return substr($markdown, 0, $frontStart) . $updated . substr($markdown, $closingOffset);
        }

        if (preg_match('/\A---(?:\r\n|\n|\r|\z)/', $markdown) === 1) {
            return null;
        }

        $lines = [];
        foreach (self::HELPER_KEYS as $key) {
            $lines[] = $key . ': ' . $this->yamlString((string) $helpers[$key]);
        }

        return '---' . $lineEnding
            . implode($lineEnding, $lines) . $lineEnding
            . '---' . $lineEnding
            . $markdown;
    }

    private function replaceHelperLines(string $frontMatter, array $helpers, string $lineEnding): string
    {
        $parts = preg_split('/(\r\n|\n|\r)/', $frontMatter, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $frontMatter;
        }

        $output = '';
        $found = [];
        $skipIndented = false;
        $count = count($parts);
        for ($index = 0; $index < $count; $index += 2) {
            $line = (string) $parts[$index];
            $ending = $index + 1 < $count ? (string) $parts[$index + 1] : '';

            if ($skipIndented && preg_match('/^[ \t]+/', $line) === 1) {
                continue;
            }
            $skipIndented = false;

            if (preg_match('/^([A-Za-z0-9_-]+)[ \t]*:/', $line, $match) === 1 && in_array($match[1], self::HELPER_KEYS, true)) {
                $key = $match[1];
                if (!isset($found[$key])) {
                    $output .= $key . ': ' . $this->yamlString((string) $helpers[$key]) . $ending;
                    $found[$key] = true;
                }
                $skipIndented = true;
                continue;
            }

            $output .= $line . $ending;
        }

        $missing = [];
        foreach (self::HELPER_KEYS as $key) {
            if (!isset($found[$key])) {
                $missing[] = $key . ': ' . $this->yamlString((string) $helpers[$key]);
            }
        }

        if ($missing === []) {
            return $output;
        }

        if ($output !== '' && !$this->endsWithLineEnding($output)) {
            $output .= $lineEnding;
        }

        return $output . implode($lineEnding, $missing);
    }

    private function yamlString(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '""';
    }

    private function lineEnding(string $markdown): string
    {
        if (strpos($markdown, "\r\n") !== false) {
            return "\r\n";
        }
        if (strpos($markdown, "\n") !== false) {
            return "\n";
        }
        if (strpos($markdown, "\r") !== false) {
            return "\r";
        }

        return "\n";
    }

    private function endsWithLineEnding(string $value): bool
    {
        return preg_match('/(?:\r\n|\n|\r)\z/', $value) === 1;
    }

    private function downloadError(string $message): array
    {
        return [
            'ok' => false,
            'error' => $message,
            'content' => '',
            'download_name' => '',
            'source_path' => '',
            'source_hash' => '',
        ];
    }
}
