<?php

declare(strict_types=1);

namespace Tomos;

foreach ([
    'FrontMatterParser' => 'FrontMatterParser.php',
    'Security' => 'Security.php',
    'PostEditableMarkdown' => 'PostEditableMarkdown.php',
    'PostBasicPage' => 'PostBasicPage.php',
    'PostSubmissionPreparer' => 'PostSubmissionPreparer.php',
    'PostUploadInput' => 'PostUploadInput.php',
    'PostInboxPreview' => 'PostInboxPreview.php',
    'PostDrafts' => 'PostDrafts.php',
] as $dependency => $file) {
    if (!class_exists(__NAMESPACE__ . '\\' . $dependency)) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . $file;
    }
}

final class PostInboxItem
{
    public string $path;
    public string $fileName;
    public int $modifiedAt;
    public int $size;

    public function __construct(string $path, string $fileName, int $modifiedAt, int $size)
    {
        $this->path = $path;
        $this->fileName = $fileName;
        $this->modifiedAt = $modifiedAt;
        $this->size = $size;
    }
}

final class PostInboxReadResult
{
    public bool $ok;
    /** @var string[] */
    public array $errors;
    public string $content;
    public string $fileName;
    public string $path;

    /** @param string[] $errors */
    public function __construct(bool $ok, array $errors = [], string $content = '', string $fileName = '', string $path = '')
    {
        $this->ok = $ok;
        $this->errors = $errors;
        $this->content = $content;
        $this->fileName = $fileName;
        $this->path = $path;
    }
}

final class PostInboxReceiveResult
{
    public bool $ok;
    public int $status;
    public string $message;

    public function __construct(bool $ok, int $status, string $message)
    {
        $this->ok = $ok;
        $this->status = $status;
        $this->message = $message;
    }
}

final class PostInbox
{
    private string $inboxDir;
    private FrontMatterParser $frontMatterParser;
    private PostSubmissionPreparer $submissionPreparer;
    private string $error = '';

