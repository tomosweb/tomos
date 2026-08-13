<?php

declare(strict_types=1);

namespace Tomos;

foreach (['PostInbox' => 'PostInbox.php', 'MetadataIndex' => 'MetadataIndex.php'] as $dependency => $file) {
    if (!class_exists(__NAMESPACE__ . '\\' . $dependency)) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . $file;
    }
}

final class PostDraftItem
{
    public string $source;
    public string $path;
    public string $fileName;
    public string $title;
    public int $modifiedAt;
    public int $size;

    public function __construct(string $source, string $path, string $fileName, string $title, int $modifiedAt, int $size)
    {
        $this->source = $source;
        $this->path = $path;
        $this->fileName = $fileName;
        $this->title = $title;
        $this->modifiedAt = $modifiedAt;
        $this->size = $size;
    }
}

final class PostDrafts
{
    private array $config;
    private string $rootDir;
    private string $contentDir;
    private PostInbox $inbox;
    private FrontMatterParser $frontMatterParser;

    public function __construct(array $config, string $rootDir)
    {
        $this->config = $config;
        $this->rootDir = $rootDir;
        $this->contentDir = rtrim((string) (($config['paths']['content_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'content')), DIRECTORY_SEPARATOR);
        $this->inbox = new PostInbox($config, $rootDir);
        $this->frontMatterParser = new FrontMatterParser();
    }

    /** @return PostDraftItem[] */
    public function list(): array
    {
        $items = [];
        foreach ($this->inbox->list() as $item) {
            $read = $this->inbox->read($item->path);
            if (!$read->ok || !$this->isDraft($read->content, $item->fileName)) {
                continue;
            }
            $parsed = $this->frontMatterParser->parse($read->content);
            $metadata = $this->frontMatterParser->buildPageMetadata($parsed['metadata'], $parsed['body'], $item->fileName);
            $items[] = new PostDraftItem('inbox', $item->path, $item->fileName, (string) $metadata['title'], $item->modifiedAt, $item->size);
        }

        foreach ($this->postDraftEntries() as $entry) {
            $path = (string) ($entry['path'] ?? '');
            $fullPath = $this->safeContentPath($path);
            if ($fullPath === null) {
                continue;
            }
            $items[] = new PostDraftItem(
                'post',
                $path,
                basename($path),
                (string) ($entry['title'] ?? basename($path)),
                (int) (@filemtime($fullPath) ?: 0),
                (int) (@filesize($fullPath) ?: 0)
            );
        }

        usort($items, static fn (PostDraftItem $left, PostDraftItem $right): int => $right->modifiedAt <=> $left->modifiedAt ?: strcasecmp($left->fileName, $right->fileName));
        return $items;
    }

    public function read(string $source, string $path): PostInboxReadResult
    {
        if ($source === 'inbox') {
            $read = $this->inbox->read($path);
            if (!$read->ok || !$this->isDraft($read->content, $read->fileName)) {
                return new PostInboxReadResult(false, ['下書き原稿が見つかりません。']);
            }
            return $read;
        }
        if ($source !== 'post') {
            return new PostInboxReadResult(false, ['下書き原稿の保存先が正しくありません。']);
        }

        $realPath = $this->safeContentPath($path);
        if ($realPath === null || !is_readable($realPath)) {
            return new PostInboxReadResult(false, ['下書き原稿が見つかりません。']);
        }
        $content = @file_get_contents($realPath);
        if ($content === false || !$this->isDraft($content, $path)) {
            return new PostInboxReadResult(false, ['下書き原稿が見つかりません。']);
        }
        return new PostInboxReadResult(true, [], $content, basename($path), str_replace(DIRECTORY_SEPARATOR, '/', $path));
    }

    public function inbox(): PostInbox
    {
        return $this->inbox;
    }

    /** @return array{ok:bool,errors:string[]} */
    public function delete(string $source, string $path): array
    {
        if ($source === 'inbox') {
            $read = $this->read('inbox', $path);
            if (!$read->ok || !$this->inbox->delete($read->path)) {
                return ['ok' => false, 'errors' => ['Inbox下書きを削除できませんでした。']];
            }
            return ['ok' => true, 'errors' => []];
        }
        if ($source !== 'post') {
            return ['ok' => false, 'errors' => ['下書きの保存先が正しくありません。']];
        }
        $realPath = $this->safeContentPath($path);
        if ($realPath === null) {
            return ['ok' => false, 'errors' => ['下書き原稿が見つからないか、安全に削除できません。']];
        }
        $content = @file_get_contents($realPath);
        if ($content === false || !$this->isDraft($content, $path)) {
            return ['ok' => false, 'errors' => ['公開済み記事や対象外のファイルは下書き削除できません。']];
        }
        if (!@unlink($realPath)) {
            return ['ok' => false, 'errors' => ['Tomos Post下書きを削除できませんでした。']];
        }
        try {
            $index = new MetadataIndex($this->contentDir, (string) (($this->config['paths']['cache_dir'] ?? '') ?: ($this->rootDir . DIRECTORY_SEPARATOR . 'cache')), $this->frontMatterParser, true);
            $index->rebuildManagement();
        } catch (\Throwable $exception) {
            // The next management request can rebuild a stale management index.
        }
        return ['ok' => true, 'errors' => []];
    }

    private function postDraftEntries(): array
    {
        try {
            $index = new MetadataIndex(
                $this->contentDir,
                (string) (($this->config['paths']['cache_dir'] ?? '') ?: ($this->rootDir . DIRECTORY_SEPARATOR . 'cache')),
                $this->frontMatterParser,
                true
            );
            return array_values(array_filter($index->build(), static fn (array $entry): bool => !empty($entry['draft'])));
        } catch (\Throwable $exception) {
            return [];
        }
    }

    private function safeContentPath(string $path): ?string
    {
        if (!Security::isSafeRelativePath($path) || !Security::hasAllowedExtension($path, ['md'])) {
            return null;
        }
        $candidate = $this->contentDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $current = $this->contentDir;
        foreach (explode('/', $path) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                return null;
            }
        }
        $realBase = realpath($this->contentDir);
        $realPath = realpath($candidate);
        if ($realBase === false || $realPath === false || !is_file($realPath) || !Security::isPathInside($realPath, $realBase)) {
            return null;
        }
        return $realPath;
    }

    private function isDraft(string $markdown, string $path): bool
    {
        $parsed = $this->frontMatterParser->parse($markdown);
        $metadata = $this->frontMatterParser->buildPageMetadata($parsed['metadata'], $parsed['body'], $path);
        return !empty($metadata['draft']);
    }
}
