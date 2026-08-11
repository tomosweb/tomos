<?php

declare(strict_types=1);

namespace Tomos;

foreach ([
    'Security' => 'Security.php',
    'FrontMatterParser' => 'FrontMatterParser.php',
    'PublishedMetadata' => 'PublishedMetadata.php',
    'PostBasicPage' => 'PostBasicPage.php',
    'PostEditableMarkdown' => 'PostEditableMarkdown.php',
    'PostSubmissionPreparer' => 'PostSubmissionPreparer.php',
    'PostUploadTempStore' => 'PostUploadTempStore.php',
] as $dependency => $file) {
    if (!class_exists(__NAMESPACE__ . '\\' . $dependency)) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . $file;
    }
}

final class PostConflictDecision
{
    public bool $ok;
    /** @var string[] */
    public array $errors;
    /** @var string[] */
    public array $warnings;
    public bool $requiresConfirmation;
    public ?string $tempId;
    public ?string $targetPath;
    public string $action;
    public ?PostUploadTempRecord $record;
    public string $contentPath;
    public string $internalUrl;
    public string $absoluteUrl;
    public string $originalFileName;
    public string $savedFileName;
    public string $existingTitle;
    public string $newTitle;
    public string $expiresAt;
    public string $suggestedFileName;
    public string $sourceStatus;
    public string $sourcePath;
    public bool $sourceConflict;
    public bool $destinationChanged;
    public bool $hasRelativeImages;
    public string $currentMarkdown;
    /** @var array<string,mixed> */
    public array $source = [];

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    public function __construct(
        bool $ok,
        array $errors = [],
        array $warnings = [],
        bool $requiresConfirmation = false,
        ?string $tempId = null,
        ?string $targetPath = null,
        string $action = '',
        ?PostUploadTempRecord $record = null
    ) {
        $this->ok = $ok;
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->requiresConfirmation = $requiresConfirmation;
        $this->tempId = $tempId;
        $this->targetPath = $targetPath;
        $this->action = $action;
        $this->record = $record;
        $this->contentPath = '';
        $this->internalUrl = '';
        $this->absoluteUrl = '';
        $this->originalFileName = '';
        $this->savedFileName = '';
        $this->existingTitle = '';
        $this->newTitle = '';
        $this->expiresAt = '';
        $this->suggestedFileName = '';
        $this->sourceStatus = '';
        $this->sourcePath = '';
        $this->sourceConflict = false;
        $this->destinationChanged = false;
        $this->hasRelativeImages = false;
        $this->currentMarkdown = '';
    }
}

final class PostConflictManager
{
    private string $contentDir;
    private array $site;
    private FrontMatterParser $frontMatterParser;
    private PostUploadTempStore $tempStore;
    private PostEditableMarkdown $editableMarkdown;
    private PostSubmissionPreparer $submissionPreparer;

    public function __construct(
        string $contentDir,
        array $site,
        FrontMatterParser $frontMatterParser,
        PostUploadTempStore $tempStore,
        PostEditableMarkdown $editableMarkdown,
        PostSubmissionPreparer $submissionPreparer
    ) {
        $this->contentDir = $contentDir;
        $this->site = $site;
        $this->frontMatterParser = $frontMatterParser;
        $this->tempStore = $tempStore;
        $this->editableMarkdown = $editableMarkdown;
        $this->submissionPreparer = $submissionPreparer;
    }

