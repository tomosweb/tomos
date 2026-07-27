<?php

declare(strict_types=1);

namespace Tomos;

foreach ([
    'Security' => 'Security.php',
    'FrontMatterParser' => 'FrontMatterParser.php',
    'Route' => 'Router.php',
    'PageRepository' => 'PageRepository.php',
    'HtmlCache' => 'HtmlCache.php',
    'ImageProcessor' => 'ImageProcessor.php',
    'ImageReferenceIndex' => 'ImageReferenceIndex.php',
    'ImageDeletionRetryQueue' => 'ImageDeletionRetryQueue.php',
    'LinkAliasIndex' => 'LinkAliasIndex.php',
    'MetadataIndex' => 'MetadataIndex.php',
    'PostUploadTempStore' => 'PostUploadTempStore.php',
    'PostBasicPage' => 'PostBasicPage.php',
] as $dependency => $file) {
    if (!class_exists(__NAMESPACE__ . '\\' . $dependency)) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . $file;
    }
}

final class PostUploadResult
{
    public bool $ok;
    /** @var string[] */
    public array $errors;
    /** @var string[] */
    public array $warnings;
    public string $contentPath;
    public string $internalUrl;
    public string $absoluteUrl;
    public string $originalFileName;
    public string $savedFileName;
    public bool $conflict;
    public string $tempId;
    public string $existingTitle;
    public string $newTitle;
    public string $expiresAt;
    public string $operation;
    public string $suggestedFileName;
    public int $imageCount;

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    public function __construct(
        bool $ok,
        array $errors = [],
        array $warnings = [],
        string $contentPath = '',
        string $internalUrl = '',
        string $absoluteUrl = '',
        string $originalFileName = '',
        string $savedFileName = '',
        bool $conflict = false,
        string $tempId = '',
        string $existingTitle = '',
        string $newTitle = '',
        string $expiresAt = '',
        string $operation = 'create',
        string $suggestedFileName = '',
        int $imageCount = 0
    ) {
        $this->ok = $ok;
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->contentPath = $contentPath;
        $this->internalUrl = $internalUrl;
        $this->absoluteUrl = $absoluteUrl;
        $this->originalFileName = $originalFileName;
        $this->savedFileName = $savedFileName;
        $this->conflict = $conflict;
        $this->tempId = $tempId;
        $this->existingTitle = $existingTitle;
        $this->newTitle = $newTitle;
        $this->expiresAt = $expiresAt;
        $this->operation = $operation;
        $this->suggestedFileName = $suggestedFileName;
        $this->imageCount = $imageCount;
    }
}

