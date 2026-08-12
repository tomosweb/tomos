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
    'PostEditableMarkdown' => 'PostEditableMarkdown.php',
    'PostUploadInput' => 'PostUploadInput.php',
    'PostSubmissionPreparer' => 'PostSubmissionPreparer.php',
    'PostPublisher' => 'PostPublisher.php',
    'PostConflictManager' => 'PostConflictManager.php',
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
    public string $sourceStatus = '';
    public string $sourcePath = '';
    public bool $sourceConflict = false;
    public bool $destinationChanged = false;
    public bool $hasRelativeImages = false;

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
    private const MAX_IMAGE_BYTES = 10485760;
    private const MAX_IMAGE_COUNT = 5;
    /** @var array<string,string> */
    private const ACCEPTED_IMAGE_EXTENSIONS = [
        'jpg' => 'jpg',
        'jpeg' => 'jpg',
        'png' => 'png',
        'gif' => 'gif',
        'webp' => 'webp',
    ];
    /** @var string[] */

    private string $contentDir;
    private string $cacheDir;
    private array $site;
    private bool $htmlCacheEnabled;
    private bool $includeDrafts;
    private FrontMatterParser $frontMatterParser;
    private PostEditableMarkdown $editableMarkdown;
    private PostSubmissionPreparer $submissionPreparer;
    private PostPublisher $publisher;
    private PostConflictManager $conflictManager;

    public function __construct(array $config, string $rootDir)
    {
        $this->contentDir = (string) (($config['paths']['content_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'content'));
        $this->cacheDir = (string) (($config['paths']['cache_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'cache'));
        $this->site = is_array($config['site'] ?? null) ? $config['site'] : [];
        $this->htmlCacheEnabled = (bool) ($config['features']['html_cache'] ?? false);
        $this->includeDrafts = (bool) ($config['metadata']['include_drafts'] ?? false);
        $this->frontMatterParser = new FrontMatterParser();
        $tempStore = new PostUploadTempStore($this->cacheDir);
        $this->editableMarkdown = new PostEditableMarkdown($config, $rootDir);
        $this->submissionPreparer = new PostSubmissionPreparer($this->editableMarkdown);
        $this->conflictManager = new PostConflictManager(
            $this->contentDir,
            $this->site,
            $this->frontMatterParser,
            $tempStore,
            $this->editableMarkdown,
            $this->submissionPreparer
        );
        $this->publisher = new PostPublisher(
            $this->contentDir,
            $this->cacheDir,
            $this->frontMatterParser,
            $this->htmlCacheEnabled,
            $this->includeDrafts,
            $this->site
        );
    }

    public function handle(array $file, string $folderInput, string $fileNameInput, ?string $sessionId = null, array $imageFiles = [], array $omittedImages = [], bool $trustedStagedImages = false, string $submissionId = ''): PostUploadResult
    {
        $input = PostUploadInput::read($file);
        if (!$input->canContinue) {
            return new PostUploadResult(false, $input->errors);
        }

        return $this->handlePreparedContent(
            $input->content,
            $input->originalFileName,
            $folderInput,
            $fileNameInput,
            $sessionId,
            $imageFiles,
            $omittedImages,
            $trustedStagedImages,
            $submissionId
        );
    }

    public function handleContent(
        string $content,
        string $originalFileName,
        string $folderInput = '',
        string $fileNameInput = '',
        ?string $sessionId = null,
        array $imageFiles = [],
        array $omittedImages = [],
        bool $trustedStagedImages = false,
        string $submissionId = ''
    ): PostUploadResult {
        return $this->handlePreparedContent(
            $content,
            $originalFileName,
            $folderInput,
            $fileNameInput,
            $sessionId,
            $imageFiles,
            $omittedImages,
            $trustedStagedImages,
            $submissionId
        );
    }

    private function handlePreparedContent(
        string $content,
        string $originalFileName,
        string $folderInput,
        string $fileNameInput,
        ?string $sessionId,
        array $imageFiles,
        array $omittedImages,
        bool $trustedStagedImages,
        string $submissionId
    ): PostUploadResult {

        $prepared = $this->submissionPreparer->prepare(
            $content,
            $originalFileName,
            $folderInput,
            $fileNameInput
        );
        $errors = $prepared->errors;
        $warnings = [];

        if ($errors !== []) {
            return new PostUploadResult(false, $errors);
        }

        $content = $prepared->content;
        $chosenName = $prepared->chosenFileName;
        $safeFileName = $prepared->safeFileName;
        $folder = $prepared->folder;
        $basicPageType = $prepared->basicPageType;
        $isEditable = $prepared->editable;
        $editable = $prepared->editableInfo;

        $content = $this->applyImageOmissions($content, $omittedImages, $errors, $warnings);
        if ($errors !== []) {
            return new PostUploadResult(false, $errors);
        }

        $existingImageReferences = $isEditable
            ? $this->existingEditableImageReferences($editable, $content)
            : [];
        $imagePlan = $this->prepareImages(
            $content,
            $imageFiles,
            $errors,
            $warnings,
            $trustedStagedImages,
            $existingImageReferences
        );
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
            $equivalentFileName = $this->conflictManager->equivalentExistingFileName($targetDirReal, $safeFileName);
            if ($equivalentFileName !== '') {
                $safeFileName = $equivalentFileName;
                $targetPath = $targetDirReal . DIRECTORY_SEPARATOR . $safeFileName;
            }
        }

        $contentPath = ($folder === '' ? '' : $folder . '/') . $safeFileName;
        if ($isEditable) {
            return $this->resultFromConflictDecision($this->conflictManager->prepareEditableConfirmation(
                $editable,
                $content,
                $contentPath,
                $folder,
                $chosenName,
                $safeFileName,
                $targetPath,
                $imagePlan,
                $warnings,
                $sessionId,
                $submissionId
            ));
        }

        $conflict = $this->conflictManager->inspectNew(
            $targetPath,
            $contentPath,
            $content,
            $folder,
            $chosenName,
            $safeFileName,
            $imagePlan,
            $warnings,
            $sessionId,
            $submissionId
        );
        if ($conflict->requiresConfirmation || !$conflict->ok) {
            return $this->resultFromConflictDecision($conflict, count($imagePlan));
        }

        $publish = $this->publisher->publishNew($targetPath, $content, $imagePlan, $folder);
        if (!$publish->ok) {
            return new PostUploadResult(false, $publish->errors);
        }
        $warnings = array_merge($warnings, $publish->warnings);

        $internalUrl = $this->urlFromContentPath($contentPath);
        $absoluteUrl = $this->absolutePublicUrl($internalUrl);

        $warnings = array_merge($warnings, $this->publisher->rebuildIndexes($contentPath));

        return new PostUploadResult(true, [], $warnings, $contentPath, $internalUrl, $absoluteUrl, $chosenName, $safeFileName, false, '', '', '', '', 'create', '', count($imagePlan));
    }

    public function updateFromTemp(string $tempId, ?string $sessionId = null, string $submissionId = ''): PostUploadResult
    {
        $loaded = $this->conflictManager->loadForAction($tempId, $sessionId, $submissionId);
        if (!$loaded->ok || $loaded->record === null) {
            return new PostUploadResult(false, $loaded->errors);
        }
        $record = $loaded->record;
        $validated = $this->conflictManager->validateExistingUpdate($record);
        if (!$validated->ok || $validated->targetPath === null) {
            return new PostUploadResult(false, $validated->errors);
        }
        $targetPath = $validated->targetPath;
        $current = $validated->currentMarkdown;

        $contentPath = (string) ($record->meta['content_path'] ?? '');
        $oldImageRefs = $this->managedImageReferences($current, $contentPath);
        $newImageRefs = $this->managedImageReferences($record->markdown, $contentPath);

        $publish = $this->publisher->updateExisting(
            $targetPath,
            $record->markdown,
            $record->imagePaths,
            (string) ($record->meta['folder'] ?? '')
        );
        if (!$publish->ok) {
            return new PostUploadResult(false, $publish->errors);
        }

        $this->conflictManager->delete($tempId);

        $warnings = array_merge($publish->warnings, $this->publisher->rebuildIndexes($contentPath));
        $warnings = array_merge(
            $warnings,
            $this->publisher->deleteUnreferencedImagesIfSafe(
                array_values(array_diff($oldImageRefs, $newImageRefs)),
                $warnings
            )
        );
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

    public function updateEditableFromTemp(
        string $tempId,
        string $mode,
        bool $allowConflict,
        ?string $sessionId = null,
        string $submissionId = ''
    ): PostUploadResult {
        $loaded = $this->conflictManager->loadForAction($tempId, $sessionId, $submissionId, 'editable_update');
        if (!$loaded->ok || $loaded->record === null) {
            return new PostUploadResult(false, $loaded->errors);
        }
        $record = $loaded->record;
        $validated = $this->conflictManager->validateEditableUpdate($record, $mode, $allowConflict);
        if (!$validated->ok || $validated->targetPath === null) {
            return new PostUploadResult(false, $validated->errors);
        }
        $sourceStatus = $validated->sourceStatus;
        $source = $validated->source;
        $targetPath = $validated->targetPath;

        $draft = $mode === 'draft';
        $markdown = $this->editableMarkdown->applyDraftState($record->markdown, $draft);
        if ($markdown === null) {
            return new PostUploadResult(false, ['Front Matterの公開状態を安全に更新できませんでした。']);
        }
        if (!$draft && $sourceStatus === 'draft') {
            $markdown = PublishedMetadata::addIfMissing($markdown, $this->publishedNow());
        }

        $publish = $this->publisher->updateExisting(
            $targetPath,
            $markdown,
            $record->imagePaths,
            (string) ($record->meta['folder'] ?? '')
        );
        if (!$publish->ok) {
            return new PostUploadResult(false, $publish->errors);
        }

        $this->conflictManager->delete($tempId);
        $contentPath = (string) ($record->meta['content_path'] ?? '');
        $warnings = array_merge($publish->warnings, $this->publisher->rebuildIndexes($contentPath));
        $operation = $mode === 'draft'
            ? 'editable_draft'
            : ($sourceStatus === 'draft' ? 'editable_publish' : 'editable_update');

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
            $this->titleFromMarkdown($markdown, $contentPath),
            '',
            $operation,
            '',
            (int) ($record->meta['image_count'] ?? count($record->imagePaths))
        );
    }

    public function createEditableFromTemp(string $tempId, ?string $sessionId = null, string $submissionId = ''): PostUploadResult
    {
        $loaded = $this->conflictManager->loadForAction($tempId, $sessionId, $submissionId, 'editable_new');
        if (!$loaded->ok || $loaded->record === null) {
            return new PostUploadResult(false, $loaded->errors);
        }
        $record = $loaded->record;
        $validated = $this->conflictManager->validateEditableNew($record);
        if (!$validated->ok || $validated->targetPath === null) {
            return new PostUploadResult(false, $validated->errors);
        }
        $targetPath = $validated->targetPath;

        $markdown = $this->editableMarkdown->applyDraftState($record->markdown, false);
        if ($markdown === null) {
            return new PostUploadResult(false, ['Front Matterの公開状態を安全に更新できませんでした。']);
        }
        $markdown = PublishedMetadata::addIfMissing($markdown, $this->publishedNow());

        $folder = (string) ($record->meta['folder'] ?? '');
        $publish = $this->publisher->publishNew($targetPath, $markdown, $record->imagePaths, $folder, false);
        if (!$publish->ok) {
            return new PostUploadResult(false, $publish->errors);
        }

        $this->conflictManager->delete($tempId);
        $contentPath = (string) ($record->meta['content_path'] ?? '');
        $warnings = array_merge($publish->warnings, $this->publisher->rebuildIndexes($contentPath));

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
            '',
            $this->titleFromMarkdown($markdown, $contentPath),
            '',
            'editable_new',
            '',
            (int) ($record->meta['image_count'] ?? count($record->imagePaths))
        );
    }

    public function createRenamedFromTemp(string $tempId, string $fileNameInput, ?string $sessionId = null, string $submissionId = ''): PostUploadResult
    {
        $loaded = $this->conflictManager->loadForAction($tempId, $sessionId, $submissionId);
        if (!$loaded->ok || $loaded->record === null) {
            return new PostUploadResult(false, $loaded->errors);
        }
        $record = $loaded->record;
        $decision = $this->conflictManager->prepareRename($record, $fileNameInput);
        if (!$decision->ok || $decision->targetPath === null) {
            return new PostUploadResult(false, $decision->errors, [], '', '', '', $decision->originalFileName, $decision->savedFileName);
        }
        $folder = (string) ($record->meta['folder'] ?? '');
        $contentPath = $decision->contentPath;
        $safeFileName = $decision->savedFileName;
        $targetPath = $decision->targetPath;

        $publish = $this->publisher->publishNew($targetPath, $record->markdown, $record->imagePaths, $folder);
        if (!$publish->ok) {
            return new PostUploadResult(false, $publish->errors);
        }

        $this->conflictManager->delete($tempId);

        $internalUrl = $this->urlFromContentPath($contentPath);
        $absoluteUrl = $this->absolutePublicUrl($internalUrl);
        $warnings = array_merge($publish->warnings, $this->publisher->rebuildIndexes($contentPath));

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
        return $this->conflictManager->cancel($tempId, $sessionId);
    }

    public function loadTemp(string $tempId, ?string $sessionId = null): ?PostUploadTempRecord
    {
        return $this->conflictManager->loadTemp($tempId, $sessionId);
    }

    public function suggestedRenamedFileName(PostUploadTempRecord $record): string
    {
        return $this->conflictManager->suggestedRenamedFileName($record);
    }

    private function resultFromConflictDecision(PostConflictDecision $decision, int $imageCount = 0): PostUploadResult
    {
        $result = new PostUploadResult(
            $decision->ok && !$decision->requiresConfirmation,
            $decision->errors,
            $decision->warnings,
            $decision->contentPath,
            $decision->internalUrl,
            $decision->absoluteUrl,
            $decision->originalFileName,
            $decision->savedFileName,
            $decision->requiresConfirmation,
            $decision->tempId ?? '',
            $decision->existingTitle,
            $decision->newTitle,
            $decision->expiresAt,
            $decision->action,
            $decision->suggestedFileName,
            $imageCount
        );
        $result->sourceStatus = $decision->sourceStatus;
        $result->sourcePath = $decision->sourcePath;
        $result->sourceConflict = $decision->sourceConflict;
        $result->destinationChanged = $decision->destinationChanged;
        $result->hasRelativeImages = $decision->hasRelativeImages;
        return $result;
    }

    /**
     * @return array<string,string>
     */
    private function prepareImages(
        string $markdown,
        array $files,
        array &$errors,
        array &$warnings,
        bool $trustedStagedImages = false,
        array $existingReferences = []
    ): array
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
                $errors[] = PostUploadInput::uploadErrorMessage($error);
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
            } elseif (in_array($fileName, $existingReferences, true)) {
                continue;
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

    /**
     * @return string[]
     */
    private function existingEditableImageReferences(array $editable, string $markdown): array
    {
        $references = $this->extractImageReferences((string) ($editable['current_markdown'] ?? ''));
        $sourceFile = (string) ($editable['source_file'] ?? '');
        if ($sourceFile === '' || !is_file($sourceFile)) {
            return $references;
        }

        $contentBase = realpath($this->contentDir);
        if ($contentBase === false) {
            return $references;
        }

        $imageDir = dirname($sourceFile) . DIRECTORY_SEPARATOR . 'images';
        foreach ($this->extractImageReferences($markdown) as $fileName) {
            $candidate = $imageDir . DIRECTORY_SEPARATOR . $fileName;
            if (is_link($candidate)) {
                continue;
            }
            $realPath = realpath($candidate);
            if (
                $realPath !== false
                && is_file($realPath)
                && Security::isPathInside($realPath, $contentBase)
            ) {
                $references[] = $fileName;
            }
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

    private function withInitialPublishedMetadata(string $markdown): string
    {
        return $this->publisher->withInitialPublishedMetadata($markdown);
    }

    private function publishedNow(): string
    {
        $timezoneName = (string) ($this->site['timezone'] ?? 'Asia/Tokyo');
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Throwable $exception) {
            $timezone = new \DateTimeZone('Asia/Tokyo');
        }

        return (new \DateTimeImmutable('now', $timezone))->format('Y-m-d\\TH:i:sP');
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

    private function imageReferenceIndex(): ImageReferenceIndex
    {
        return new ImageReferenceIndex(
            $this->contentDir,
            $this->cacheDir,
            $this->frontMatterParser,
            $this->includeDrafts
        );
    }

    private function titleFromMarkdown(string $markdown, string $contentPath): string
    {
        $parsed = $this->frontMatterParser->parse($markdown);
        $metadata = $this->frontMatterParser->buildPageMetadata($parsed['metadata'], $parsed['body'], $contentPath);
        return (string) ($metadata['title'] ?? '');
    }

    private function normalizeFolder(string $folder, array &$errors): string
    {
        return $this->submissionPreparer->normalizeFolder($folder, $errors);
    }

    private function normalizeFileName(string $fileName, array &$errors): string
    {
        return $this->submissionPreparer->normalizeFileName($fileName, $errors);
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

}