    public function inspectNew(
        string $targetPath,
        string $contentPath,
        string $content,
        string $folder,
        string $chosenName,
        string $safeFileName,
        array $imagePlan,
        array $warnings,
        ?string $sessionId,
        string $submissionId
    ): PostConflictDecision {
        if (!file_exists($targetPath)) {
            $decision = new PostConflictDecision(true, [], $warnings, false, null, $targetPath, 'create');
            $decision->contentPath = $contentPath;
            $decision->originalFileName = $chosenName;
            $decision->savedFileName = $safeFileName;
            return $decision;
        }

        $existingMarkdown = @file_get_contents($targetPath);
        if ($existingMarkdown === false) {
            return new PostConflictDecision(false, ['同じ保存先にページがありますが、現在の内容を確認できませんでした。']);
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
            return new PostConflictDecision(false, ['確認用の一時ファイルを保存できませんでした。時間をおいて再度投稿してください。']);
        }

        $decision = new PostConflictDecision(false, [], $warnings, true, $tempRecord->id, $targetPath, 'conflict', $tempRecord);
        $decision->contentPath = $contentPath;
        $decision->internalUrl = $internalUrl;
        $decision->absoluteUrl = $absoluteUrl;
        $decision->originalFileName = $chosenName;
        $decision->savedFileName = $safeFileName;
        $decision->existingTitle = (string) ($tempRecord->meta['existing_title'] ?? '');
        $decision->newTitle = (string) ($tempRecord->meta['new_title'] ?? '');
        $decision->expiresAt = date('Y-m-d H:i', (int) ($tempRecord->meta['expires_at'] ?? 0));
        $decision->suggestedFileName = $this->suggestedRenamedFileName($tempRecord);
        return $decision;
    }

    public function prepareEditableConfirmation(
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
    ): PostConflictDecision {
        $sourcePath = (string) ($editable['source_path'] ?? '');
        $sourceStatus = (string) ($editable['source_status'] ?? '');
        $destinationChanged = !hash_equals($sourcePath, $contentPath);
        $sourceExists = !empty($editable['source_exists']);

        if (!$destinationChanged && !$sourceExists) {
            return new PostConflictDecision(false, ['編集元の原稿が見つかりません。記事管理から原稿を確認し、必要であればもう一度ダウンロードしてください。']);
        }
        if ($destinationChanged && PostBasicPage::isProtectedContentPath($sourcePath)) {
            return new PostConflictDecision(false, ['固定ページの保存先は変更できません。元のファイル名と保存先のまま更新してください。']);
        }
        if ($destinationChanged && file_exists($targetPath)) {
            return new PostConflictDecision(false, ['変更後の保存先には、すでにページがあります。別の保存先を指定してください。']);
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
            return new PostConflictDecision(false, ['確認用の一時ファイルを保存できませんでした。時間をおいて再度投稿してください。']);
        }

        $action = $destinationChanged ? 'editable_new_confirm' : ($sourceConflict ? 'editable_conflict' : 'editable_confirm');
        $decision = new PostConflictDecision(false, [], array_values(array_unique($warnings)), true, $tempRecord->id, $targetPath, $action, $tempRecord);
        $decision->contentPath = $contentPath;
        $decision->internalUrl = $internalUrl;
        $decision->absoluteUrl = $absoluteUrl;
        $decision->originalFileName = $chosenName;
        $decision->savedFileName = $safeFileName;
        $decision->existingTitle = (string) ($tempRecord->meta['existing_title'] ?? '');
        $decision->newTitle = (string) ($tempRecord->meta['new_title'] ?? '');
        $decision->expiresAt = date('Y-m-d H:i', (int) ($tempRecord->meta['expires_at'] ?? 0));
        $decision->sourceStatus = $sourceStatus;
        $decision->sourcePath = $sourcePath;
        $decision->sourceConflict = $sourceConflict;
        $decision->destinationChanged = $destinationChanged;
        $decision->hasRelativeImages = $hasRelativeImages;
        return $decision;
    }

    public function loadForAction(string $tempId, ?string $sessionId, string $submissionId, string $kind = ''): PostConflictDecision
    {
        $record = $this->tempStore->load($tempId, $sessionId);
        if ($record === null || ($kind !== '' && (string) ($record->meta['upload_kind'] ?? '') !== $kind)) {
            return new PostConflictDecision(false, ['確認用の一時ファイルが見つからないか、有効期限が切れました。もう一度投稿してください。']);
        }
        if (!$this->submissionMatches($record, $submissionId)) {
            return new PostConflictDecision(false, ['投稿の送信情報と確認用データを照合できませんでした。もう一度投稿してください。']);
        }
        $decision = new PostConflictDecision(true, [], [], false, $tempId, null, 'loaded', $record);
        return $decision;
    }

