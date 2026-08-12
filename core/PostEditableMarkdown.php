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

    public function inspectReupload(string $markdown): array
    {
        $helpers = $this->extractHelpers($markdown);
        if (empty($helpers['found'])) {
            return [
                'ok' => true,
                'editable' => false,
                'error' => '',
                'markdown' => $markdown,
            ];
        }

        if (
            !empty($helpers['invalid'])
            || count((array) ($helpers['values'] ?? [])) !== count(self::HELPER_KEYS)
        ) {
            return $this->reuploadError(
                '編集元の情報が不足しているため、この原稿を更新できません。'
                . "\n"
                . 'Tomos Postから原稿をもう一度ダウンロードしてください。'
            );
        }

        $values = (array) $helpers['values'];
        $sourcePath = trim((string) ($values['tomos_source_path'] ?? ''));
        $sourceHash = strtolower(trim((string) ($values['tomos_source_hash'] ?? '')));
        $sourceStatus = strtolower(trim((string) ($values['tomos_source_status'] ?? '')));
        if (
            !$this->isSafeSourcePath($sourcePath)
            || preg_match('/\A[a-f0-9]{64}\z/', $sourceHash) !== 1
            || !in_array($sourceStatus, ['published', 'draft'], true)
        ) {
            return $this->reuploadError(
                '編集元の情報を確認できません。'
                . "\n"
                . '記事管理から原稿をもう一度ダウンロードしてください。'
            );
        }

        $cleaned = $this->removeHelpers($markdown);
        if ($cleaned === null) {
            return $this->reuploadError('Tomos連携用の情報を安全に取り除けませんでした。');
        }

        $source = $this->readSource($sourcePath);
        if (empty($source['ok'])) {
            return $this->reuploadError((string) ($source['error'] ?? '編集元の原稿を確認できませんでした。'));
        }

        return [
            'ok' => true,
            'editable' => true,
            'error' => '',
            'markdown' => $cleaned,
            'source_path' => $sourcePath,
            'source_hash' => $sourceHash,
            'source_status' => $sourceStatus,
            'source_exists' => !empty($source['exists']),
            'source_file' => (string) ($source['file'] ?? ''),
            'current_markdown' => (string) ($source['markdown'] ?? ''),
            'current_hash' => (string) ($source['hash'] ?? ''),
            'current_status' => (string) ($source['status'] ?? ''),
        ];
    }

    public function readSource(string $sourcePath): array
    {
        if (!$this->isSafeSourcePath($sourcePath)) {
            return [
                'ok' => false,
                'exists' => false,
                'error' => '編集元の情報を確認できません。記事管理から原稿をもう一度ダウンロードしてください。',
            ];
        }

        $candidate = $this->contentDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sourcePath);
        if ($this->hasSymlinkSegment($candidate)) {
            return [
                'ok' => false,
                'exists' => false,
                'error' => '編集元の原稿を安全に確認できませんでした。',
            ];
        }

        $realPath = realpath($candidate);
        if ($realPath === false) {
            return [
                'ok' => true,
                'exists' => false,
                'error' => '',
                'file' => '',
                'markdown' => '',
                'hash' => '',
                'status' => '',
            ];
        }
        if (
            !is_file($realPath)
            || !Security::isPathInside($realPath, $this->contentDir)
            || !is_readable($realPath)
        ) {
            return [
                'ok' => false,
                'exists' => false,
                'error' => '編集元の原稿を読み込めませんでした。',
            ];
        }

        $markdown = @file_get_contents($realPath);
        if ($markdown === false) {
            return [
                'ok' => false,
                'exists' => true,
                'error' => '編集元の原稿を読み込めませんでした。',
            ];
        }

        $parsed = $this->frontMatterParser->parse($markdown);
        $metadata = $this->frontMatterParser->buildPageMetadata($parsed['metadata'], $parsed['body'], $sourcePath);

        return [
            'ok' => true,
            'exists' => true,
            'error' => '',
            'file' => $realPath,
            'markdown' => $markdown,
            'hash' => hash('sha256', $markdown),
            'status' => !empty($metadata['draft']) ? 'draft' : 'published',
        ];
    }

    public function applyDraftState(string $markdown, bool $draft): ?string
    {
        $lineEnding = $this->lineEnding($markdown);
        if (preg_match('/\A---(\r\n|\n|\r)/', $markdown, $opening) !== 1) {
            if (preg_match('/\A---(?:\r\n|\n|\r|\z)/', $markdown) === 1) {
                return null;
            }
            if (!$draft) {
                return $markdown;
            }

            return '---' . $lineEnding
                . 'draft: true' . $lineEnding
                . '---' . $lineEnding
                . $markdown;
        }

        $frontStart = strlen($opening[0]);
        if (
            preg_match('/(\r\n|\n|\r)---[ \t]*(?=(?:\r\n|\n|\r)|\z)/', $markdown, $closing, PREG_OFFSET_CAPTURE, $frontStart) !== 1
        ) {
            return null;
        }

        $closingOffset = (int) $closing[0][1];
        $frontMatter = substr($markdown, $frontStart, $closingOffset - $frontStart);
        $parts = preg_split('/(\r\n|\n|\r)/', $frontMatter, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return null;
        }

        $output = '';
        $found = false;
        $skipIndented = false;
        $count = count($parts);
        for ($index = 0; $index < $count; $index += 2) {
            $line = (string) $parts[$index];
            $ending = $index + 1 < $count ? (string) $parts[$index + 1] : '';

            if ($skipIndented && preg_match('/^[ \t]+/', $line) === 1) {
                continue;
            }
            $skipIndented = false;

            if (preg_match('/^draft[ \t]*:/', $line) === 1) {
                if ($draft && !$found) {
                    $output .= 'draft: true' . $ending;
                    $found = true;
                }
                $skipIndented = true;
                continue;
            }

            $output .= $line . $ending;
        }

        if ($draft && !$found) {
            if ($output !== '' && !$this->endsWithLineEnding($output)) {
                $output .= $lineEnding;
            }
            $output .= 'draft: true';
        }

        if (!$draft && trim($output) === '') {
            return $this->removeEmptyFrontMatter($markdown, $closing);
        }

        return substr($markdown, 0, $frontStart) . $output . substr($markdown, $closingOffset);
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
        $internalUrl = $directory === '.'
            ? '/content/'
            : '/content/' . trim($directory, '/') . '/';

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

    private function isSafeSourcePath(string $sourcePath): bool
    {
        return $sourcePath !== ''
            && preg_match('//u', $sourcePath) === 1
            && Security::isSafeRelativePath($sourcePath)
            && Security::hasAllowedExtension($sourcePath, ['md'])
            && strpos($sourcePath, 'content/') !== 0;
    }

    private function extractHelpers(string $markdown): array
    {
        if (preg_match('/\A---(\r\n|\n|\r)/', $markdown, $opening) !== 1) {
            return ['found' => false, 'invalid' => false, 'values' => []];
        }

        $frontStart = strlen($opening[0]);
        if (
            preg_match('/(\r\n|\n|\r)---[ \t]*(?=(?:\r\n|\n|\r)|\z)/', $markdown, $closing, PREG_OFFSET_CAPTURE, $frontStart) !== 1
        ) {
            return ['found' => false, 'invalid' => false, 'values' => []];
        }

        $closingOffset = (int) $closing[0][1];
        $frontMatter = substr($markdown, $frontStart, $closingOffset - $frontStart);
        $lines = preg_split('/\r\n|\n|\r/', $frontMatter);
        if (!is_array($lines)) {
            return ['found' => true, 'invalid' => true, 'values' => []];
        }

        $values = [];
        $found = false;
        $invalid = false;
        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+)[ \t]*:[ \t]*(.*)$/', (string) $line, $match) !== 1) {
                continue;
            }
            $key = (string) $match[1];
            if (!in_array($key, self::HELPER_KEYS, true)) {
                continue;
            }

            $found = true;
            if (array_key_exists($key, $values)) {
                $invalid = true;
                continue;
            }

            $decoded = $this->decodeScalar((string) $match[2]);
            if ($decoded === null || $decoded === '') {
                $invalid = true;
                continue;
            }
            $values[$key] = $decoded;
        }

        return ['found' => $found, 'invalid' => $invalid, 'values' => $values];
    }

    private function decodeScalar(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || in_array($value, ['|', '>'], true)) {
            return null;
        }
        if ($value[0] === '"') {
            $decoded = json_decode($value, true);
            return is_string($decoded) ? $decoded : null;
        }
        if ($value[0] === "'") {
            if (substr($value, -1) !== "'") {
                return null;
            }
            return str_replace("''", "'", substr($value, 1, -1));
        }

        return $value;
    }

    private function removeHelpers(string $markdown): ?string
    {
        if (preg_match('/\A---(\r\n|\n|\r)/', $markdown, $opening) !== 1) {
            return $markdown;
        }

        $frontStart = strlen($opening[0]);
        if (
            preg_match('/(\r\n|\n|\r)---[ \t]*(?=(?:\r\n|\n|\r)|\z)/', $markdown, $closing, PREG_OFFSET_CAPTURE, $frontStart) !== 1
        ) {
            return null;
        }

        $closingOffset = (int) $closing[0][1];
        $frontMatter = substr($markdown, $frontStart, $closingOffset - $frontStart);
        $parts = preg_split('/(\r\n|\n|\r)/', $frontMatter, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return null;
        }

        $output = '';
        $skipIndented = false;
        $count = count($parts);
        for ($index = 0; $index < $count; $index += 2) {
            $line = (string) $parts[$index];
            $ending = $index + 1 < $count ? (string) $parts[$index + 1] : '';

            if ($skipIndented && preg_match('/^[ \t]+/', $line) === 1) {
                continue;
            }
            $skipIndented = false;

            if (
                preg_match('/^([A-Za-z0-9_-]+)[ \t]*:/', $line, $match) === 1
                && in_array((string) $match[1], self::HELPER_KEYS, true)
            ) {
                $skipIndented = true;
                continue;
            }

            $output .= $line . $ending;
        }

        if (trim($output) === '') {
            return $this->removeEmptyFrontMatter($markdown, $closing);
        }

        return substr($markdown, 0, $frontStart) . $output . substr($markdown, $closingOffset);
    }

    private function removeEmptyFrontMatter(string $markdown, array $closing): string
    {
        $after = (int) $closing[0][1] + strlen((string) $closing[0][0]);
        $body = substr($markdown, $after);
        if (strpos($body, "\r\n") === 0) {
            return substr($body, 2);
        }
        if (strpos($body, "\n") === 0 || strpos($body, "\r") === 0) {
            return substr($body, 1);
        }

        return $body;
    }

    private function reuploadError(string $message): array
    {
        return [
            'ok' => false,
            'editable' => true,
            'error' => $message,
            'markdown' => '',
        ];
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
