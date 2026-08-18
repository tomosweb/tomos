<?php

declare(strict_types=1);

namespace Tomos;

final class PostPublishResult
{
    public bool $ok;
    /** @var string[] */
    public array $errors;
    /** @var string[] */
    public array $warnings;

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    public function __construct(bool $ok, array $errors = [], array $warnings = [])
    {
        $this->ok = $ok;
        $this->errors = $errors;
        $this->warnings = $warnings;
    }
}

final class PostPublisher
{
    private string $contentDir;
    private string $cacheDir;
    private FrontMatterParser $frontMatterParser;
    private bool $htmlCacheEnabled;
    private bool $includeDrafts;
    private array $site;
    private ?array $freshImageReferenceIndex = null;

    public function __construct(
        string $contentDir,
        string $cacheDir,
        FrontMatterParser $frontMatterParser,
        bool $htmlCacheEnabled,
        bool $includeDrafts,
        array $site
    ) {
        $this->contentDir = $contentDir;
        $this->cacheDir = $cacheDir;
        $this->frontMatterParser = $frontMatterParser;
        $this->htmlCacheEnabled = $htmlCacheEnabled;
        $this->includeDrafts = $includeDrafts;
        $this->site = $site;
    }

    /**
     * Save a newly-created Markdown file and its already-prepared images.
     *
     * @param array<string,string> $images
     */
    public function publishNew(string $targetPath, string $content, array $images, string $folder, bool $addInitialPublishedMetadata = true): PostPublishResult
    {
        $imageSave = $this->saveImages($images, $folder);
        if ($imageSave['error'] !== '') {
            return new PostPublishResult(false, [$imageSave['error']]);
        }

        if ($addInitialPublishedMetadata) {
            $content = $this->withInitialPublishedMetadata($content);
        }
        $saveError = $this->writeNewFile($targetPath, $content);
        if ($saveError !== '') {
            $rollbackOk = $this->rollbackImageChanges($imageSave['created'], $imageSave['replaced']);
            $errors = [$saveError];
            if (!$rollbackOk) {
                $errors[] = '画像の自動復元を完了できませんでした。管理者による確認が必要です。';
            }
            return new PostPublishResult(false, $errors);
        }

        $warnings = array_merge($imageSave['warnings'], $this->commitImageChanges($imageSave['replaced']));
        return new PostPublishResult(true, [], array_values(array_unique($warnings)));
    }

    /**
     * Replace an existing Markdown file and save its already-prepared images.
     *
     * @param array<string,string> $images
     */
    public function updateExisting(string $targetPath, string $content, array $images, string $folder): PostPublishResult
    {
        $imageSave = $this->saveImages($images, $folder);
        if ($imageSave['error'] !== '') {
            return new PostPublishResult(false, [$imageSave['error']]);
        }

        $replaceError = $this->replaceFileSafely($targetPath, $content);
        if ($replaceError !== '') {
            $rollbackOk = $this->rollbackImageChanges($imageSave['created'], $imageSave['replaced']);
            $errors = [$replaceError];
            if (!$rollbackOk) {
                $errors[] = '画像の自動復元を完了できませんでした。管理者による確認が必要です。';
            }
            return new PostPublishResult(false, $errors);
        }

        $warnings = array_merge($imageSave['warnings'], $this->commitImageChanges($imageSave['replaced']));
        return new PostPublishResult(true, [], array_values(array_unique($warnings)));
    }

    /**
     * Rebuild all indexes after a successful publication.
     *
     * @return string[]
     */
    public function rebuildIndexes(string $contentPath): array
    {
        return array_merge(
            $this->rebuildMetadata($contentPath),
            $this->rebuildImageReferences()
        );
    }

    /**
     * Delete images selected by PostUpload after a successful update, unless
     * rebuilding the image-reference index already reported a warning.
     *
     * @param string[] $imagePaths
     * @param string[] $warnings
     * @return string[]
     */
    public function deleteUnreferencedImagesIfSafe(array $imagePaths, array $warnings): array
    {
        if ($this->hasImageReferenceWarning($warnings)) {
            return [];
        }

        $index = new ImageReferenceIndex(
            $this->contentDir,
            $this->cacheDir,
            $this->frontMatterParser,
            $this->includeDrafts
        );
        return (new ImageDeletionRetryQueue($this->cacheDir, $index))->attempt(
            $imagePaths,
            $this->freshImageReferenceIndex,
            false
        );
    }