    public function validateExistingUpdate(PostUploadTempRecord $record): PostConflictDecision
    {
        $target = $this->targetFromRecord($record);
        if ($target['error'] !== '') {
            $this->delete($record->id);
            return new PostConflictDecision(false, [$target['error']]);
        }
        if (!is_file($target['path'])) {
            $this->delete($record->id);
            return new PostConflictDecision(false, ['更新対象のファイルが見つかりません。投稿画面からやり直してください。']);
        }
        $current = @file_get_contents($target['path']);
        if ($current === false) {
            return new PostConflictDecision(false, ['更新対象の現在の内容を確認できませんでした。']);
        }
        if (!hash_equals((string) ($record->meta['existing_hash'] ?? ''), hash('sha256', $current))) {
            $this->delete($record->id);
            return new PostConflictDecision(false, ['確認後に更新対象の内容が変わりました。もう一度投稿内容を確認してください。']);
        }
        $decision = new PostConflictDecision(true, [], [], false, $record->id, $target['path'], 'update', $record);
        $decision->currentMarkdown = $current;
        return $decision;
    }

    public function validateEditableUpdate(PostUploadTempRecord $record, string $mode, bool $allowConflict): PostConflictDecision
    {
        $sourceStatus = (string) ($record->meta['source_status'] ?? '');
        $allowedModes = $sourceStatus === 'draft' ? ['draft', 'publish'] : ['published'];
        if (!in_array($mode, $allowedModes, true)) {
            return new PostConflictDecision(false, ['更新方法を確認できませんでした。もう一度投稿してください。']);
        }
        if (!empty($record->meta['source_conflict']) && !$allowConflict) {
            return new PostConflictDecision(false, ['競合している原稿は、上書きを明示した場合だけ更新できます。']);
        }

        $sourcePath = (string) ($record->meta['source_path'] ?? '');
        $source = $this->editableMarkdown->readSource($sourcePath);
        if (empty($source['ok']) || empty($source['exists'])) {
            $this->delete($record->id);
            return new PostConflictDecision(false, ['編集元の原稿が見つかりません。記事管理から原稿を確認し、必要であればもう一度ダウンロードしてください。']);
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
            $this->delete($record->id);
            return new PostConflictDecision(false, ['確認後に更新対象の内容が変わりました。もう一度投稿内容を確認してください。']);
        }

        $target = $this->targetFromRecord($record);
        if ($target['error'] !== '') {
            $this->delete($record->id);
            return new PostConflictDecision(false, [$target['error']]);
        }
        if (realpath($target['path']) !== (string) ($source['file'] ?? '')) {
            $this->delete($record->id);
            return new PostConflictDecision(false, ['編集元と更新先を安全に照合できませんでした。']);
        }
        $decision = new PostConflictDecision(true, [], [], false, $record->id, $target['path'], 'editable_update', $record);
        $decision->sourceStatus = $sourceStatus;
        $decision->source = $source;
        return $decision;
    }

    public function validateEditableNew(PostUploadTempRecord $record): PostConflictDecision
    {
        $target = $this->targetFromRecord($record);
        if ($target['error'] !== '') {
            $this->delete($record->id);
            return new PostConflictDecision(false, [$target['error']]);
        }
        if (file_exists($target['path'])) {
            return new PostConflictDecision(false, ['変更後の保存先には、すでにページがあります。別の保存先を指定してください。']);
        }
        return new PostConflictDecision(true, [], [], false, $record->id, $target['path'], 'editable_new', $record);
    }

