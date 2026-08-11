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
    private PostUploadTempStore $tempStore;
    private PostEditableMarkdown $editableMarkdown;
    private PostSubmissionPreparer $submissionPreparer;
    private PostPublisher $publisher;

    public function __construct(array $config, string $rootDir)
    {
        $this->contentDir = (string) (($config['paths']['content_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'content'));
        $this->cacheDir = (string) (($config['paths']['cache_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'cache'));
        $this->site = is_array($config['site'] ?? null) ? $config['site'] : [];
        $this->htmlCacheEnabled = (bool) ($config['features']['html_cache'] ?? false);
        $this->includeDrafts = (bool) ($config['metadata']['include_drafts'] ?? false);
        $this->frontMatterParser = new FrontMatterParser();
        $this->tempStore = new PostUploadTempStore($this->cacheDir);
        $this->editableMarkdown = new PostEditableMarkdown($config, $rootDir);
        $this->submissionPreparer = new PostSubmissionPreparer($this->editableMarkdown);
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

        $prepared = $this->submissionPreparer->prepare(
            $input->content,
            $input->originalFileName,
            $folderInput,
            $fileNameInput
        );
        $errors = array_merge($input->errors, $prepared->errors);
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
            $equivalentFileName = $this->findEquivalentExistingFileName($targetDirReal, $safeFileName);
            if ($equivalentFileName !== '') {
                $safeFileName = $equivalentFileName;
                $targetPath = $targetDirReal . DIRECTORY_SEPARATOR . $safeFileName;
            }
        }

        $contentPath = ($folder === '' ? '' : $folder . '/') . $safeFileName;
        if ($isEditable) {
            return $this->prepareEditableConfirmation(
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
            );
        }

        if (file_exists($targetPath)) {
            $existingMarkdown = @file_get_contents($targetPath);
            if ($existingMarkdown === false) {
                return new PostUploadResult(false, ['同じ保存先にページがありますが、現在の内容を確認できませんでした。']);
            }

            $internalUrl = $this->urlFromContentPath($contentPath);
            $absoluteUrl = $this->absolutePublicUrl($internalUrl);
            $tempRecord = $this->tempStore->create($content, [
                'session_id' => $sessionId ?? '',
                'submission_hash' => $this->submissionHash($submissionId),
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
        $record = $this->tempStore->load($tempId, $sessionId);
        if ($record === null) {
            return new PostUploadResult(false, ['確認用の一時ファイルが見つからないか、有効期限が切れました。もう一度投稿してください。']);
        }
        if (!$this->submissionMatches($record, $submissionId)) {
            return new PostUploadResult(false, ['投稿の送信情報と確認用データを照合できませんでした。もう一度投稿してください。']);
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

        $publish = $this->publisher->updateExisting(
            $targetPath,
            $record->markdown,
            $record->imagePaths,
            (string) ($record->meta['folder'] ?? '')
        );
        if (!$publish->ok) {
            return new PostUploadResult(false, $publish->errors);
        }

        $this->tempStore->delete($tempId);

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
        $record = $this->tempStore->load($tempId, $sessionId);
        if ($record === null || (string) ($record->meta['upload_kind'] ?? '') !== 'editable_update') {
            return new PostUploadResult(false, ['確認用の一時ファイルが見つからないか、有効期限が切れました。もう一度投稿してください。']);
        }
        if (!$this->submissionMatches($record, $submissionId)) {
            return new PostUploadResult(false, ['投稿の送信情報と確認用データを照合できませんでした。もう一度投稿してください。']);
        }

        $sourceStatus = (string) ($record->meta['source_status'] ?? '');
        $allowedModes = $sourceStatus === 'draft' ? ['draft', 'publish'] : ['published'];
        if (!in_array($mode, $allowedModes, true)) {
            return new PostUploadResult(false, ['更新方法を確認できませんでした。もう一度投稿してください。']);
        }
        if (!empty($record->meta['source_conflict']) && !$allowConflict) {
            return new PostUploadResult(false, ['競合している原稿は、上書きを明示した場合だけ更新できます。']);
        }

        $sourcePath = (string) ($record->meta['source_path'] ?? '');
        $source = $this->editableMarkdown->readSource($sourcePath);
        if (empty($source['ok']) || empty($source['exists'])) {
            $this->tempStore->delete($tempId);
            return new PostUploadResult(false, [
                '編集元の原稿が見つかりません。記事管理から原稿を確認し、必要であればもう一度ダウンロードしてください。',
            ]);
        }

        $currentHash = (string) ($source['hash'] ?? '');
        $expectedHash = (string) ($record->meta['existing_hash'] ?? '');
        $currentStatus = (string) ($source['status'] ?? '');
        $expectedStatus = (string) ($record->meta['current_status'] ?? '');
        if (
            $expectedHash === ''
            || !hash_equals($expectedHash, $currentHash)
            || $expectedStatus === ''
            || !hash_equals($expectedStatus, $currentStatus)
        ) {
            $this->tempStore->delete($tempId);
            return new PostUploadResult(false, ['確認後に更新対象の内容が変わりました。もう一度投稿内容を確認してください。']);
        }

        $target = $this->targetFromRecord($record);
        if ($target['error'] !== '') {
            $this->tempStore->delete($tempId);
            return new PostUploadResult(false, [$target['error']]);
        }
        if (realpath($target['path']) !== (string) ($source['file'] ?? '')) {
            $this->tempStore->delete($tempId);
            return new PostUploadResult(false, ['編集元と更新先を安全に照合できませんでした。']);
        }

        $draft = $mode === 'draft';
        $markdown = $this->editableMarkdown->applyDraftState($record->markdown, $draft);
        if ($markdown === null) {
            return new PostUploadResult(false, ['Front Matterの公開状態を安全に更新できませんでした。']);
        }
        if (!$draft && $sourceStatus === 'draft') {
            $markdown = PublishedMetadata::addIfMissing($markdown, $this->publishedNow());
        }

        $publish = $this->publisher->updateExisting(
            $target['path'],
            $markdown,
            $record->imagePaths,
            (string) ($record->meta['folder'] ?? '')
        );
        if (!$publish->ok) {
            return new PostUploadResult(false, $publish->errors);
        }

        $this->tempStore->delete($tempId);
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
        $record = $this->tempStore->load($tempId, $sessionId);
        if ($record === null || (string) ($record->meta['upload_kind'] ?? '') !== 'editable_new') {
            return new PostUploadResult(false, ['確認用の一時ファイルが見つからないか、有効期限が切れました。もう一度投稿してください。']);
        }
        if (!$this->submissionMatches($record, $submissionId)) {
            return new PostUploadResult(false, ['投稿の送信情報と確認用データを照合できませんでした。もう一度投稿してください。']);
        }

        $target = $this->targetFromRecord($record);
        if ($target['error'] !== '') {
            $this->tempStore->delete($tempId);
            return new PostUploadResult(false, [$target['error']]);
        }
        if (file_exists($target['path'])) {
            return new PostUploadResult(false, ['変更後の保存先には、すでにページがあります。別の保存先を指定してください。']);
        }

        $markdown = $this->editableMarkdown->applyDraftState($record->markdown, false);
        if ($markdown === null) {
            return new PostUploadResult(false, ['Front Matterの公開状態を安全に更新できませんでした。']);
        }
        $markdown = PublishedMetadata::addIfMissing($markdown, $this->publishedNow());

        $folder = (string) ($record->meta['folder'] ?? '');
        $publish = $this->publisher->publishNew($target['path'], $markdown, $record->imagePaths, $folder, false);
        if (!$publish->ok) {
            return new PostUploadResult(false, $publish->errors);
        }

        $this->tempStore->delete($tempId);
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
        $record = $this->tempStore->load($tempId, $sessionId);
        if ($record === null) {
            return new PostUploadResult(false, ['確認用の一時ファイルが見つからないか、有効期限が切れました。もう一度投稿してください。']);
        }
        if (!$this->submissionMatches($record, $submissionId)) {
            return new PostUploadResult(false, ['投稿の送信情報と確認用データを照合できませんでした。もう一度投稿してください。']);
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

        $publish = $this->publisher->publishNew($target['path'], $record->markdown, $record->imagePaths, $folder);
        if (!$publish->ok) {
            return new PostUploadResult(false, $publish->errors);
        }

        $this->tempStore->delete($tempId);

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

    private function prepareEditableConfirmation(
        array $editable,
        string $markdown,
        string $contentPath,
        string $folder,
        string $chosenName,
        string $safeFileName,
        string $targetPath,
        array $imagePlan,
        array $warnings,
        ?string $sessionId,
        string $submissionId
    ): PostUploadResult {
        $sourcePath = (string) ($editable['source_path'] ?? '');
        $sourceStatus = (string) ($editable['source_status'] ?? '');
        $destinationChanged = !hash_equals($sourcePath, $contentPath);
        $sourceExists = !empty($editable['source_exists']);

        if (!$destinationChanged && !$sourceExists) {
            return new PostUploadResult(false, [
                '編集元の原稿が見つかりません。記事管理から原稿を確認し、必要であればもう一度ダウンロードしてください。',
            ]);
        }
        if ($destinationChanged && PostBasicPage::isProtectedContentPath($sourcePath)) {
            return new PostUploadResult(false, ['固定ページの保存先は変更できません。元のファイル名と保存先のまま更新してください。']);
        }
        if ($destinationChanged && file_exists($targetPath)) {
            return new PostUploadResult(false, ['変更後の保存先には、すでにページがあります。別の保存先を指定してください。']);
        }

        $currentMarkdown = (string) ($editable['current_markdown'] ?? '');
        $currentHash = (string) ($editable['current_hash'] ?? '');
        $currentStatus = (string) ($editable['current_status'] ?? '');
        $sourceConflict = !$destinationChanged && (
            $currentHash === ''
            || !hash_equals((string) ($editable['source_hash'] ?? ''), $currentHash)
            || !hash_equals($sourceStatus, $currentStatus)
        );

        $internalUrl = $this->urlFromContentPath($contentPath);
        $absoluteUrl = $this->absolutePublicUrl($internalUrl);
        $hasRelativeImages = $this->hasRelativeImageReferences($markdown);
        if ($destinationChanged) {
            $warnings[] = '保存先が変更されています。元の原稿は残したまま、新しい原稿として投稿します。';
            if ($hasRelativeImages) {
                $warnings[] = '保存先を変更すると、既存画像の相対パスが参照できなくなる場合があります。元の原稿と画像は変更されません。';
            }
        }

        $tempRecord = $this->tempStore->create($markdown, [
            'session_id' => $sessionId ?? '',
            'submission_hash' => $this->submissionHash($submissionId),
            'upload_kind' => $destinationChanged ? 'editable_new' : 'editable_update',
            'folder' => $folder,
            'original_file_name' => $chosenName,
            'planned_file_name' => $safeFileName,
            'saved_file_name' => $safeFileName,
            'content_path' => $contentPath,
            'internal_url' => $internalUrl,
            'absolute_url' => $absoluteUrl,
            'source_path' => $sourcePath,
            'source_status' => $sourceStatus,
            'source_download_hash' => (string) ($editable['source_hash'] ?? ''),
            'source_conflict' => $sourceConflict,
            'current_status' => $currentStatus,
            'existing_hash' => $currentHash,
            'existing_title' => $sourceExists ? $this->titleFromMarkdown($currentMarkdown, $sourcePath) : '',
            'new_title' => $this->titleFromMarkdown($markdown, $contentPath),
            'destination_changed' => $destinationChanged,
            'has_relative_images' => $hasRelativeImages,
            'image_count' => count($imagePlan),
        ], $imagePlan);
        if ($tempRecord === null) {
            return new PostUploadResult(false, ['確認用の一時ファイルを保存できませんでした。時間をおいて再度投稿してください。']);
        }

        $result = new PostUploadResult(
            false,
            [],
            array_values(array_unique($warnings)),
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
            $destinationChanged ? 'editable_new_confirm' : ($sourceConflict ? 'editable_conflict' : 'editable_confirm'),
            '',
            count($imagePlan)
        );
        $result->sourceStatus = $sourceStatus;
        $result->sourcePath = $sourcePath;
        $result->sourceConflict = $sourceConflict;
        $result->destinationChanged = $destinationChanged;
        $result->hasRelativeImages = $hasRelativeImages;

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

    private function hasRelativeImageReferences(string $markdown): bool
    {
        return preg_match(
            '/!\[[^\]\n]*\]\((?![A-Za-z][A-Za-z0-9+.-]*:|\/|#)[^)]+\)/u',
            $markdown
        ) === 1;
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
        return $this->submissionPreparer->normalizeUnicodeNfc($value);
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

    private function submissionHash(string $submissionId): string
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $submissionId) === 1
            ? hash('sha256', $submissionId)
            : '';
    }

    private function submissionMatches(PostUploadTempRecord $record, string $submissionId): bool
    {
        $expected = (string) ($record->meta['submission_hash'] ?? '');
        if ($expected === '') {
            // Temporary records created immediately before this update remain usable.
            return true;
        }
        $actual = $this->submissionHash($submissionId);
        return $actual !== '' && hash_equals($expected, $actual);
    }

}