    /**
     * @param array<string,string> $images
     * @return array{error:string,created:string[],replaced:array<string,string>,warnings:string[]}
     */
    private function saveImages(array $images, string $folder): array
    {
        if ($images === []) {
            return ['error' => '', 'created' => [], 'replaced' => [], 'warnings' => []];
        }

        $contentBase = realpath($this->contentDir);
        if ($contentBase === false || !is_dir($contentBase)) {
            return ['error' => 'content/ フォルダが見つかりません。', 'created' => [], 'replaced' => [], 'warnings' => []];
        }

        $imageDir = $contentBase;
        if ($folder !== '') {
            $imageDir .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder);
        }
        $imageDir .= DIRECTORY_SEPARATOR . 'images';
        if (!is_dir($imageDir) && !@mkdir($imageDir, 0775, true) && !is_dir($imageDir)) {
            return ['error' => '画像保存先フォルダを作成できませんでした。', 'created' => [], 'replaced' => [], 'warnings' => []];
        }

        $imageDirReal = realpath($imageDir);
        if ($imageDirReal === false || !Security::isPathInside($imageDirReal, $contentBase)) {
            return ['error' => '画像保存先を確認できませんでした。', 'created' => [], 'replaced' => [], 'warnings' => []];
        }

        $created = [];
        $replaced = [];
        $warnings = [];
        $processor = new ImageProcessor();
        foreach ($images as $fileName => $sourcePath) {
            $fileName = strtolower((string) $fileName);
            if (preg_match('/\Atms-[a-f0-9]{16}\.(jpg|jpeg|png|gif|webp)\z/', $fileName) !== 1 || !is_file($sourcePath)) {
                $rollbackOk = $this->rollbackImageChanges($created, $replaced);
                $error = '選択された画像を確認できませんでした。';
                if (!$rollbackOk) {
                    $error .= ' 画像の自動復元を完了できなかったため、管理者による確認が必要です。';
                }
                return ['error' => $error, 'created' => [], 'replaced' => [], 'warnings' => $warnings];
            }

            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $processed = $processor->process($sourcePath, $extension, $imageDirReal);
            if (!$processed->ok) {
                $rollbackOk = $this->rollbackImageChanges($created, $replaced);
                $error = $processed->error;
                if (!$rollbackOk) {
                    $error .= ' 画像の自動復元を完了できなかったため、管理者による確認が必要です。';
                }
                return ['error' => $error, 'created' => [], 'replaced' => [], 'warnings' => $warnings];
            }
            $warnings = array_merge($warnings, $processed->warnings);

            $targetPath = $imageDirReal . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($targetPath)) {
                $targetHash = hash_file('sha256', $targetPath);
                $sourceHash = hash_file('sha256', $sourcePath);
                $processedHash = hash_file('sha256', $processed->path);
                if ($targetHash !== $sourceHash && $targetHash !== $processedHash) {
                    if (!is_string($sourceHash) || !is_string($processedHash)) {
                        @unlink($processed->path);
                        $rollbackOk = $this->rollbackImageChanges($created, $replaced);
                        $error = '画像の内容を確認できませんでした。';
                        if (!$rollbackOk) {
                            $error .= ' 画像の自動復元を完了できなかったため、管理者による確認が必要です。';
                        }
                        return ['error' => $error, 'created' => [], 'replaced' => [], 'warnings' => $warnings];
                    }
                    $replacement = $this->replaceExistingImage($processed->path, $targetPath);
                    if (!empty($replacement['ok'])) {
                        $replaced[$targetPath] = (string) $replacement['backup'];
                        continue;
                    }
                    @unlink($processed->path);
                    $rollbackOk = $this->rollbackImageChanges($created, $replaced);
                    $error = '同じ名前の画像がすでにあります。別の画像を選び直してください。';
                    if (!empty($replacement['rollback_failed']) || !$rollbackOk) {
                        $error .= ' 画像の自動復元を完了できなかったため、管理者による確認が必要です。';
                    }
                    return ['error' => $error, 'created' => [], 'replaced' => [], 'warnings' => $warnings];
                }
                @unlink($processed->path);
                continue;
            }

