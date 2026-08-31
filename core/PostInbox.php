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
    'PostInboxImageStore' => 'PostInboxImageStore.php',
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
    public string $uploadId;

    public function __construct(bool $ok, int $status, string $message, string $uploadId = '')
    {
        $this->ok = $ok;
        $this->status = $status;
        $this->message = $message;
        $this->uploadId = $uploadId;
    }
}

final class PostInbox
{
    private string $inboxDir;
    private FrontMatterParser $frontMatterParser;
    private PostSubmissionPreparer $submissionPreparer;
    private PostInboxImageStore $imageStore;
    private string $error = '';

    public function __construct(array $config, string $rootDir)
    {
        $this->inboxDir = rtrim((string) (($config['paths']['inbox_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'inbox')), DIRECTORY_SEPARATOR);
        $this->frontMatterParser = new FrontMatterParser();
        $editableMarkdown = new PostEditableMarkdown($config, $rootDir);
        $this->submissionPreparer = new PostSubmissionPreparer($editableMarkdown);
        $this->imageStore = new PostInboxImageStore($this->inboxDir);
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
        if ($path === null || !is_file($path) || !@unlink($path)) {
            return false;
        }
        $this->imageStore->deleteForFile(basename($relativePath));
        $this->clearPublisherFailure(basename($relativePath));
        return true;
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
        $validation = $this->validateReceive($fileName, $content);
        if ($validation !== null) return $validation;

        $target = $this->inboxDir . DIRECTORY_SEPARATOR . $fileName;
        if (is_link($target)) return new PostInboxReceiveResult(false, 409, '同名の下書きがすでにあります。');
        if (file_exists($target)) {
            $existing = @file_get_contents($target);
            if (!is_string($existing) || !$this->isPublisherFailureCandidate($fileName, $existing)) {
                return new PostInboxReceiveResult(false, 409, '同名の下書きがすでにあります。');
            }
            if (!$this->replaceFile($target, $content)) return new PostInboxReceiveResult(false, 500, '下書きを安全に置き換えられませんでした。');
            $this->clearPublisherFailure($fileName);
            return new PostInboxReceiveResult(true, 200, '下書きを置き換えました。');
        }
        $inboxBase = realpath($this->inboxDir);
        if ($inboxBase === false || realpath(dirname($target)) !== $inboxBase) {
            return new PostInboxReceiveResult(false, 400, 'ファイル名が正しくありません。');
        }
        $handle = @fopen($target, 'x');
        if ($handle === false) {
            return new PostInboxReceiveResult(false, file_exists($target) ? 409 : 500, file_exists($target) ? '同名の下書きがすでにあります。' : '下書きを保存できません。');
        }
        $written = @fwrite($handle, $content);
        @fclose($handle);
        if ($written !== strlen($content)) {
            @unlink($target);
            return new PostInboxReceiveResult(false, 500, '下書きを保存できません。');
        }
        return new PostInboxReceiveResult(true, 201, '下書きとして受信しました。');
    }

    /** @param mixed[] $expectedImages */
    public function beginImageReceive(string $fileName, string $content, array $expectedImages): PostInboxReceiveResult
    {
        $validation = $this->validateReceive($fileName, $content);
        if ($validation !== null) return $validation;
        $target = $this->inboxDir . DIRECTORY_SEPARATOR . $fileName;
        if (is_link($target)) return new PostInboxReceiveResult(false, 409, '同名の下書きがすでにあります。');
        if (file_exists($target)) {
            $existing = @file_get_contents($target);
            if (!is_string($existing) || !$this->isPublisherFailureCandidate($fileName, $existing)) {
                return new PostInboxReceiveResult(false, 409, '同名の下書きがすでにあります。');
            }
        }
        $references = $this->managedImageReferences($content);
        $expected = array_values(array_unique(array_map('strtolower', array_filter($expectedImages, 'is_string'))));
        sort($references); sort($expected);
        if ($references === [] || count($references) > 5 || $references !== $expected) {
            return new PostInboxReceiveResult(false, 400, 'Markdown内の画像と送信予定の画像が一致しません。');
        }
        return $this->imageResult($this->imageStore->begin($fileName, $content, $expected));
    }

    public function receiveImage(string $uploadId, string $imageName, string $body, int $chunkIndex, int $chunkCount, int $totalSize): PostInboxReceiveResult
    {
        return $this->imageResult($this->imageStore->receiveChunk($uploadId, $imageName, $body, $chunkIndex, $chunkCount, $totalSize));
    }

    public function finalizeImageReceive(string $uploadId): PostInboxReceiveResult
    {
        $ready = $this->imageStore->finalize($uploadId);
        if (empty($ready['ok'])) return $this->imageResult($ready);
        $fileName = (string) ($ready['file_name'] ?? '');
        $content = (string) ($ready['content'] ?? '');
        $result = $this->receive($fileName, $content);
        if (!$result->ok) {
            $this->imageStore->deleteItem((string) ($ready['upload_id'] ?? ''));
            return $result;
        }
        $this->imageStore->pruneForFile($fileName, (string) ($ready['upload_id'] ?? ''));
        return new PostInboxReceiveResult(true, 201, '画像付きMarkdownを受信しました。', (string) ($ready['upload_id'] ?? ''));
    }

    public function cancelImageReceive(string $uploadId): bool { return $this->imageStore->cancel($uploadId); }

    /** @return array<int,array<string,mixed>> */
    public function stagedImageFiles(string $fileName): array { return $this->imageStore->stagedFiles($fileName); }

    public function markPublisherAutoPublishFailure(string $relativePath): bool
    {
        $read = $this->read($relativePath);
        if (!$read->ok) return false;
        $draft = $this->setDraftTrue($read->content);
        if ($draft === null || !$this->replaceFile($this->safePath($relativePath) ?? '', $draft)) return false;
        return $this->writePublisherFailure($read->fileName, $draft);
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
            $this->error = '下書き保存領域を作成できませんでした。保存先の権限を確認してください。';
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

    private function setDraftTrue(string $markdown): ?string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        if (substr($normalized, 0, 4) !== "---\n") return null;
        $closing = strpos($normalized, "\n---", 4);
        if ($closing === false) return null;
        $frontMatter = substr($normalized, 4, $closing - 4);
        if (preg_match('/^([ \t]*draft[ \t]*:).*$/mi', $frontMatter) === 1) {
            $updated = preg_replace('/^([ \t]*draft[ \t]*:).*$/mi', '$1 true', $frontMatter, 1);
        } else {
            $updated = $frontMatter . ($frontMatter === '' ? '' : "\n") . 'draft: true';
        }
        return is_string($updated) ? "---\n" . $updated . "\n---" . substr($normalized, $closing + 4) : null;
    }

    private function validateReceive(string $fileName, string $content): ?PostInboxReceiveResult
    {
        if (!$this->ensureDirectory()) return new PostInboxReceiveResult(false, 500, '下書き保存領域を利用できません。');
        if ($fileName === '' || strpos($fileName, "\0") !== false || $fileName !== basename($fileName) || strpos($fileName, '/') !== false || strpos($fileName, '\\') !== false || strpos($fileName, ':') !== false) return new PostInboxReceiveResult(false, 400, 'ファイル名が正しくありません。');
        if (strlen($content) > PostUploadInput::maxBytes()) return new PostInboxReceiveResult(false, 413, 'ファイルサイズが大きすぎます。初期版では1MBまでです。');
        $prepared = $this->submissionPreparer->prepare($content, $fileName, '', '');
        if (!$prepared->ok) return new PostInboxReceiveResult(false, 400, (string) ($prepared->errors[0] ?? 'Markdownを受信できません。'));
        return null;
    }

    /** @return string[] */
    private function managedImageReferences(string $markdown): array
    {
        if (preg_match_all('/!\[[^\]\n]*\]\(images\/(tms-[a-f0-9]{16}\.(?:jpg|jpeg|png|gif|webp))\)/iu', $markdown, $matches) < 1) return [];
        return array_values(array_unique(array_map(static fn ($name): string => strtolower((string) $name), $matches[1])));
    }

    private function replaceFile(string $path, string $content): bool
    {
        if ($path === '' || !is_file($path)) return false;
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) { @unlink($tmp); return false; }
        @chmod($tmp, 0600);
        return @rename($tmp, $path);
    }

    private function publisherMetadataDir(): string { return $this->inboxDir . DIRECTORY_SEPARATOR . '.publisher'; }
    private function publisherMetadataPath(string $fileName): string { return $this->publisherMetadataDir() . DIRECTORY_SEPARATOR . hash('sha256', $fileName) . '.json'; }
    private function writePublisherFailure(string $fileName, string $content): bool
    {
        if (!is_dir($this->publisherMetadataDir()) && !@mkdir($this->publisherMetadataDir(), 0700, true) && !is_dir($this->publisherMetadataDir())) return false;
        $data = json_encode(['replaceable' => true, 'content_hash' => hash('sha256', $content)], JSON_UNESCAPED_SLASHES);
        return is_string($data) && @file_put_contents($this->publisherMetadataPath($fileName), $data . "\n", LOCK_EX) !== false;
    }
    private function isPublisherFailureCandidate(string $fileName, string $content): bool
    {
        $raw = @file_get_contents($this->publisherMetadataPath($fileName));
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) && !empty($data['replaceable']) && is_string($data['content_hash'] ?? null) && hash_equals((string) $data['content_hash'], hash('sha256', $content));
    }
    private function clearPublisherFailure(string $fileName): void { @unlink($this->publisherMetadataPath($fileName)); }

    /** @param array<string,mixed> $result */
    private function imageResult(array $result): PostInboxReceiveResult { return new PostInboxReceiveResult(!empty($result['ok']), (int) ($result['status'] ?? 500), (string) ($result['message'] ?? '画像を処理できませんでした。'), (string) ($result['upload_id'] ?? '')); }
}
