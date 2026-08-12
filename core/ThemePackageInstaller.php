<?php

declare(strict_types=1);

namespace Tomos;

use Throwable;
use ZipArchive;

final class ThemePackageInstaller
{
    private const CONFIRM_TTL = 1800;
    private const CLEANUP_TTL = 3600;
    private const LOCK_TTL = 600;
    private const REQUEST_OVERHEAD_BYTES = 262144;

    private string $rootDir;
    private string $storageDir;
    private string $themesDir;
    private string $temporaryRoot;
    private string $lockPath;
    private ThemePackagePolicy $policy;

    public function __construct(string $rootDir, ?string $themesDir = null)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->storageDir = $this->rootDir . DIRECTORY_SEPARATOR . 'storage';
        $this->themesDir = rtrim($themesDir ?? ($this->rootDir . DIRECTORY_SEPARATOR . 'themes'), DIRECTORY_SEPARATOR);
        $this->temporaryRoot = $this->storageDir . DIRECTORY_SEPARATOR . 'theme-upload-tmp';
        $this->lockPath = $this->storageDir . DIRECTORY_SEPARATOR . 'theme-upload.lock';
        $this->policy = new ThemePackagePolicy();
    }

    public function diagnostics(): array
    {
        $errors = [];
        if (!class_exists(ZipArchive::class)) {
            $errors[] = 'このサーバーでは、テーマZIPの確認に必要なZIP機能を利用できません。';
        }
        if (!is_dir($this->storageDir) || !is_writable($this->storageDir)) {
            $errors[] = 'テーマZIP用の保存領域に書き込みできません。サーバーの保存領域を確認してください。';
        }
        if (!is_dir($this->themesDir) || !is_writable($this->themesDir)) {
            $errors[] = 'themesフォルダに書き込みできません。サーバーの設定を確認してください。';
        }
        return $errors;
    }

    public function uploadLimit(): array
    {
        $uploadRaw = (string) ini_get('upload_max_filesize');
        $postRaw = (string) ini_get('post_max_size');
        $uploadMax = PostUploadCapabilities::iniBytes($uploadRaw);
        $postMax = PostUploadCapabilities::iniBytes($postRaw);
        $postPayload = $postMax > self::REQUEST_OVERHEAD_BYTES ? $postMax - self::REQUEST_OVERHEAD_BYTES : 0;
        $values = [ThemePackagePolicy::MAX_ZIP_BYTES];
        if ($uploadMax > 0) {
            $values[] = $uploadMax;
        }
        if ($postPayload > 0) {
            $values[] = $postPayload;
        }
        return [
            'bytes' => min($values),
            'settings_known' => $uploadMax > 0 && $postMax > 0,
            'below_tomos_limit' => min($values) < ThemePackagePolicy::MAX_ZIP_BYTES,
        ];
    }

    public function stageUpload(array $upload, string $owner): array
    {
        $diagnostics = $this->diagnostics();
        if ($diagnostics !== []) {
            throw new ThemePackageException($diagnostics[0], 'diagnostics');
        }

        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            if ($error === UPLOAD_ERR_NO_FILE) {
                throw new ThemePackageException('テーマZIPを選択してください。', 'upload');
            }
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new ThemePackageException('テーマZIPの容量がサーバーの上限を超えています。より小さいZIPを選択してください。', 'upload_size');
            }
            throw new ThemePackageException('テーマZIPを受け付けられませんでした。もう一度選択してください。', 'upload');
        }

        $name = (string) ($upload['name'] ?? '');
        $temporaryName = (string) ($upload['tmp_name'] ?? '');
        $size = (int) ($upload['size'] ?? 0);
        if (pathinfo($name, PATHINFO_EXTENSION) !== 'zip') {
            throw new ThemePackageException('ZIP形式のテーマファイルを選択してください。', 'upload_type');
        }
        if ($size < 1) {
            throw new ThemePackageException('テーマZIPを確認できませんでした。Tomos公式サイトからダウンロードしたZIPを選択してください。', 'upload_type');
        }
        if ($size > ThemePackagePolicy::MAX_ZIP_BYTES || $size > (int) $this->uploadLimit()['bytes']) {
            throw new ThemePackageException('テーマZIPの容量が上限を超えています。10 MiB以下のZIPを選択してください。', 'upload_size');
        }
        if (!is_uploaded_file($temporaryName)) {
            throw new ThemePackageException('アップロードされたテーマZIPを確認できませんでした。もう一度選択してください。', 'upload');
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo !== false ? finfo_file($finfo, $temporaryName) : false;
            if (is_string($mime) && !in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
                throw new ThemePackageException('選択されたファイルはZIP形式ではありません。', 'upload_type');
            }
        }

        $this->ensureTemporaryRoot();
        $id = bin2hex(random_bytes(16));
        $workDir = $this->workDir($id);
        if (!@mkdir($workDir, 0700)) {
            throw new ThemePackageException('テーマZIPを一時保存できませんでした。サーバーの保存領域を確認してください。', 'temporary');
        }
        @chmod($workDir, 0700);
        $zipPath = $workDir . DIRECTORY_SEPARATOR . 'package.zip';
        if (!@move_uploaded_file($temporaryName, $zipPath)) {
            $this->removeTree($workDir);
            throw new ThemePackageException('テーマZIPを一時保存できませんでした。サーバーの保存領域を確認してください。', 'temporary');
        }
        @chmod($zipPath, 0600);

        try {
            return $this->inspectPackage($id, $owner, true);
        } catch (Throwable $exception) {
            $removed = $this->removeTree($workDir);
            $this->logFailure('inspect', $id, $exception);
            if (!$removed && $exception instanceof ThemePackageException) {
                throw new ThemePackageException(
                    $exception->getMessage() . ' 一時ファイルを削除できませんでした。サーバーの保存領域を確認してください。',
                    $exception->stage()
                );
            }
            throw $exception;
        }
    }

    public function apply(string $id, string $owner): array
    {
        $lockHandle = null;
        $stagingDir = '';
        $installed = false;
        $themeId = '';
        $result = null;
        $pendingException = null;
        $cleanupWarning = false;

        try {
            $summary = $this->inspectPackage($id, $owner, false);
            $themeId = (string) $summary['theme_id'];
            $this->assertThemeDoesNotExist($themeId);
            $lockHandle = $this->acquireLock($owner);
            $this->assertThemeDoesNotExist($themeId);

            $stagingDir = $this->themesDir . DIRECTORY_SEPARATOR . '.tomos-theme-staging-' . bin2hex(random_bytes(12));
            if (!@mkdir($stagingDir, 0700)) {
                throw new ThemePackageException('テーマを追加する準備ができませんでした。themesフォルダの書き込み権限を確認してください。', 'hidden_staging');
            }
            @chmod($stagingDir, 0700);
            $stagedTheme = $stagingDir . DIRECTORY_SEPARATOR . $themeId;
            $sourceTheme = $this->workDir($id) . DIRECTORY_SEPARATOR . 'extracted' . DIRECTORY_SEPARATOR . $themeId;
            $this->copyTree($sourceTheme, $stagedTheme);
            $this->makeThemeReadable($stagedTheme);
            $stagingValidation = $this->policy->validateExtracted($stagingDir, $themeId);
            $this->verifyRecordedFiles($id, $summary, $stagedTheme);

            $this->assertThemeDoesNotExist($themeId);
            $target = $this->themesDir . DIRECTORY_SEPARATOR . $themeId;
            if (!@rename($stagedTheme, $target)) {
                throw new ThemePackageException('テーマを配置できませんでした。themesフォルダの書き込み権限を確認してください。', 'rename');
            }
            $installed = true;

            $finalValidation = $this->policy->validateExtracted($this->themesDir, $themeId);
            if ($finalValidation['theme_id'] !== $stagingValidation['theme_id']) {
                throw new ThemePackageException('追加後の検証に失敗したため、テーマを追加前の状態へ戻しました。', 'post_validation');
            }

            $result = [
                'theme_id' => $themeId,
                'display_name' => (string) $finalValidation['display_name'],
                'version' => (string) $finalValidation['version'],
                'warnings' => is_array($finalValidation['warnings'] ?? null) ? $finalValidation['warnings'] : [],
                'cleanup_warning' => false,
            ];
        } catch (Throwable $exception) {
            if ($installed && $themeId !== '') {
                $this->removeInstalledTheme($themeId, $stagingDir);
                $exception = new ThemePackageException(
                    '追加後の検証に失敗したため、テーマを追加前の状態へ戻しました。',
                    'post_validation'
                );
            }
            $this->logFailure('apply', $id, $exception);
            $pendingException = $exception;
        } finally {
            if ($stagingDir !== '' && is_dir($stagingDir)) {
                if (!$this->removeTreeWithRetry($stagingDir)) {
                    $cleanupWarning = true;
                    error_log('[Tomos theme upload] hidden staging cleanup failed stage=apply');
                }
            }
            if (!$this->removeTree($this->workDir($id))) {
                $cleanupWarning = true;
                error_log('[Tomos theme upload] temporary cleanup failed stage=apply');
            }
            $this->releaseLock($lockHandle);
        }

        if ($pendingException instanceof Throwable) {
            if ($cleanupWarning && $pendingException instanceof ThemePackageException) {
                throw new ThemePackageException(
                    $pendingException->getMessage() . ' 一時ファイルを削除できませんでした。サーバーの保存領域を確認してください。',
                    $pendingException->stage()
                );
            }
            throw $pendingException;
        }
        if (!is_array($result)) {
            throw new ThemePackageException('テーマを追加できませんでした。もう一度お試しください。', 'unexpected');
        }
        $result['cleanup_warning'] = $cleanupWarning;
        return $result;
    }

    public function discard(string $id): bool
    {
        if (!$this->isPackageId($id)) {
            return true;
        }
        $removed = $this->removeTree($this->workDir($id));
        if (!$removed) {
            error_log('[Tomos theme upload] temporary cleanup failed stage=discard package=' . $id);
        }
        return $removed;
    }

    public function cleanupStaleTemporaryFiles(): void
    {
        if (!is_dir($this->temporaryRoot)) {
            return;
        }
        $items = @scandir($this->temporaryRoot);
        if (!is_array($items)) {
            error_log('[Tomos theme upload] stale cleanup scan failed');
            return;
        }
        foreach ($items as $item) {
            if (!$this->isPackageId($item)) {
                continue;
            }
            $path = $this->workDir($item);
            $modified = @filemtime($path);
            if (is_int($modified) && $modified < time() - self::CLEANUP_TTL && !$this->removeTree($path)) {
                error_log('[Tomos theme upload] stale cleanup failed package=' . $item);
            }
        }
    }

    private function inspectPackage(string $id, string $owner, bool $writeRecord): array
    {
        if (!$this->isPackageId($id)) {
            throw new ThemePackageException('確認内容の有効期限が切れました。テーマZIPを選び直してください。', 'record');
        }
        $workDir = $this->workDir($id);
        $zipPath = $workDir . DIRECTORY_SEPARATOR . 'package.zip';
        $recordPath = $workDir . DIRECTORY_SEPARATOR . 'record.json';
        if (!is_file($zipPath)) {
            throw new ThemePackageException('確認内容の有効期限が切れました。テーマZIPを選び直してください。', 'record');
        }

        $record = null;
        if (!$writeRecord) {
            $record = json_decode((string) @file_get_contents($recordPath), true);
            if (!is_array($record)
                || !isset($record['owner_hash'], $record['expires_at'])
                || !hash_equals((string) $record['owner_hash'], hash('sha256', $owner))
                || (int) $record['expires_at'] < time()
            ) {
                throw new ThemePackageException('確認内容の有効期限が切れました。テーマZIPを選び直してください。', 'record');
            }
            if (!hash_equals((string) ($record['zip_sha256'] ?? ''), (string) @hash_file('sha256', $zipPath))) {
                throw new ThemePackageException('確認済みのテーマZIPを再確認できませんでした。もう一度選択してください。', 'record');
            }
        }

        $this->inspectRawCentralDirectory($zipPath);

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new ThemePackageException('テーマZIPを確認できませんでした。Tomos公式サイトからダウンロードしたZIPを選択してください。', 'zip_open');
        }

        try {
            $inspection = $this->inspectEntries($zip);
            $extractRoot = $workDir . DIRECTORY_SEPARATOR . 'extracted';
            if ($writeRecord) {
                if (is_dir($extractRoot) && !$this->removeTree($extractRoot)) {
                    throw new ThemePackageException('テーマZIPの確認用領域を準備できませんでした。', 'extract');
                }
                $files = $this->extractEntries($zip, $inspection['entries'], $extractRoot);
            } else {
                $files = is_array($record['files'] ?? null) ? $record['files'] : [];
                $this->verifyRecordedFiles($id, $record, $extractRoot . DIRECTORY_SEPARATOR . $inspection['theme_id']);
            }

            $summary = $this->policy->validateExtracted($extractRoot, (string) $inspection['theme_id']);
            $this->assertThemeDoesNotExist((string) $summary['theme_id']);
            $summary['file_count'] = (int) $inspection['file_count'];
            $summary['expanded_bytes'] = (int) $inspection['expanded_bytes'];

            if ($writeRecord) {
                $record = array_merge($summary, [
                    'owner_hash' => hash('sha256', $owner),
                    'created_at' => time(),
                    'expires_at' => time() + self::CONFIRM_TTL,
                    'zip_sha256' => (string) hash_file('sha256', $zipPath),
                    'files' => $files,
                ]);
                $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($json) || @file_put_contents($recordPath, $json, LOCK_EX) === false) {
                    throw new ThemePackageException('テーマZIPの確認結果を保存できませんでした。', 'record');
                }
                @chmod($recordPath, 0600);
            }

            $return = $record;
            if ($writeRecord) {
                $return['package_id'] = $id;
            }
            return $return;
        } finally {
            $zip->close();
        }
    }

    private function inspectEntries(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1) {
            throw new ThemePackageException('テーマZIPの中にファイルがありません。', 'zip_empty');
        }
        if ($zip->numFiles > ThemePackagePolicy::MAX_ENTRIES) {
            throw new ThemePackageException('テーマZIP内のファイル数が上限を超えています。', 'entry_count');
        }

        $entries = [];
        $seen = [];
        $normalizedSeen = [];
        $types = [];
        $roots = [];
        $expanded = 0;
        $fileCount = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if (!is_array($stat) || !isset($stat['name'])) {
                throw new ThemePackageException('テーマZIPの構成を確認できませんでした。', 'entry');
            }
            $rawName = $zip->getNameIndex($index, ZipArchive::FL_UNCHANGED | ZipArchive::FL_ENC_RAW);
            if (!is_string($rawName)) {
                throw new ThemePackageException('テーマZIPのファイルパスを確認できませんでした。', 'path');
            }
            $directory = substr($rawName, -1) === '/';
            $name = $directory ? substr($rawName, 0, -1) : $rawName;
            if ($this->isIgnorableMacMetadataPath($name)) {
                continue;
            }
            $this->validateEntryPath($name, $directory);

            $exactKey = $name;
            $collisionKey = strtolower($name);
            $normalizationKey = $this->normalizationKey($name);
            if (isset($seen[$exactKey]) || isset($normalizedSeen[$collisionKey]) || isset($normalizedSeen[$normalizationKey])) {
                throw new ThemePackageException('テーマZIP内に重複または衝突するファイルパスがあります。', 'path_collision');
            }
            $seen[$exactKey] = true;
            $normalizedSeen[$collisionKey] = true;
            $normalizedSeen[$normalizationKey] = true;

            $segments = explode('/', $name);
            $roots[$segments[0]] = true;
            if (count($segments) === 1 && !$directory) {
                throw new ThemePackageException('テーマZIPの最上位にテーマディレクトリがありません。', 'root_file');
            }
            $depth = $directory ? count($segments) - 1 : count($segments) - 2;
            if ($depth > ThemePackagePolicy::MAX_DIRECTORY_DEPTH) {
                throw new ThemePackageException('テーマZIP内のディレクトリ階層が上限を超えています。', 'depth');
            }

            $this->validateEntryType($zip, $index, $directory);
            $compression = (int) ($stat['comp_method'] ?? ZipArchive::CM_STORE);
            if (!in_array($compression, [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)) {
                throw new ThemePackageException('テーマZIPに利用できない圧縮方式が含まれています。', 'compression');
            }
            if (isset($stat['encryption_method']) && (int) $stat['encryption_method'] !== ZipArchive::EM_NONE) {
                throw new ThemePackageException('暗号化されたテーマZIPは追加できません。', 'encryption');
            }

            $types[$name] = $directory ? 'directory' : 'file';
            foreach ($segments as $position => $unused) {
                if ($position === count($segments) - 1) {
                    break;
                }
                $parent = implode('/', array_slice($segments, 0, $position + 1));
                if (($types[$parent] ?? null) === 'file') {
                    throw new ThemePackageException('テーマZIP内のファイルとディレクトリの構成が矛盾しています。', 'path_collision');
                }
            }

            $size = (int) ($stat['size'] ?? 0);
            if ($size < 0 || (!$directory && $size > ThemePackagePolicy::MAX_FILE_BYTES)) {
                throw new ThemePackageException('テーマZIP内に容量上限を超えるファイルがあります。', 'file_size');
            }
            $expanded += $size;
            if ($expanded > ThemePackagePolicy::MAX_EXPANDED_BYTES) {
                throw new ThemePackageException('テーマZIPを展開した容量が上限を超えています。', 'expanded_size');
            }
            if (!$directory) {
                $fileCount++;
            }
            $entries[] = [
                'index' => $index,
                'name' => $name,
                'zip_name' => $rawName,
                'directory' => $directory,
                'size' => $size,
            ];
        }

        if (count($roots) !== 1) {
            throw new ThemePackageException('テーマZIPの最上位には、テーマディレクトリを1件だけ置いてください。', 'root_count');
        }
        $themeId = (string) array_key_first($roots);
        if (!ThemePackagePolicy::isThemeId($themeId)) {
            throw new ThemePackageException('テーマIDが正しくありません。', 'theme_id');
        }

        foreach ($entries as $entry) {
            $segments = explode('/', (string) $entry['name']);
            array_shift($segments);
            $this->policy->validatePackageRelativePath(implode('/', $segments), !empty($entry['directory']));
        }

        foreach ($types as $path => $type) {
            $segments = explode('/', $path);
            array_pop($segments);
            while ($segments !== []) {
                $parent = implode('/', $segments);
                if (($types[$parent] ?? null) === 'file') {
                    throw new ThemePackageException('テーマZIP内のファイルとディレクトリの構成が矛盾しています。', 'path_collision');
                }
                array_pop($segments);
            }
        }

        return [
            'entries' => $entries,
            'theme_id' => $themeId,
            'file_count' => $fileCount,
            'expanded_bytes' => $expanded,
        ];
    }

    private function inspectRawCentralDirectory(string $zipPath): void
    {
        $raw = @file_get_contents($zipPath);
        if (!is_string($raw) || strlen($raw) < 22) {
            throw new ThemePackageException('テーマZIPを確認できませんでした。Tomos公式サイトからダウンロードしたZIPを選択してください。', 'zip_open');
        }
        $eocd = strrpos($raw, "PK\x05\x06");
        if ($eocd === false || $eocd + 22 > strlen($raw)) {
            throw new ThemePackageException('テーマZIPを確認できませんでした。Tomos公式サイトからダウンロードしたZIPを選択してください。', 'zip_open');
        }
        $disk = $this->uint16($raw, $eocd + 4);
        $centralDisk = $this->uint16($raw, $eocd + 6);
        $diskEntries = $this->uint16($raw, $eocd + 8);
        $totalEntries = $this->uint16($raw, $eocd + 10);
        $centralSize = $this->uint32($raw, $eocd + 12);
        $centralOffset = $this->uint32($raw, $eocd + 16);
        $commentLength = $this->uint16($raw, $eocd + 20);
        if ($totalEntries < 1) {
            throw new ThemePackageException('テーマZIPの中にファイルがありません。', 'zip_empty');
        }
        if ($totalEntries > ThemePackagePolicy::MAX_ENTRIES) {
            throw new ThemePackageException('テーマZIP内のファイル数が上限を超えています。', 'entry_count');
        }
        if ($disk !== 0 || $centralDisk !== 0 || $diskEntries !== $totalEntries
            || $totalEntries === 0xFFFF || $centralSize === 0xFFFFFFFF || $centralOffset === 0xFFFFFFFF
            || $eocd + 22 + $commentLength !== strlen($raw)
            || $centralOffset + $centralSize > $eocd
        ) {
            throw new ThemePackageException('テーマZIPの構成を確認できませんでした。', 'zip_open');
        }

        $offset = $centralOffset;
        $seen = [];
        $normalizedSeen = [];
        for ($index = 0; $index < $totalEntries; $index++) {
            if ($offset + 46 > strlen($raw) || substr($raw, $offset, 4) !== "PK\x01\x02") {
                throw new ThemePackageException('テーマZIPの構成を確認できませんでした。', 'zip_open');
            }
            $nameLength = $this->uint16($raw, $offset + 28);
            $extraLength = $this->uint16($raw, $offset + 30);
            $entryCommentLength = $this->uint16($raw, $offset + 32);
            $recordLength = 46 + $nameLength + $extraLength + $entryCommentLength;
            if ($offset + $recordLength > strlen($raw)) {
                throw new ThemePackageException('テーマZIPの構成を確認できませんでした。', 'zip_open');
            }
            $rawName = substr($raw, $offset + 46, $nameLength);
            $directory = substr($rawName, -1) === '/';
            $name = $directory ? substr($rawName, 0, -1) : $rawName;
            if ($this->isIgnorableMacMetadataPath($name)) {
                $offset += $recordLength;
                continue;
            }
            $this->validateEntryPath($name, $directory);
            $caseKey = strtolower($name);
            $normalizationKey = $this->normalizationKey($name);
            if (isset($seen[$name]) || isset($normalizedSeen[$caseKey]) || isset($normalizedSeen[$normalizationKey])) {
                throw new ThemePackageException('テーマZIP内に重複または衝突するファイルパスがあります。', 'path_collision');
            }
            $seen[$name] = true;
            $normalizedSeen[$caseKey] = true;
            $normalizedSeen[$normalizationKey] = true;
            $offset += $recordLength;
        }
        if ($offset !== $centralOffset + $centralSize) {
            throw new ThemePackageException('テーマZIPの構成を確認できませんでした。', 'zip_open');
        }
    }

    private function uint16(string $raw, int $offset): int
    {
        $value = unpack('vvalue', substr($raw, $offset, 2));
        return is_array($value) ? (int) $value['value'] : -1;
    }

    private function uint32(string $raw, int $offset): int
    {
        $value = unpack('Vvalue', substr($raw, $offset, 4));
        return is_array($value) ? (int) $value['value'] : -1;
    }

    private function isIgnorableMacMetadataPath(string $name): bool
    {
        if ($name === ''
            || strpos($name, "\0") !== false
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || preg_match('//u', $name) !== 1
            || $name[0] === '/'
            || strpos($name, '\\') !== false
            || strpos($name, ':') !== false
            || strpos($name, '//') !== false
        ) {
            return false;
        }

        $segments = explode('/', $name);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        if (($segments[0] ?? '') === '__MACOSX') {
            return true;
        }

        foreach ($segments as $segment) {
            if ($segment === '.DS_Store' || strpos($segment, '._') === 0) {
                return true;
            }
        }

        return false;
    }

    private function validateEntryPath(string $name, bool $directory): void
    {
        if ($name === '' || strpos($name, "\0") !== false || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new ThemePackageException('テーマZIPに安全でないファイルパスが含まれています。', 'path');
        }
        if (preg_match('//u', $name) !== 1 || $name[0] === '/' || strpos($name, '\\') !== false || strpos($name, ':') !== false) {
            throw new ThemePackageException('テーマZIPに安全でないファイルパスが含まれています。', 'path');
        }
        if (strpos($name, '//') !== false) {
            throw new ThemePackageException('テーマZIPに安全でないファイルパスが含まれています。', 'path');
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || $segment[0] === '.') {
                throw new ThemePackageException('テーマZIPに安全でないファイルパスが含まれています。', 'path');
            }
        }
        if ($directory && substr($name, -1) === '/') {
            throw new ThemePackageException('テーマZIPに安全でないファイルパスが含まれています。', 'path');
        }
    }

    private function normalizationKey(string $path): string
    {
        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($path, \Normalizer::FORM_C);
            if (!is_string($normalized)) {
                throw new ThemePackageException('テーマZIPに不正なUnicodeパスが含まれています。', 'unicode');
            }
            return 'n:' . strtolower($normalized);
        }
        if (preg_match('/\p{M}/u', $path) === 1) {
            throw new ThemePackageException('テーマZIPに正規化を確認できないUnicodeパスが含まれています。', 'unicode');
        }
        return 'n:' . strtolower($path);
    }

    private function validateEntryType(ZipArchive $zip, int $index, bool $directory): void
    {
        $operationsSystem = 0;
        $attributes = 0;
        if (!method_exists($zip, 'getExternalAttributesIndex')
            || !$zip->getExternalAttributesIndex($index, $operationsSystem, $attributes, ZipArchive::FL_UNCHANGED)
        ) {
            return;
        }
        if ((int) $operationsSystem !== ZipArchive::OPSYS_UNIX) {
            return;
        }
        $mode = ($attributes >> 16) & 0xFFFF;
        $type = $mode & 0170000;
        $expected = $directory ? 0040000 : 0100000;
        if ($type !== $expected) {
            throw new ThemePackageException('テーマZIPに利用できない種類のファイルが含まれています。', 'entry_type');
        }
        if (!$directory && ($mode & 0111) !== 0) {
            throw new ThemePackageException('テーマZIPに実行可能ファイルが含まれています。', 'entry_type');
        }
    }

    private function extractEntries(ZipArchive $zip, array $entries, string $extractRoot): array
    {
        if (!@mkdir($extractRoot, 0700, true)) {
            throw new ThemePackageException('テーマZIPの確認用領域を作成できませんでした。', 'extract');
        }
        @chmod($extractRoot, 0700);
        $files = [];
        $actualTotal = 0;

        foreach ($entries as $entry) {
            $destination = $extractRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $entry['name']);
            $this->assertWithin($extractRoot, $destination);
            if (!empty($entry['directory'])) {
                if (!is_dir($destination) && !@mkdir($destination, 0700, true)) {
                    throw new ThemePackageException('テーマZIPを安全に展開できませんでした。ZIPを選び直してください。', 'extract');
                }
                @chmod($destination, 0700);
                continue;
            }
            $parent = dirname($destination);
            if (!is_dir($parent) && !@mkdir($parent, 0700, true)) {
                throw new ThemePackageException('テーマZIPを安全に展開できませんでした。ZIPを選び直してください。', 'extract');
            }
            @chmod($parent, 0700);
            $input = $zip->getStream((string) $entry['zip_name']);
            $output = @fopen($destination, 'xb');
            if (!is_resource($input) || !is_resource($output)) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new ThemePackageException('テーマZIPを安全に展開できませんでした。ZIPを選び直してください。', 'extract');
            }
            $fileBytes = 0;
            while (!feof($input)) {
                $chunk = fread($input, 65536);
                if (!is_string($chunk)) {
                    fclose($input);
                    fclose($output);
                    throw new ThemePackageException('テーマZIP内のファイルを読み取れませんでした。', 'extract');
                }
                $length = strlen($chunk);
                $fileBytes += $length;
                $actualTotal += $length;
                if ($fileBytes > ThemePackagePolicy::MAX_FILE_BYTES) {
                    fclose($input);
                    fclose($output);
                    throw new ThemePackageException('テーマZIP内に容量上限を超えるファイルがあります。', 'file_size');
                }
                if ($actualTotal > ThemePackagePolicy::MAX_EXPANDED_BYTES) {
                    fclose($input);
                    fclose($output);
                    throw new ThemePackageException('テーマZIPを展開した容量が上限を超えています。', 'expanded_size');
                }
                if ($length > 0 && fwrite($output, $chunk) !== $length) {
                    fclose($input);
                    fclose($output);
                    throw new ThemePackageException('テーマZIPを安全に展開できませんでした。ZIPを選び直してください。', 'extract');
                }
            }
            fclose($input);
            fclose($output);
            @chmod($destination, 0600);
            if ($fileBytes !== (int) $entry['size']) {
                throw new ThemePackageException('テーマZIP内の容量情報を確認できませんでした。', 'extract');
            }
            $relativeToTheme = implode('/', array_slice(explode('/', (string) $entry['name']), 1));
            $files[$relativeToTheme] = [
                'size' => $fileBytes,
                'sha256' => (string) hash_file('sha256', $destination),
            ];
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private function verifyRecordedFiles(string $id, array $record, string $themeDir): void
    {
        $files = is_array($record['files'] ?? null) ? $record['files'] : [];
        $actualFiles = [];
        if (!is_dir($themeDir) || is_link($themeDir)) {
            throw new ThemePackageException('確認済みのテーマ内容を再確認できませんでした。', 'record');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($themeDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink() || !$entry->isFile()) {
                if ($entry instanceof \SplFileInfo && ($entry->isLink() || !$entry->isDir())) {
                    throw new ThemePackageException('確認済みのテーマ内容を再確認できませんでした。', 'record');
                }
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen(rtrim($themeDir, DIRECTORY_SEPARATOR)) + 1));
            $actualFiles[$relative] = true;
        }
        $expectedFiles = array_fill_keys(array_keys($files), true);
        ksort($actualFiles, SORT_STRING);
        ksort($expectedFiles, SORT_STRING);
        if (array_keys($actualFiles) !== array_keys($expectedFiles)) {
            throw new ThemePackageException('確認済みのテーマ内容が変更されています。テーマZIPを選び直してください。', 'record');
        }
        foreach ($files as $relative => $expected) {
            if (!is_string($relative) || !is_array($expected)) {
                throw new ThemePackageException('確認済みのテーマ内容を再確認できませんでした。', 'record');
            }
            $path = rtrim($themeDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $this->assertWithin($themeDir, $path);
            $hash = is_file($path) ? @hash_file('sha256', $path) : false;
            if (!is_string($hash)
                || !hash_equals((string) ($expected['sha256'] ?? ''), $hash)
                || (int) ($expected['size'] ?? -1) !== (int) @filesize($path)
            ) {
                throw new ThemePackageException('確認済みのテーマ内容が変更されています。テーマZIPを選び直してください。', 'record');
            }
        }
    }

    private function acquireLock(string $owner)
    {
        $handle = @fopen($this->lockPath, 'x');
        if (!is_resource($handle)) {
            $stale = @fopen($this->lockPath, 'r+');
            if (is_resource($stale) && @flock($stale, LOCK_EX | LOCK_NB)) {
                $raw = stream_get_contents($stale);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                $startedAt = is_array($decoded) ? (int) ($decoded['started_at'] ?? 0) : 0;
                if ($startedAt > 0 && $startedAt < time() - self::LOCK_TTL) {
                    @unlink($this->lockPath);
                }
                @flock($stale, LOCK_UN);
                fclose($stale);
                $handle = @fopen($this->lockPath, 'x');
            } elseif (is_resource($stale)) {
                fclose($stale);
            }
        }
        if (!is_resource($handle) || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new ThemePackageException('別のテーマ追加処理が実行中です。しばらく待ってからもう一度お試しください。', 'lock');
        }
        $payload = json_encode([
            'started_at' => time(),
            'owner_hash' => hash('sha256', $owner),
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || fwrite($handle, $payload) === false) {
            @flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($this->lockPath);
            throw new ThemePackageException('テーマ追加用の排他制御を開始できませんでした。', 'lock');
        }
        @chmod($this->lockPath, 0600);
        return $handle;
    }

    private function releaseLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        @unlink($this->lockPath);
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function assertThemeDoesNotExist(string $themeId): void
    {
        if (file_exists($this->themesDir . DIRECTORY_SEPARATOR . $themeId)
            || is_link($this->themesDir . DIRECTORY_SEPARATOR . $themeId)
        ) {
            throw new ThemePackageException('同じテーマIDのテーマがすでに追加されています。別のテーマを選択してください。上書きはできません。', 'duplicate_theme');
        }
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!is_dir($source) || is_link($source) || !@mkdir($destination, 0700)) {
            throw new ThemePackageException('テーマを追加する準備ができませんでした。themesフォルダの書き込み権限を確認してください。', 'hidden_staging');
        }
        @chmod($destination, 0700);
        $items = @scandir($source);
        if (!is_array($items)) {
            throw new ThemePackageException('テーマを追加する準備ができませんでした。', 'hidden_staging');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $source . DIRECTORY_SEPARATOR . $item;
            $to = $destination . DIRECTORY_SEPARATOR . $item;
            if (is_link($from)) {
                throw new ThemePackageException('テーマZIPに利用できない種類のファイルが含まれています。', 'entry_type');
            }
            if (is_dir($from)) {
                $this->copyTree($from, $to);
            } elseif (is_file($from)) {
                if (!@copy($from, $to)) {
                    throw new ThemePackageException('テーマを追加する準備ができませんでした。', 'hidden_staging');
                }
                @chmod($to, 0600);
            } else {
                throw new ThemePackageException('テーマZIPに利用できない種類のファイルが含まれています。', 'entry_type');
            }
        }
    }

    private function removeInstalledTheme(string $themeId, string $stagingDir): void
    {
        $target = $this->themesDir . DIRECTORY_SEPARATOR . $themeId;
        $quarantine = $stagingDir !== '' ? $stagingDir . DIRECTORY_SEPARATOR . $themeId : '';
        if ($quarantine !== '' && is_dir($stagingDir) && @rename($target, $quarantine)) {
            $this->removeTree($quarantine);
            return;
        }
        if (!$this->removeTree($target)) {
            throw new ThemePackageException('追加後の検証に失敗し、自動復元も完了できませんでした。管理者による確認が必要です。', 'rollback');
        }
    }

    private function makeThemeReadable(string $themeDir): void
    {
        @chmod($themeDir, 0755);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($themeDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                throw new ThemePackageException('テーマを追加する準備ができませんでした。', 'hidden_staging');
            }
            if (!@chmod($entry->getPathname(), $entry->isDir() ? 0755 : 0644)) {
                throw new ThemePackageException('テーマを追加する準備ができませんでした。', 'hidden_staging');
            }
        }
    }

    private function ensureTemporaryRoot(): void
    {
        if (!is_dir($this->temporaryRoot) && !@mkdir($this->temporaryRoot, 0700, true)) {
            throw new ThemePackageException('テーマZIPを一時保存できませんでした。サーバーの保存領域を確認してください。', 'temporary');
        }
        @chmod($this->temporaryRoot, 0700);
    }

    private function assertWithin(string $base, string $path): void
    {
        $prefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($path, $prefix) !== 0) {
            throw new ThemePackageException('テーマZIPに安全でないファイルパスが含まれています。', 'path');
        }
    }

    private function workDir(string $id): string
    {
        return $this->temporaryRoot . DIRECTORY_SEPARATOR . $id;
    }

    private function isPackageId(string $id): bool
    {
        return preg_match('/\A[a-f0-9]{32}\z/', $id) === 1;
    }

    private function removeTree(string $path): bool
    {
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        $items = @scandir($path);
        if (!is_array($items)) {
            return false;
        }
        $ok = true;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (!$this->removeTree($path . DIRECTORY_SEPARATOR . $item)) {
                $ok = false;
            }
        }
        return @rmdir($path) && $ok;
    }

    private function removeTreeWithRetry(string $path): bool
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            if ($this->removeTree($path)) {
                return true;
            }
            usleep(20000);
        }
        return false;
    }

    private function logFailure(string $operation, string $id, Throwable $exception): void
    {
        $stage = $exception instanceof ThemePackageException ? $exception->stage() : 'unexpected';
        error_log('[Tomos theme upload] operation=' . $operation . ' stage=' . $stage . ' package=' . $id);
    }
}