            if (!@rename($processed->path, $targetPath) && !@copy($processed->path, $targetPath)) {
                @unlink($processed->path);
                $rollbackOk = $this->rollbackImageChanges($created, $replaced);
                $error = '画像を保存できませんでした。';
                if (!$rollbackOk) {
                    $error .= ' 画像の自動復元を完了できなかったため、管理者による確認が必要です。';
                }
                return ['error' => $error, 'created' => [], 'replaced' => [], 'warnings' => $warnings];
            }
            @unlink($processed->path);
            $created[] = $targetPath;
        }

        return [
            'error' => '',
            'created' => $created,
            'replaced' => $replaced,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** @return array{ok:bool,backup:string,rollback_failed:bool} */
    private function replaceExistingImage(string $sourcePath, string $targetPath): array
    {
        try {
            $backupPath = $targetPath . '.tomos-backup-' . bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            return ['ok' => false, 'backup' => '', 'rollback_failed' => false];
        }

        if (!@rename($targetPath, $backupPath)) {
            return ['ok' => false, 'backup' => '', 'rollback_failed' => false];
        }
        if (@rename($sourcePath, $targetPath)) {
            return ['ok' => true, 'backup' => $backupPath, 'rollback_failed' => false];
        }

        $restored = @rename($backupPath, $targetPath);
        return ['ok' => false, 'backup' => $backupPath, 'rollback_failed' => !$restored];
    }

    /**
     * @param string[] $created
     * @param array<string,string> $replaced map target path to backup path
     */
    private function rollbackImageChanges(array $created, array $replaced): bool
    {
        $ok = true;
        foreach (array_reverse($created) as $path) {
            if (is_file($path) && !@unlink($path)) {
                $ok = false;
            }
        }

        foreach (array_reverse($replaced, true) as $target => $backup) {
            if (is_file($target) && !@unlink($target)) {
                $ok = false;
                continue;
            }
            if (!is_file($backup) || !@rename($backup, $target)) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * @param array<string,string> $replaced map target path to backup path
     * @return string[]
     */
    private function commitImageChanges(array $replaced): array
    {
        $warnings = [];
        foreach ($replaced as $backup) {
            if (is_file($backup) && !@unlink($backup)) {
                $warnings[] = '画像更新は完了しましたが、旧画像の一時バックアップを削除できませんでした。';
            }
        }
        return $warnings;
    }

    private function writeNewFile(string $targetPath, string $content): string
    {
        $handle = @fopen($targetPath, 'xb');
        if ($handle === false) {
            return 'ファイルを保存できませんでした。保存先の権限を確認してください。';
        }

        $written = @fwrite($handle, $content);
        @fclose($handle);
        if ($written === false || $written < strlen($content)) {
            @unlink($targetPath);
            return 'ファイルを書き込めませんでした。';
        }

        return '';
    }

    public function withInitialPublishedMetadata(string $markdown): string
    {
        $parsed = $this->frontMatterParser->parse($markdown);
        $metadata = $this->frontMatterParser->buildPageMetadata($parsed['metadata'], $parsed['body'], 'post.md');
        if (!empty($metadata['draft'])) {
            return $markdown;
        }

        $now = $this->publicationNow();
        return PublishedMetadata::addInitialMetadata(
            $markdown,
            $now->format('Y-m-d'),
            $now->format(\DateTimeInterface::ATOM)
        );
    }

    private function publicationNow(): \DateTimeImmutable
    {
        $timezoneName = (string) ($this->site['timezone'] ?? 'Asia/Tokyo');
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Throwable $exception) {
            $timezone = new \DateTimeZone('Asia/Tokyo');
        }

        return new \DateTimeImmutable('now', $timezone);
    }

    private function replaceFileSafely(string $targetPath, string $content): string
    {
        $targetDir = dirname($targetPath);
        $tmpPath = $targetDir . DIRECTORY_SEPARATOR . '.tomos-update-' . bin2hex(random_bytes(12)) . '.tmp';
        if (@file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            return '更新用の一時ファイルを書き込めませんでした。既存ページは変更していません。';
        }

        if (!@rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);
            return 'ページを更新できませんでした。既存ページは変更していません。';
        }

        return '';
    }

    /** @return string[] */
    private function rebuildMetadata(string $contentPath): array
    {
        try {
            $index = new MetadataIndex(
                $this->contentDir,
                $this->cacheDir,
                $this->frontMatterParser,
                $this->includeDrafts
            );
            $index->rebuild();
            return [];
        } catch (\Throwable $exception) {
            $warnings = ['Markdownは保存しましたが、一覧・検索・タグ・RSS・sitemap用の情報を更新できませんでした。'];
            $htmlCache = new HtmlCache($this->cacheDir, $this->htmlCacheEnabled);
            if ($contentPath !== '' && !$htmlCache->delete($contentPath)) {
                $warnings[] = '対象ページのHTMLキャッシュを削除できませんでした。表示が古い場合は cache/html/ を確認してください。';
            }

            return $warnings;
        }
    }

    /** @return string[] */
    private function rebuildImageReferences(): array
    {
        try {
            $index = new ImageReferenceIndex(
                $this->contentDir,
                $this->cacheDir,
                $this->frontMatterParser,
                $this->includeDrafts
            );
            $this->freshImageReferenceIndex = $index->rebuild();
            return (new ImageDeletionRetryQueue($this->cacheDir, $index))->attempt([], $this->freshImageReferenceIndex);
        } catch (\Throwable $exception) {
            return ['Markdownは保存しましたが、画像参照情報を更新できませんでした。画像削除判定を行う前に cache/index/image-references.json を再生成してください。'];
        }
    }

    /** @param string[] $warnings */
    private function hasImageReferenceWarning(array $warnings): bool
    {
        foreach ($warnings as $warning) {
            if (strpos((string) $warning, '画像参照情報') !== false) {
                return true;
            }
        }

        return false;
    }
}