    public function __construct(array $config, string $rootDir)
    {
        $this->inboxDir = rtrim((string) (($config['paths']['inbox_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'inbox')), DIRECTORY_SEPARATOR);
        $this->frontMatterParser = new FrontMatterParser();
        $editableMarkdown = new PostEditableMarkdown($config, $rootDir);
        $this->submissionPreparer = new PostSubmissionPreparer($editableMarkdown);
        $this->ensureDirectory();
    }

    public function error(): string
    {
        return $this->error;
    }

    public function autoPublishLockPath(): string
    {
        return $this->inboxDir . DIRECTORY_SEPARATOR . '.auto-publish.lock';
    }

    /** @return PostInboxItem[] */
    public function list(): array
    {
        if (!$this->ensureDirectory()) {
            return [];
        }

        $items = [];
        $entries = @scandir($this->inboxDir);
        if (!is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess' || $entry === '.gitkeep') {
                continue;
            }
            $candidate = $this->inboxDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($candidate) || is_link($candidate) || !$this->isSupportedFileName($entry)) {
                continue;
            }
            $realPath = realpath($candidate);
            $inboxBase = realpath($this->inboxDir);
            if ($realPath === false || $inboxBase === false || !Security::isPathInside($realPath, $inboxBase)) {
                continue;
            }
            $items[] = new PostInboxItem(
                $entry,
                $entry,
                (int) (@filemtime($realPath) ?: 0),
                (int) (@filesize($realPath) ?: 0)
            );
        }

        usort($items, static fn (PostInboxItem $left, PostInboxItem $right): int => $right->modifiedAt <=> $left->modifiedAt ?: strcasecmp($left->fileName, $right->fileName));
        return $items;
    }

    public function read(string $relativePath): PostInboxReadResult
    {
        $path = $this->safePath($relativePath);
        if ($path === null) {
            return new PostInboxReadResult(false, ['受信箱のファイルパスが正しくありません。']);
        }
        $fileName = basename($relativePath);
        if (!$this->isSupportedFileName($fileName)) {
            return new PostInboxReadResult(false, ['Tomosで投稿できないファイル形式です。']);
        }
        if (!is_file($path) || !is_readable($path)) {
            return new PostInboxReadResult(false, ['受信箱のファイルが見つからないか、読み込めません。']);
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return new PostInboxReadResult(false, ['受信箱のMarkdownを読み込めません。']);
        }
        return new PostInboxReadResult(true, [], $content, $fileName, str_replace(DIRECTORY_SEPARATOR, '/', $relativePath));
    }

    public function delete(string $relativePath): bool
    {
        $path = $this->safePath($relativePath);
        return $path !== null && is_file($path) && @unlink($path);
    }

    public function contentForManualPublish(string $markdown): string
    {
        $parsed = $this->frontMatterParser->parse($markdown);
        if (empty($parsed['has_frontmatter']) || !is_array($parsed['metadata'] ?? null)) {
            return $markdown;
        }

        $metadata = $this->frontMatterParser->buildPageMetadata(
            $parsed['metadata'],
            (string) ($parsed['body'] ?? ''),
            'inbox.md'
        );
        if (empty($metadata['draft'])) {
            return $markdown;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        $closingPosition = strpos($normalized, "\n---", 4);
        if ($closingPosition === false) {
            return $markdown;
        }
        $frontMatter = substr($normalized, 4, $closingPosition - 4);
        $updatedFrontMatter = preg_replace_callback(
            '/^([ \t]*draft[ \t]*:)[ \t]*(.*)$/mi',
            static fn (array $matches): string => $matches[1] . ' false',
            $frontMatter,
            1
        );
        if (!is_string($updatedFrontMatter) || $updatedFrontMatter === $frontMatter) {
            return $markdown;
        }

        return "---\n" . $updatedFrontMatter . "\n---" . substr($normalized, $closingPosition + 4);
    }

    public function receive(string $fileName, string $content): PostInboxReceiveResult
    {
        if (!$this->ensureDirectory()) {
            return new PostInboxReceiveResult(false, 500, 'Tomos受信箱を利用できません。');
        }
        if ($fileName === '' || strpos($fileName, "\0") !== false || $fileName !== basename($fileName) || strpos($fileName, '/') !== false || strpos($fileName, '\\') !== false || strpos($fileName, ':') !== false) {
            return new PostInboxReceiveResult(false, 400, 'ファイル名が正しくありません。');
        }
        if (strlen($content) > PostUploadInput::maxBytes()) {
            return new PostInboxReceiveResult(false, 413, 'ファイルサイズが大きすぎます。初期版では1MBまでです。');
        }
        $prepared = $this->submissionPreparer->prepare($content, $fileName, '', '');
        if (!$prepared->ok) {
            return new PostInboxReceiveResult(false, 400, (string) ($prepared->errors[0] ?? 'Markdownを受信できません。'));
        }

        $target = $this->inboxDir . DIRECTORY_SEPARATOR . $fileName;
        if (is_link($target) || file_exists($target)) {
            return new PostInboxReceiveResult(false, 409, '同名ファイルがすでに受信箱にあります。');
        }
        $inboxBase = realpath($this->inboxDir);
        if ($inboxBase === false || realpath(dirname($target)) !== $inboxBase) {
            return new PostInboxReceiveResult(false, 400, 'ファイル名が正しくありません。');
        }
        $handle = @fopen($target, 'x');
        if ($handle === false) {
            return new PostInboxReceiveResult(false, file_exists($target) ? 409 : 500, file_exists($target) ? '同名ファイルがすでに受信箱にあります。' : 'Tomos受信箱へ保存できません。');
        }
        $written = @fwrite($handle, $content);
        @fclose($handle);
        if ($written !== strlen($content)) {
            @unlink($target);
            return new PostInboxReceiveResult(false, 500, 'Tomos受信箱へ保存できません。');
        }
        return new PostInboxReceiveResult(true, 201, 'Tomos Inboxへ送信しました。');
    }

    public function folderFromMarkdown(string $markdown): string
    {
        $parsed = $this->frontMatterParser->parse($markdown);
        if (empty($parsed['has_frontmatter']) || !is_array($parsed['metadata'] ?? null)) {
            return '';
        }
        $folder = $parsed['metadata']['folder'] ?? '';
        if (!is_string($folder)) {
            return '';
        }
        $folder = trim($folder);
        if (
            strlen($folder) >= 2
            && (($folder[0] === '"' && substr($folder, -1) === '"') || ($folder[0] === "'" && substr($folder, -1) === "'"))
        ) {
            $folder = substr($folder, 1, -1);
        }
        return trim($folder);
    }

    public function isDraft(string $markdown, string $contentPath = 'inbox.md'): bool
    {
        $parsed = $this->frontMatterParser->parse($markdown);
        $metadata = is_array($parsed['metadata'] ?? null) ? $parsed['metadata'] : [];
        $normalized = $this->frontMatterParser->buildPageMetadata(
            $metadata,
            (string) ($parsed['body'] ?? $markdown),
            $contentPath
        );
        return !empty($normalized['draft']);
    }

    private function ensureDirectory(): bool
    {
        if (!is_dir($this->inboxDir) && !@mkdir($this->inboxDir, 0775, true) && !is_dir($this->inboxDir)) {
            $this->error = '受信箱フォルダを作成できませんでした。storage/inbox/ の権限を確認してください。';
            return false;
        }
        $this->error = '';
        $htaccess = $this->inboxDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Options -Indexes\n\nOrder allow,deny\nDeny from all\nRequire all denied\n", LOCK_EX);
        }
        $gitkeep = $this->inboxDir . DIRECTORY_SEPARATOR . '.gitkeep';
        if (!is_file($gitkeep)) {
            @file_put_contents($gitkeep, '');
        }
        return true;
    }

    private function safePath(string $relativePath): ?string
    {
        if (!Security::isSafeRelativePath($relativePath) || strpos($relativePath, '/') !== false) {
            return null;
        }
        if (!$this->ensureDirectory()) {
            return null;
        }
        $candidate = $this->inboxDir . DIRECTORY_SEPARATOR . $relativePath;
        if (is_link($candidate)) {
            return null;
        }
        $inboxBase = realpath($this->inboxDir);
        $realPath = realpath($candidate);
        if ($inboxBase === false || $realPath === false || !Security::isPathInside($realPath, $inboxBase)) {
            return null;
        }
        return $realPath;
    }

    private function isSupportedFileName(string $fileName): bool
    {
        $errors = [];
        $this->submissionPreparer->normalizeFileName($fileName, $errors);
        return $errors === [];
    }
}
