<?php

declare(strict_types=1);

namespace Tomos;

final class PostUploadInputResult
{
    public bool $ok;
    public bool $canContinue;
    public bool $contentRead;
    /** @var string[] */
    public array $errors;
    public string $content;
    public string $originalFileName;
    public int $size;

    /**
     * @param string[] $errors
     */
    public function __construct(
        bool $ok,
        array $errors = [],
        string $content = '',
        string $originalFileName = '',
        int $size = 0,
        bool $canContinue = true,
        bool $contentRead = true
    ) {
        $this->ok = $ok;
        $this->canContinue = $canContinue;
        $this->contentRead = $contentRead;
        $this->errors = $errors;
        $this->content = $content;
        $this->originalFileName = $originalFileName;
        $this->size = $size;
    }
}

final class PostUploadInput
{
    public const MAX_BYTES = 1048576;

    public static function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    /**
     * @param array<string,mixed> $file
     * @param callable(string):bool|null $isUploadedFile
     */
    public static function read(array $file, ?callable $isUploadedFile = null): PostUploadInputResult
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return new PostUploadInputResult(false, [self::uploadErrorMessage($error)], '', '', 0, false, false);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $isUploaded = $isUploadedFile ?? static fn (string $path): bool => is_uploaded_file($path);
        if ($tmpPath === '' || !$isUploaded($tmpPath)) {
            return new PostUploadInputResult(false, ['アップロードされたファイルを確認できませんでした。'], '', '', 0, false, false);
        }

        $size = (int) ($file['size'] ?? 0);
        $errors = [];
        if ($size <= 0) {
            $errors[] = '空のファイルは投稿できません。';
        }
        if ($size > self::MAX_BYTES) {
            $errors[] = 'ファイルサイズが大きすぎます。初期版では1MBまでです。';
        }

        $content = @file_get_contents($tmpPath);
        $contentRead = $content !== false;
        if (!$contentRead) {
            $errors[] = 'アップロードされたファイルを読み込めませんでした。';
            $content = '';
        }

        return new PostUploadInputResult(
            $errors === [],
            $errors,
            $content,
            (string) ($file['name'] ?? ''),
            $size,
            true,
            $contentRead
        );
    }

    public static function uploadErrorMessage(int $error): string
    {
        switch ($error) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'ファイルサイズが大きすぎます。';
            case UPLOAD_ERR_NO_FILE:
                return '投稿するファイルを選択してください。';
            default:
                return 'ファイルをアップロードできませんでした。';
        }
    }
}