    public function prepareRename(PostUploadTempRecord $record, string $fileNameInput): PostConflictDecision
    {
        if (PostBasicPage::isProtectedContentPath((string) ($record->meta['content_path'] ?? ''))) {
            return new PostConflictDecision(false, ['トップページとAboutページは別名で投稿できません。更新するか、投稿をやめてください。']);
        }

        $errors = [];
        $safeFileName = $this->submissionPreparer->normalizeFileName($fileNameInput, $errors);
        if ($errors !== []) {
            $decision = new PostConflictDecision(false, $errors);
            $decision->originalFileName = (string) ($record->meta['original_file_name'] ?? '');
            $decision->savedFileName = $safeFileName;
            return $decision;
        }

        $folder = (string) ($record->meta['folder'] ?? '');
        $contentPath = ($folder === '' ? '' : $folder . '/') . $safeFileName;
        $target = $this->targetFromFolderAndFile($folder, $safeFileName);
        if ($target['error'] !== '') {
            return new PostConflictDecision(false, [$target['error']]);
        }
        if (file_exists($target['path'])) {
            return new PostConflictDecision(false, ['このファイル名もすでに使われています。別のファイル名を指定してください。']);
        }
        $decision = new PostConflictDecision(true, [], [], false, $record->id, $target['path'], 'rename_create', $record);
        $decision->contentPath = $contentPath;
        $decision->savedFileName = $safeFileName;
        return $decision;
    }

    public function loadTemp(string $tempId, ?string $sessionId = null): ?PostUploadTempRecord
    {
        return $this->tempStore->load($tempId, $sessionId);
    }

    public function delete(string $tempId): void
    {
        $this->tempStore->delete($tempId);
    }

    public function cancel(string $tempId, ?string $sessionId = null): bool
    {
        if ($this->tempStore->load($tempId, $sessionId) === null) {
            return false;
        }
        $this->tempStore->delete($tempId);
        return true;
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

    public function equivalentExistingFileName(string $targetDir, string $safeFileName): string
    {
        $items = @scandir($targetDir);
        if (!is_array($items)) {
            return '';
        }

        $normalizedCandidate = $this->submissionPreparer->normalizeUnicodeNfc($safeFileName);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === $safeFileName) {
                continue;
            }
            $path = $targetDir . DIRECTORY_SEPARATOR . $item;
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            if ($this->submissionPreparer->normalizeUnicodeNfc($item) === $normalizedCandidate) {
                return $item;
            }
        }
        return '';
    }

    /** @return array{path:string,error:string} */
    private function targetFromRecord(PostUploadTempRecord $record): array
    {
        return $this->targetFromFolderAndFile(
            (string) ($record->meta['folder'] ?? ''),
            (string) ($record->meta['saved_file_name'] ?? '')
        );
    }

    /** @return array{path:string,error:string} */
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
        return ['path' => $targetDirReal . DIRECTORY_SEPARATOR . $safeFileName, 'error' => ''];
    }

    private function submissionHash(string $submissionId): string
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $submissionId) === 1 ? hash('sha256', $submissionId) : '';
    }

    private function submissionMatches(PostUploadTempRecord $record, string $submissionId): bool
    {
        $expected = (string) ($record->meta['submission_hash'] ?? '');
        if ($expected === '') {
            return true;
        }
        $actual = $this->submissionHash($submissionId);
        return $actual !== '' && hash_equals($expected, $actual);
    }

    private function titleFromMarkdown(string $markdown, string $contentPath): string
    {
        $parsed = $this->frontMatterParser->parse($markdown);
        $metadata = $this->frontMatterParser->buildPageMetadata($parsed['metadata'], $parsed['body'], $contentPath);
        return (string) ($metadata['title'] ?? '');
    }

    private function hasRelativeImageReferences(string $markdown): bool
    {
        $parsed = $this->frontMatterParser->parse($markdown);
        $body = (string) ($parsed['body'] ?? '');
        return preg_match('/!\[[^\]]*\]\((?!https?:\/\/|\/|data:)[^)]+\)/i', $body) === 1
            || preg_match('/<img\b[^>]+src=["\'](?!https?:\/\/|\/|data:)[^"\']+/i', $body) === 1;
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
        return Security::absoluteUrl($siteUrl, Security::publicUrl($internalUrl, (string) ($this->site['public_base_path'] ?? ($this->site['base_path'] ?? ''))));
    }
}