final class PostUpload
{
    private const MAX_BYTES = 1048576;
    private const MAX_IMAGE_BYTES = 10485760;
    private const MAX_IMAGE_COUNT = 5;
    /** @var string[] */
    private const ACCEPTED_EXTENSIONS = ['md', 'markdown', 'txt'];
    /** @var array<string,string> */
    private const ACCEPTED_IMAGE_EXTENSIONS = [
        'jpg' => 'jpg',
        'jpeg' => 'jpg',
        'png' => 'png',
        'gif' => 'gif',
        'webp' => 'webp',
    ];
    /** @var string[] */
    private const DANGEROUS_EXTENSION_SEGMENTS = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'html', 'htm', 'js', 'css', 'svg'];
    /** @var array<string,string> */
    private const JAPANESE_NFC_FALLBACK = [
        'ゔ' => 'ゔ',
        'が' => 'が', 'ぎ' => 'ぎ', 'ぐ' => 'ぐ', 'げ' => 'げ', 'ご' => 'ご',
        'ざ' => 'ざ', 'じ' => 'じ', 'ず' => 'ず', 'ぜ' => 'ぜ', 'ぞ' => 'ぞ',
        'だ' => 'だ', 'ぢ' => 'ぢ', 'づ' => 'づ', 'で' => 'で', 'ど' => 'ど',
        'ば' => 'ば', 'び' => 'び', 'ぶ' => 'ぶ', 'べ' => 'べ', 'ぼ' => 'ぼ',
        'ぱ' => 'ぱ', 'ぴ' => 'ぴ', 'ぷ' => 'ぷ', 'ぺ' => 'ぺ', 'ぽ' => 'ぽ',
        'ゞ' => 'ゞ',
        'ヴ' => 'ヴ',
        'ガ' => 'ガ', 'ギ' => 'ギ', 'グ' => 'グ', 'ゲ' => 'ゲ', 'ゴ' => 'ゴ',
        'ザ' => 'ザ', 'ジ' => 'ジ', 'ズ' => 'ズ', 'ゼ' => 'ゼ', 'ゾ' => 'ゾ',
        'ダ' => 'ダ', 'ヂ' => 'ヂ', 'ヅ' => 'ヅ', 'デ' => 'デ', 'ド' => 'ド',
        'バ' => 'バ', 'ビ' => 'ビ', 'ブ' => 'ブ', 'ベ' => 'ベ', 'ボ' => 'ボ',
        'パ' => 'パ', 'ピ' => 'ピ', 'プ' => 'プ', 'ペ' => 'ペ', 'ポ' => 'ポ',
        'ヷ' => 'ヷ', 'ヸ' => 'ヸ', 'ヹ' => 'ヹ', 'ヺ' => 'ヺ', 'ヾ' => 'ヾ',
    ];

    private string $contentDir;
    private string $cacheDir;
    private array $site;
    private bool $htmlCacheEnabled;
    private bool $includeDrafts;
    private FrontMatterParser $frontMatterParser;
    private PostUploadTempStore $tempStore;
    private ?array $freshImageReferenceIndex = null;

    public function __construct(array $config, string $rootDir)
    {
        $this->contentDir = (string) (($config['paths']['content_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'content'));
        $this->cacheDir = (string) (($config['paths']['cache_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'cache'));
        $this->site = is_array($config['site'] ?? null) ? $config['site'] : [];
        $this->htmlCacheEnabled = (bool) ($config['features']['html_cache'] ?? false);
        $this->includeDrafts = (bool) ($config['metadata']['include_drafts'] ?? false);
        $this->frontMatterParser = new FrontMatterParser();
        $this->tempStore = new PostUploadTempStore($this->cacheDir);
    }

    public function handle(array $file, string $folderInput, string $fileNameInput, ?string $sessionId = null, array $imageFiles = [], array $omittedImages = [], bool $trustedStagedImages = false): PostUploadResult
    {
        $errors = [];
        $warnings = [];

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new PostUploadResult(false, [$this->uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE))]);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return new PostUploadResult(false, ['アップロードされたファイルを確認できませんでした。']);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            $errors[] = '空のファイルは投稿できません。';
        }
        if ($size > self::MAX_BYTES) {
            $errors[] = 'ファイルサイズが大きすぎます。初期版では1MBまでです。';
        }

        $originalName = (string) ($file['name'] ?? '');
        $chosenName = trim($fileNameInput) !== '' ? trim($fileNameInput) : $originalName;
        $basicPageType = PostBasicPage::typeFromFileName($chosenName);
        $safeFileName = $this->normalizeFileName($chosenName, $errors);
        // Basic pages always belong directly below content/, regardless of UI or frontmatter input.
        $folder = $basicPageType !== '' ? '' : $this->normalizeFolder($folderInput, $errors);
        if ($basicPageType === '' && PostBasicPage::isProtectedContentPath($safeFileName)) {
            $errors[] = 'トップページは index.md、Aboutページは about.md の正式なファイル名で投稿してください。';
        }
        $content = @file_get_contents($tmpPath);
        if ($content === false) {
            $errors[] = 'アップロードされたファイルを読み込めませんでした。';
        } else {
            if ($this->looksBinary($content)) {
                $errors[] = 'テキストファイルとして読み込めない内容が含まれています。';
            }
            if (function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8')) {
                $errors[] = '文字コードはUTF-8のファイルを投稿してください。';
            }
        }

        if ($errors !== []) {
            return new PostUploadResult(false, $errors);
        }

        $content = $this->applyImageOmissions($content, $omittedImages, $errors, $warnings);
        if ($errors !== []) {
            return new PostUploadResult(false, $errors);
        }

        $imagePlan = $this->prepareImages($content, $imageFiles, $errors, $warnings, $trustedStagedImages);
        if ($errors !== []) {
            return new PostUploadResult(false, $errors);
        }

        $contentDir = rtrim($this->contentDir, DIRECTORY_SEPARATOR);
        if (!is_dir($contentDir)) {
            return new PostUploadResult(false, ['content/ フォルダが見つかりません。']);
        }

        $targetDir = $contentDir;
        if ($folder !== '') {
            $targetDir .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder);
        }

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return new PostUploadResult(false, ['保存先フォルダを作成できませんでした。']);
        }

        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $safeFileName;
        $contentBase = realpath($contentDir);
        $targetDirReal = realpath($targetDir);
        if ($contentBase === false || $targetDirReal === false || strpos(rtrim($targetDirReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, rtrim($contentBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
            return new PostUploadResult(false, ['保存先は content/ 配下にしてください。']);
        }

        if (!file_exists($targetPath)) {
            $equivalentFileName = $this->findEquivalentExistingFileName($targetDirReal, $safeFileName);
            if ($equivalentFileName !== '') {
                $safeFileName = $equivalentFileName;
                $targetPath = $targetDirReal . DIRECTORY_SEPARATOR . $safeFileName;
            }
        }

        if (file_exists($targetPath)) {
            $existingMarkdown = @file_get_contents($targetPath);
            if ($existingMarkdown === false) {
                return new PostUploadResult(false, ['同じ保存先にページがありますが、現在の内容を確認できませんでした。']);
            }

            $contentPath = ($folder === '' ? '' : $folder . '/') . $safeFileName;
            $internalUrl = $this->urlFromContentPath($contentPath);
            $absoluteUrl = $this->absolutePublicUrl($internalUrl);
            $tempRecord = $this->tempStore->create($content, [
                'session_id' => $sessionId ?? '',
                'folder' => $folder,
                'original_file_name' => $chosenName,
                'planned_file_name' => $safeFileName,
                'saved_file_name' => $safeFileName,
                'content_path' => $contentPath,
                'internal_url' => $internalUrl,
                'absolute_url' => $absoluteUrl,
                'existing_title' => $this->titleFromMarkdown($existingMarkdown, $contentPath),
                'new_title' => $this->titleFromMarkdown($content, $contentPath),
                'existing_hash' => hash('sha256', $existingMarkdown),
                'image_count' => count($imagePlan),
            ], $imagePlan);
            if ($tempRecord === null) {
                return new PostUploadResult(false, ['確認用の一時ファイルを保存できませんでした。時間をおいて再度投稿してください。']);
            }

            return new PostUploadResult(
                false,
                [],
                $warnings,
                $contentPath,
                $internalUrl,
                $absoluteUrl,
                $chosenName,
                $safeFileName,
                true,
                $tempRecord->id,
                (string) ($tempRecord->meta['existing_title'] ?? ''),
                (string) ($tempRecord->meta['new_title'] ?? ''),
                date('Y-m-d H:i', (int) ($tempRecord->meta['expires_at'] ?? 0)),
                'conflict',
                $this->suggestedRenamedFileName($tempRecord),
                count($imagePlan)
            );
        }

        $imageSave = $this->saveImages($imagePlan, $folder);
        if ($imageSave['error'] !== '') {
            return new PostUploadResult(false, [$imageSave['error']]);
        }
        $warnings = array_merge($warnings, $imageSave['warnings']);

        $saveError = $this->writeNewFile($targetPath, $content);
        if ($saveError !== '') {
            $this->removeSavedImages($imageSave['created']);
            return new PostUploadResult(false, [$saveError]);
        }

        $contentPath = ($folder === '' ? '' : $folder . '/') . $safeFileName;
        $internalUrl = $this->urlFromContentPath($contentPath);
        $absoluteUrl = $this->absolutePublicUrl($internalUrl);

        $warnings = array_merge($warnings, $this->rebuildIndexes($contentPath));

        return new PostUploadResult(true, [], $warnings, $contentPath, $internalUrl, $absoluteUrl, $chosenName, $safeFileName, false, '', '', '', '', 'create', '', count($imagePlan));
    }

    public function updateFromTemp(string $tempId, ?string $sessionId = null): PostUploadResult
    {
        $record = $this->tempStore->load($tempId, $sessionId);
        if ($record === null) {
            return new PostUploadResult(false, ['確認用の一時ファイルが見つからないか、有効期限が切れました。もう一度投稿してください。']);
        }

        $target = $this->targetFromRecord($record);
        if ($target['error'] !== '') {
            $this->tempStore->delete($tempId);
            return new PostUploadResult(false, [$target['error']]);
        }

        $targetPath = $target['path'];
        if (!is_file($targetPath)) {
            $this->tempStore->delete($tempId);
            return new PostUploadResult(false, ['更新対象のファイルが見つかりません。投稿画面からやり直してください。']);
        }

        $current = @file_get_contents($targetPath);
        if ($current === false) {
            return new PostUploadResult(false, ['更新対象の現在の内容を確認できませんでした。']);
        }

        if (!hash_equals((string) ($record->meta['existing_hash'] ?? ''), hash('sha256', $current))) {
            $this->tempStore->delete($tempId);
            return new PostUploadResult(false, ['確認後に更新対象の内容が変わりました。もう一度投稿内容を確認してください。']);
        }

        $contentPath = (string) ($record->meta['content_path'] ?? '');
        $oldImageRefs = $this->managedImageReferences($current, $contentPath);
        $newImageRefs = $this->managedImageReferences($record->markdown, $contentPath);

        $imageSave = $this->saveImages($record->imagePaths, (string) ($record->meta['folder'] ?? ''));
        if ($imageSave['error'] !== '') {
            return new PostUploadResult(false, [$imageSave['error']]);
        }

        $replaceError = $this->replaceFileSafely($targetPath, $record->markdown);
        if ($replaceError !== '') {
            $this->removeSavedImages($imageSave['created']);
            return new PostUploadResult(false, [$replaceError]);
        }

        $this->tempStore->delete($tempId);

        $warnings = array_merge($imageSave['warnings'], $this->rebuildIndexes($contentPath));
        if (!$this->hasImageReferenceWarning($warnings)) {
            $warnings = array_merge($warnings, $this->deleteUnreferencedImages(array_values(array_diff($oldImageRefs, $newImageRefs))));
        }
        return new PostUploadResult(
            true,
            [],
            $warnings,
            $contentPath,
            (string) ($record->meta['internal_url'] ?? ''),
            (string) ($record->meta['absolute_url'] ?? ''),
            (string) ($record->meta['original_file_name'] ?? ''),
            (string) ($record->meta['saved_file_name'] ?? ''),
            false,
            '',
            (string) ($record->meta['existing_title'] ?? ''),
            (string) ($record->meta['new_title'] ?? ''),
            '',
            'update',
            '',
            (int) ($record->meta['image_count'] ?? count($record->imagePaths))
        );
    }

    public function createRenamedFromTemp(string $tempId, string $fileNameInput, ?string $sessionId = null): PostUploadResult
    {
        $record = $this->tempStore->load($tempId, $sessionId);
        if ($record === null) {
            return new PostUploadResult(false, ['確認用の一時ファイルが見つからないか、有効期限が切れました。もう一度投稿してください。']);
        }

        if (PostBasicPage::isProtectedContentPath((string) ($record->meta['content_path'] ?? ''))) {
            return new PostUploadResult(false, ['トップページとAboutページは別名で投稿できません。更新するか、投稿をやめてください。']);
        }

        $errors = [];
        $safeFileName = $this->normalizeFileName($fileNameInput, $errors);
        if ($errors !== []) {
            return new PostUploadResult(false, $errors, [], '', '', '', (string) ($record->meta['original_file_name'] ?? ''), $safeFileName);
        }

        $folder = (string) ($record->meta['folder'] ?? '');
        $contentPath = ($folder === '' ? '' : $folder . '/') . $safeFileName;
        $target = $this->targetFromFolderAndFile($folder, $safeFileName);
        if ($target['error'] !== '') {
            return new PostUploadResult(false, [$target['error']]);
        }

        if (file_exists($target['path'])) {
            return new PostUploadResult(false, ['このファイル名もすでに使われています。別のファイル名を指定してください。']);
        }

        $imageSave = $this->saveImages($record->imagePaths, $folder);
        if ($imageSave['error'] !== '') {
            return new PostUploadResult(false, [$imageSave['error']]);
        }

        $saveError = $this->writeNewFile($target['path'], $record->markdown);
        if ($saveError !== '') {
            $this->removeSavedImages($imageSave['created']);
            return new PostUploadResult(false, [$saveError]);
        }

        $this->tempStore->delete($tempId);

        $internalUrl = $this->urlFromContentPath($contentPath);
        $absoluteUrl = $this->absolutePublicUrl($internalUrl);
        $warnings = array_merge($imageSave['warnings'], $this->rebuildIndexes($contentPath));

        return new PostUploadResult(
            true,
            [],
            $warnings,
            $contentPath,
            $internalUrl,
            $absoluteUrl,
            (string) ($record->meta['original_file_name'] ?? ''),
            $safeFileName,
            false,
            '',
            (string) ($record->meta['existing_title'] ?? ''),
            $this->titleFromMarkdown($record->markdown, $contentPath),
            '',
            'rename_create',
            '',
            (int) ($record->meta['image_count'] ?? count($record->imagePaths))
        );
    }

    public function cancelTemp(string $tempId, ?string $sessionId = null): bool
    {
        if ($this->tempStore->load($tempId, $sessionId) === null) {
            return false;
        }

        $this->tempStore->delete($tempId);
        return true;
    }

    public function loadTemp(string $tempId, ?string $sessionId = null): ?PostUploadTempRecord
    {
        return $this->tempStore->load($tempId, $sessionId);
    }

    public function suggestedRenamedFileName(PostUploadTempRecord $record): string
    {
        $planned = (string) ($record->meta['planned_file_name'] ?? 'post.md');
        $folder = (string) ($record->meta['folder'] ?? '');
        $name = pathinfo($planned, PATHINFO_FILENAME);
        $extension = pathinfo($planned, PATHINFO_EXTENSION) ?: 'md';
        $counter = 2;
        do {
            $candidate = $name . '-' . $counter . '.' . $extension;
            $target = $this->targetFromFolderAndFile($folder, $candidate);
            $counter++;
        } while ($target['error'] === '' && file_exists($target['path']));

        return $candidate;
    }

    /**
     * @return array<string,string>
     */
    private function prepareImages(string $markdown, array $files, array &$errors, array &$warnings, bool $trustedStagedImages = false): array
    {
        $references = $this->extractImageReferences($markdown);
        if ($references === []) {
            if ($this->hasUploadedImages($files)) {
                $warnings[] = 'Markdown内に画像指定がないため、選択された画像は保存しませんでした。';
            }
            return [];
        }

        if (count($references) > self::MAX_IMAGE_COUNT) {
            $errors[] = '画像は5点まで投稿できます。';
            return [];
        }

        $uploadedImages = [];
        foreach ($this->normalizeUploadedImages($files) as $file) {
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                $errors[] = $this->uploadErrorMessage($error);
                continue;
            }

            $tmpPath = (string) ($file['tmp_name'] ?? '');
            if ($tmpPath === '' || ($trustedStagedImages ? !is_file($tmpPath) : !is_uploaded_file($tmpPath))) {
                $errors[] = '選択された画像を確認できませんでした。';
                continue;
            }

            $size = (int) ($file['size'] ?? 0);
            if ($size <= 0) {
                $errors[] = '空の画像は投稿できません。';
                continue;
            }
            if ($size > self::MAX_IMAGE_BYTES) {
                $errors[] = '10MB以下の画像を選んでください。';
                continue;
            }

            $extension = $this->imageExtensionFromUpload($file);
            if ($extension === '') {
                $errors[] = 'JPEG、PNG、WebP、GIFの画像を選んでください。';
                continue;
            }

            $uploadName = strtolower(basename((string) ($file['name'] ?? '')));
            if (
                preg_match('/\Atms-[a-f0-9]{16}\.(?:jpg|jpeg|png|gif|webp)\z/', $uploadName) === 1
                && in_array($uploadName, $references, true)
            ) {
                // Browser-side resizing keeps the original content hash in this managed name.
                $fileName = $uploadName;
            } else {
                $hash = hash_file('sha256', $tmpPath);
                if (!is_string($hash)) {
                    $errors[] = '選択された画像を読み込めませんでした。';
                    continue;
                }
                $fileName = 'tms-' . substr($hash, 0, 16) . '.' . $extension;
            }
            $uploadedImages[$fileName] = $tmpPath;
        }

        if ($errors !== []) {
            return [];
        }

        $missing = [];
        $plan = [];
        foreach ($references as $fileName) {
            if (isset($uploadedImages[$fileName])) {
                $plan[$fileName] = $uploadedImages[$fileName];
            } else {
                $missing[] = $fileName;
            }
        }

        if ($missing !== []) {
            $errors[] = 'Markdown内の画像に対応する元画像を選んでください。';
            return [];
        }

        return $plan;
    }

    /**
     * @param mixed[] $omittedImages
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function applyImageOmissions(string $markdown, array $omittedImages, array &$errors, array &$warnings): string
    {
        $references = $this->extractImageReferences($markdown);
        if (count($references) > self::MAX_IMAGE_COUNT) {
            $errors[] = '画像は5点まで投稿できます。';
            return $markdown;
        }

        $requested = [];
        foreach ($omittedImages as $imageName) {
            if (!is_string($imageName)) {
                $errors[] = '掲載をやめる画像を確認できませんでした。';
                return $markdown;
            }
            $normalized = strtolower(trim($imageName));
            if (
                preg_match('/\Atms-[a-f0-9]{16}\.(?:jpg|jpeg|png|gif|webp)\z/', $normalized) !== 1
                || !in_array($normalized, $references, true)
            ) {
                $errors[] = '掲載をやめる画像を確認できませんでした。';
                return $markdown;
            }
            $requested[$normalized] = true;
        }

        if ($requested === []) {
            return $markdown;
        }

        $updated = preg_replace_callback(
            '/!\[([^\]\n]*)\]\(images\/(tms-[a-f0-9]{16}\.(?:jpg|jpeg|png|gif|webp))\)/iu',
            static function (array $matches) use ($requested): string {
                return isset($requested[strtolower((string) $matches[2])]) ? '' : (string) $matches[0];
            },
            $markdown
        );
        if (!is_string($updated)) {
            $errors[] = '画像の掲載設定を反映できませんでした。';
            return $markdown;
        }

        $warnings[] = '選択した画像' . count($requested) . '点は、投稿用Markdownから画像記述を外します。端末に保存されているMarkdownは変更しません。';
        return $updated;
    }

    /**
     * @return string[]
     */
    private function extractImageReferences(string $markdown): array
    {
        if (preg_match_all('/!\[[^\]\n]*\]\(images\/(tms-[a-f0-9]{16}\.(?:jpg|jpeg|png|gif|webp))\)/iu', $markdown, $matches) < 1) {
            return [];
        }

        $references = [];
        foreach ($matches[1] as $fileName) {
            $references[] = strtolower((string) $fileName);
        }

        return array_values(array_unique($references));
    }

    private function hasUploadedImages(array $files): bool
    {
        foreach ($this->normalizeUploadedImages($files) as $file) {
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeUploadedImages(array $files): array
    {
        if ($files === []) {
            return [];
        }

        if (is_array($files['name'] ?? null)) {
            $normalized = [];
            foreach ($files['name'] as $index => $name) {
                $normalized[] = [
                    'name' => $name,
                    'type' => $files['type'][$index] ?? '',
                    'tmp_name' => $files['tmp_name'][$index] ?? '',
                    'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $files['size'][$index] ?? 0,
                ];
            }
            return $normalized;
        }

        return [$files];
    }

    private function imageExtensionFromUpload(array $file): string
    {
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (isset(self::ACCEPTED_IMAGE_EXTENSIONS[$extension])) {
            return self::ACCEPTED_IMAGE_EXTENSIONS[$extension];
        }

        $mimeType = strtolower((string) ($file['type'] ?? ''));
        if ($mimeType === 'image/jpeg') {
            return 'jpg';
        }
        if ($mimeType === 'image/png') {
            return 'png';
        }
        if ($mimeType === 'image/gif') {
            return 'gif';
        }
        if ($mimeType === 'image/webp') {
            return 'webp';
        }

        return '';
    }

    /**
     * @param array<string,string> $images
     * @return array{error:string,created:string[],warnings:string[]}
     */
    private function saveImages(array $images, string $folder): array
    {
        if ($images === []) {
            return ['error' => '', 'created' => [], 'warnings' => []];
        }

        $contentBase = realpath($this->contentDir);
        if ($contentBase === false || !is_dir($contentBase)) {
            return ['error' => 'content/ フォルダが見つかりません。', 'created' => [], 'warnings' => []];
        }

        $imageDir = $contentBase;
        if ($folder !== '') {
            $imageDir .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder);
        }
        $imageDir .= DIRECTORY_SEPARATOR . 'images';
        if (!is_dir($imageDir) && !@mkdir($imageDir, 0775, true) && !is_dir($imageDir)) {
            return ['error' => '画像保存先フォルダを作成できませんでした。', 'created' => [], 'warnings' => []];
        }

        $imageDirReal = realpath($imageDir);
        if ($imageDirReal === false || !Security::isPathInside($imageDirReal, $contentBase)) {
            return ['error' => '画像保存先を確認できませんでした。', 'created' => [], 'warnings' => []];
        }

        $created = [];
        $warnings = [];
        $processor = new ImageProcessor();
        foreach ($images as $fileName => $sourcePath) {
            $fileName = strtolower((string) $fileName);
            if (preg_match('/\Atms-[a-f0-9]{16}\.(jpg|jpeg|png|gif|webp)\z/', $fileName) !== 1 || !is_file($sourcePath)) {
                $this->removeSavedImages($created);
                return ['error' => '選択された画像を確認できませんでした。', 'created' => [], 'warnings' => $warnings];
            }

            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $processed = $processor->process($sourcePath, $extension, $imageDirReal);
            if (!$processed->ok) {
                $this->removeSavedImages($created);
                return ['error' => $processed->error, 'created' => [], 'warnings' => $warnings];
            }
            $warnings = array_merge($warnings, $processed->warnings);

            $targetPath = $imageDirReal . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($targetPath)) {
                $targetHash = hash_file('sha256', $targetPath);
                $sourceHash = hash_file('sha256', $sourcePath);
                $processedHash = hash_file('sha256', $processed->path);
                if ($targetHash !== $sourceHash && $targetHash !== $processedHash) {
                    // A managed image may change when image-processing behavior is corrected.
                    if (is_string($sourceHash) && is_string($processedHash)
                        && $this->replaceExistingImage($processed->path, $targetPath)) {
                        continue;
                    }
                    @unlink($processed->path);
                    $this->removeSavedImages($created);
                    return ['error' => '同じ名前の画像がすでにあります。別の画像を選び直してください。', 'created' => [], 'warnings' => $warnings];
                }
                @unlink($processed->path);
                continue;
            }

            if (!@rename($processed->path, $targetPath) && !@copy($processed->path, $targetPath)) {
                @unlink($processed->path);
                $this->removeSavedImages($created);
                return ['error' => '画像を保存できませんでした。', 'created' => [], 'warnings' => $warnings];
            }
            @unlink($processed->path);
            $created[] = $targetPath;
        }

        return ['error' => '', 'created' => $created, 'warnings' => array_values(array_unique($warnings))];
    }

    private function replaceExistingImage(string $sourcePath, string $targetPath): bool
    {
        try {
            $backupPath = $targetPath . '.tomos-backup-' . bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            return false;
        }

        if (!@rename($targetPath, $backupPath)) {
            return false;
        }
        if (@rename($sourcePath, $targetPath)) {
            @unlink($backupPath);
            return true;
        }

        @rename($backupPath, $targetPath);
        return false;
    }

    /**
     * @param string[] $paths
     */
    private function removeSavedImages(array $paths): void
    {
        foreach ($paths as $path) {
            is_file($path) && @unlink($path);
        }
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

    /**
     * @return array{path:string,error:string}
     */
    private function targetFromRecord(PostUploadTempRecord $record): array
    {
        return $this->targetFromFolderAndFile(
            (string) ($record->meta['folder'] ?? ''),
            (string) ($record->meta['saved_file_name'] ?? '')
        );
    }

    /**
     * @return array{path:string,error:string}
     */
    private function targetFromFolderAndFile(string $folder, string $safeFileName): array
    {
        $contentDir = rtrim($this->contentDir, DIRECTORY_SEPARATOR);
        $contentBase = realpath($contentDir);
        if ($contentBase === false || !is_dir($contentBase)) {
            return ['path' => '', 'error' => 'content/ フォルダが見つかりません。'];
        }

        $targetDir = $contentBase;
        if ($folder !== '') {
            $targetDir .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder);
        }

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return ['path' => '', 'error' => '保存先フォルダを作成できませんでした。'];
        }

        $targetDirReal = realpath($targetDir);
        if ($targetDirReal === false || strpos(rtrim($targetDirReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, rtrim($contentBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
            return ['path' => '', 'error' => '保存先は content/ 配下にしてください。'];
        }

        return [
            'path' => $targetDirReal . DIRECTORY_SEPARATOR . $safeFileName,
            'error' => '',
        ];
    }

    /**
     * @return string[]
     */
    private function rebuildIndexes(string $contentPath): array
    {
        return array_merge(
            $this->rebuildMetadata($contentPath),
            $this->rebuildImageReferences()
        );
    }

    /**
     * @return string[]
     */
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

    /**
     * @return string[]
     */
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

    /**
     * @return string[]
     */
    private function managedImageReferences(string $markdown, string $contentPath): array
    {
        try {
            return $this->imageReferenceIndex()->managedReferencesFromMarkdown($markdown, $contentPath);
        } catch (\Throwable $exception) {
            return [];
        }
    }

    /**
     * @param string[] $imagePaths
     * @return string[]
     */
    private function deleteUnreferencedImages(array $imagePaths): array
    {
        $index = $this->imageReferenceIndex();
        return (new ImageDeletionRetryQueue($this->cacheDir, $index))->attempt(
            $imagePaths,
            $this->freshImageReferenceIndex,
            false
        );
    }

    private function imageReferenceIndex(): ImageReferenceIndex
    {
        return new ImageReferenceIndex(
            $this->contentDir,
            $this->cacheDir,
            $this->frontMatterParser,
            $this->includeDrafts
        );
    }

    /**
     * @param string[] $warnings
     */
    private function hasImageReferenceWarning(array $warnings): bool
    {
        foreach ($warnings as $warning) {
            if (strpos((string) $warning, '画像参照情報') !== false) {
                return true;
            }
        }

        return false;
    }

    private function titleFromMarkdown(string $markdown, string $contentPath): string
    {
        $parsed = $this->frontMatterParser->parse($markdown);
        $metadata = $this->frontMatterParser->buildPageMetadata($parsed['metadata'], $parsed['body'], $contentPath);
        return (string) ($metadata['title'] ?? '');
    }

    private function normalizeFolder(string $folder, array &$errors): string
    {
        $rawFolder = trim($folder);
        if ($rawFolder !== '' && ($rawFolder[0] === '/' || preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:\/\//', $rawFolder) === 1 || preg_match('/\A[A-Za-z]:/', $rawFolder) === 1)) {
            $errors[] = '保存先フォルダに危険なパス指定が含まれています。';
            return '';
        }

        $folder = trim(str_replace('\\', '/', $rawFolder));
        $folder = preg_replace('#/+#', '/', $folder) ?? $folder;
        $folder = trim($folder, '/');
        if ($folder === '') {
            return '';
        }

        if (!$this->isSafePathText($folder) || preg_match('#(^|/)\.\.?(/|$)#', $folder) === 1) {
            $errors[] = '保存先フォルダに危険なパス指定が含まれています。';
            return '';
        }

        $segments = explode('/', $folder);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                $errors[] = '保存先フォルダに危険なパス指定が含まれています。';
                return '';
            }
        }

        return implode('/', $segments);
    }

    private function normalizeFileName(string $fileName, array &$errors): string
    {
        $fileName = $this->normalizeUnicodeNfc($fileName);
        $fileName = trim($this->removeControlCharacters($fileName));
        $fileName = str_replace(['/', '\\'], '-', $fileName);
        if ($fileName === '') {
            $errors[] = 'ファイル名が正しくありません。';
            return '';
        }

        $parts = explode('.', $fileName);
        if (count($parts) < 2) {
            $errors[] = '拡張子 .md / .markdown / .txt のファイルを指定してください。';
            return '';
        }

        $extension = strtolower((string) array_pop($parts));
        $stem = implode('.', $parts);
        if (!in_array($extension, self::ACCEPTED_EXTENSIONS, true)) {
            $errors[] = '投稿できるファイルは .md / .markdown / .txt です。';
            return '';
        }

        foreach ($parts as $part) {
            if (in_array(strtolower($part), self::DANGEROUS_EXTENSION_SEGMENTS, true)) {
                $errors[] = '危険な拡張子を含むファイル名は投稿できません。';
                return '';
            }
        }

        $stem = $this->sanitizeFileNameStem($stem);
        if ($stem === '') {
            $stem = 'untitled-' . date('Ymd-His');
        }

        return $stem . '.md';
    }

    private function findEquivalentExistingFileName(string $targetDir, string $safeFileName): string
    {
        $items = @scandir($targetDir);
        if (!is_array($items)) {
            return '';
        }

        $normalizedCandidate = $this->normalizeUnicodeNfc($safeFileName);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === $safeFileName) {
                continue;
            }

            $path = $targetDir . DIRECTORY_SEPARATOR . $item;
            if (!is_file($path) || is_link($path)) {
                continue;
            }

            if ($this->normalizeUnicodeNfc($item) === $normalizedCandidate) {
                return $item;
            }
        }

        return '';
    }

    private function normalizeUnicodeNfc(string $value): string
    {
        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                return $normalized;
            }
        }

        return strtr($value, self::JAPANESE_NFC_FALLBACK);
    }

    private function sanitizeFileNameStem(string $stem): string
    {
        $stem = $this->removeControlCharacters($stem);
        $stem = str_replace(['/', '\\'], '-', $stem);
        $stem = preg_replace('/[:*?"<>|#%&+=;]+/u', '-', $stem) ?? $stem;
        $stem = preg_replace('/-+/u', '-', $stem) ?? $stem;
        $stem = preg_replace('/\s*-\s*/u', '-', $stem) ?? $stem;

        while (strpos($stem, '..') !== false) {
            $stem = str_replace('..', '.', $stem);
        }

        $stem = trim($stem);
        $stem = trim($stem, ".- \t\n\r\0\x0B");

        return $stem;
    }

    private function removeControlCharacters(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    }

    private function isSafePathText(string $value): bool
    {
        return strpos($value, "\0") === false
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            && strpos($value, ':') === false
            && strpos($value, '\\') === false;
    }

    private function looksBinary(string $content): bool
    {
        if (strpos($content, "\0") !== false) {
            return true;
        }

        return preg_match('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', $content) === 1;
    }

    private function urlFromContentPath(string $contentPath): string
    {
        if ($contentPath === 'index.md') {
            return '/';
        }

        if (substr($contentPath, -9) === '/index.md') {
            return '/' . substr($contentPath, 0, -8);
        }

        return '/' . substr($contentPath, 0, -3);
    }

    private function absolutePublicUrl(string $internalUrl): string
    {
        if ($internalUrl === '') {
            $internalUrl = '/';
        }
        if (strpos($internalUrl, '/') !== 0) {
            $internalUrl = '/' . $internalUrl;
        }

        $siteUrl = (string) ($this->site['url'] ?? '');
        $sitePath = parse_url($siteUrl, PHP_URL_PATH);
        if (is_string($sitePath) && trim($sitePath, '/') !== '') {
            return Security::absoluteUrl($siteUrl, $internalUrl);
        }

        return Security::absoluteUrl($siteUrl, Security::publicUrl($internalUrl, $this->publicBasePath()));
    }

    private function publicBasePath(): string
    {
        $publicBasePath = (string) ($this->site['public_base_path'] ?? '');
        if ($publicBasePath !== '') {
            return $publicBasePath;
        }

        return (string) ($this->site['base_path'] ?? '');
    }

    private function uploadErrorMessage(int $error): string
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
