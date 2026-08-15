<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;
use Throwable;
use ZipArchive;

final class UpdateService
{
    public const MAX_ZIP_BYTES = 52428800;
    public const MAX_FILES = 500;
    public const MAX_EXPANDED_BYTES = 104857600;

    private $rootDir;
    private $storageDir;
    private $publicKeyPath;

    public function __construct(string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->storageDir = $this->rootDir . DIRECTORY_SEPARATOR . 'storage';
        $this->publicKeyPath = $this->rootDir . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR . 'public-key.pem';
    }

    public function currentVersion(): string
    {
        $value = @file_get_contents($this->rootDir . DIRECTORY_SEPARATOR . 'VERSION');
        return $value === false ? '' : trim($value);
    }

    public function diagnostics(): array
    {
        $errors = [];
        if (version_compare(PHP_VERSION, '7.4.0', '<')) {
            $errors[] = 'Tomos UpdateにはPHP 7.4以上が必要です。';
        }
        if (!class_exists(ZipArchive::class)) {
            $errors[] = 'このサーバーでは、Tomos Updateに必要なZIP展開機能を利用できません。FTPまたはサーバーのファイル管理機能で更新してください。';
        }
        if (!function_exists('openssl_verify')) {
            $errors[] = 'このサーバーでは、更新ZIPの署名を確認できません。FTPまたはサーバーのファイル管理機能で更新してください。';
        }
        if (!is_file($this->publicKeyPath) || !is_readable($this->publicKeyPath)) {
            $errors[] = 'Tomos Updateの公開鍵を確認できません。管理者へ連絡してください。';
        }
        foreach (['update-tmp', 'update-backups', 'update-logs'] as $directory) {
            $path = $this->storageDir . DIRECTORY_SEPARATOR . $directory;
            if (!is_dir($path) || !is_writable($path)) {
                $errors[] = '更新用の保存領域に書き込みできません。FTPまたはサーバーのファイル管理機能で更新してください。';
                break;
            }
        }
        return $errors;
    }

    public function stageUpload(array $upload, string $owner): array
    {
        $environmentErrors = $this->diagnostics();
        if ($environmentErrors !== []) {
            throw new UpdateException($environmentErrors[0], 'diagnostics');
        }
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new UpdateException('更新ZIPを受け付けられませんでした。ファイルサイズを確認して、もう一度お試しください。', 'upload');
        }
        $name = (string) ($upload['name'] ?? '');
        $tmpName = (string) ($upload['tmp_name'] ?? '');
        $size = (int) ($upload['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_ZIP_BYTES || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            throw new UpdateException('更新ZIPを確認できませんでした。正規のTomos更新ZIPを選択してください。', 'upload');
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo !== false ? finfo_file($finfo, $tmpName) : false;
            if ($finfo !== false) {
                finfo_close($finfo);
            }
            if (is_string($mime) && !in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
                throw new UpdateException('選択されたファイルはZIP形式ではありません。', 'upload');
            }
        }
        if (!is_uploaded_file($tmpName)) {
            throw new UpdateException('アップロードされた更新ZIPを確認できませんでした。', 'upload');
        }

        $id = bin2hex(random_bytes(16));
        $workDir = $this->storageDir . DIRECTORY_SEPARATOR . 'update-tmp' . DIRECTORY_SEPARATOR . $id;
        if (!@mkdir($workDir, 0700, true)) {
            throw new UpdateException('更新ZIPの確認を開始できませんでした。', 'staging');
        }
        $zipPath = $workDir . DIRECTORY_SEPARATOR . 'package.zip';
        if (!@move_uploaded_file($tmpName, $zipPath)) {
            $this->removeTree($workDir);
            throw new UpdateException('更新ZIPを一時保存できませんでした。', 'staging');
        }

        try {
            return $this->inspectStaged($id, $owner, true);
        } catch (Throwable $exception) {
            $this->removeTree($workDir);
            throw $exception;
        }
    }

    public function stageDownloadedPackage(
        string $sourcePath,
        string $owner,
        string $expectedFromVersion,
        string $expectedVersion
    ): array {
        $sourceSize = @filesize($sourcePath);
        if (!is_file($sourcePath) || is_link($sourcePath) || !is_readable($sourcePath)
            || $sourceSize === false || $sourceSize < 1 || $sourceSize > self::MAX_ZIP_BYTES
        ) {
            throw new UpdateException('ダウンロード済み更新ZIPを安全に確認できません。', 'source');
        }
        $environmentErrors = $this->diagnostics();
        if ($environmentErrors !== []) {
            throw new UpdateException($environmentErrors[0], 'diagnostics');
        }

        $id = bin2hex(random_bytes(16));
        $workDir = $this->storageDir . DIRECTORY_SEPARATOR . 'update-tmp' . DIRECTORY_SEPARATOR . $id;
        if (!@mkdir($workDir, 0700, true)) {
            throw new UpdateException('更新ZIPの確認を開始できませんでした。', 'staging');
        }
        $temporaryPath = $workDir . DIRECTORY_SEPARATOR . 'package.download';
        $zipPath = $workDir . DIRECTORY_SEPARATOR . 'package.zip';
        try {
            $this->copyDownloadedPackage($sourcePath, $temporaryPath);
            if (is_file($zipPath) || is_link($zipPath) || !@rename($temporaryPath, $zipPath)) {
                throw new UpdateException('更新ZIPを正式な一時保存先へ移動できませんでした。', 'staging');
            }
            $summary = $this->inspectStaged($id, $owner, true);
            if (($summary['current_version'] ?? null) !== $expectedFromVersion
                || ($summary['from_version'] ?? null) !== $expectedFromVersion
                || ($summary['version'] ?? null) !== $expectedVersion
            ) {
                throw new UpdateException('カタログと署名済み更新ZIPのバージョン経路が一致しません。', 'update_sequence');
            }
            return $summary;
        } catch (Throwable $exception) {
            $this->removeTree($workDir);
            throw $exception;
        }
    }

    public function inspectStaged(string $id, string $owner, bool $writeRecord = false): array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
            throw new UpdateException('更新内容を確認できませんでした。更新ZIPを選び直してください。', 'staging');
        }
        $workDir = $this->storageDir . DIRECTORY_SEPARATOR . 'update-tmp' . DIRECTORY_SEPARATOR . $id;
        $zipPath = $workDir . DIRECTORY_SEPARATOR . 'package.zip';
        $recordPath = $workDir . DIRECTORY_SEPARATOR . 'record.json';
        if (!is_file($zipPath)) {
            throw new UpdateException('更新内容の有効期限が切れました。更新ZIPを選び直してください。', 'staging');
        }
        if (!$writeRecord) {
            $record = json_decode((string) @file_get_contents($recordPath), true);
            if (!is_array($record) || !hash_equals((string) ($record['owner'] ?? ''), hash('sha256', $owner))) {
                throw new UpdateException('更新内容の有効期限が切れました。更新ZIPを選び直してください。', 'staging');
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('更新ZIPを確認できませんでした。正規のTomos更新ZIPを選択してください。', 'zip_open');
        }
        try {
            $entries = $this->inspectEntries($zip);
            foreach (['manifest.json', 'manifest.sig'] as $required) {
                if (!isset($entries[$required])) {
                    throw new UpdateException('更新ZIPに必要なファイルが不足しています。', 'manifest');
                }
            }
            $manifestRaw = $zip->getFromName('manifest.json');
            $signature = $zip->getFromName('manifest.sig');
            if (!is_string($manifestRaw) || !is_string($signature) || $signature === '') {
                throw new UpdateException('更新ZIPの署名情報を確認できませんでした。', 'signature');
            }
            $this->verifySignature($manifestRaw, $signature);
            $manifest = json_decode($manifestRaw, true);
            $this->validateManifest($manifest);
            $files = $manifest['files'];

            $expectedEntries = ['manifest.json' => true, 'manifest.sig' => true];
            foreach ($files as $relative => $hash) {
                $expectedEntries['files/' . $relative] = true;
            }
            foreach ($entries as $entry => $unused) {
                if (substr($entry, -1) === '/') {
                    continue;
                }
                if (!isset($expectedEntries[$entry])) {
                    throw new UpdateException('manifestにないファイルが更新ZIPに含まれています。', 'zip_contents');
                }
            }

            $extractDir = $workDir . DIRECTORY_SEPARATOR . 'extracted';
            if (is_dir($extractDir)) {
                $this->removeTree($extractDir);
            }
            if (!@mkdir($extractDir, 0700, true)) {
                throw new UpdateException('更新ZIPの確認用領域を作成できませんでした。', 'extract');
            }
            foreach (array_keys($expectedEntries) as $entry) {
                if (in_array($entry, ['manifest.json', 'manifest.sig'], true)) {
                    continue;
                }
                $destination = $extractDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
                if (!is_dir(dirname($destination)) && !@mkdir(dirname($destination), 0700, true)) {
                    throw new UpdateException('更新ZIPを安全に展開できませんでした。', 'extract');
                }
                $stream = $zip->getStream($entry);
                if (!is_resource($stream)) {
                    throw new UpdateException('更新ZIP内のファイルを読み取れませんでした。', 'extract');
                }
                $output = @fopen($destination, 'xb');
                if (!is_resource($output)) {
                    fclose($stream);
                    throw new UpdateException('更新ZIPを安全に展開できませんでした。', 'extract');
                }
                stream_copy_to_stream($stream, $output);
                fclose($stream);
                fclose($output);
            }

            $themeFiles = [];
            foreach ($files as $relative => $expectedHash) {
                $source = $extractDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (!is_file($source) || !hash_equals(strtolower($expectedHash), hash_file('sha256', $source))) {
                    throw new UpdateException('更新ZIP内のファイルが改変されているか、不足しています。', 'hash');
                }
                if (strpos($relative, 'themes/') === 0) {
                    $themeFiles[] = $relative;
                }
                $this->assertWritableTarget($relative);
            }
            $versionSource = $extractDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'VERSION';
            if (!is_file($versionSource) || trim((string) file_get_contents($versionSource)) !== $manifest['version']) {
                throw new UpdateException('更新後バージョンとVERSIONの内容が一致しません。', 'version');
            }

            $summary = [
                'id' => $id,
                'owner' => hash('sha256', $owner),
                'current_version' => $this->currentVersion(),
                'from_version' => $manifest['from_version'],
                'version' => $manifest['version'],
                'files' => array_keys($files),
                'theme_files' => $themeFiles,
                'created_at' => gmdate('c'),
            ];
            if (@file_put_contents($recordPath, json_encode($summary, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
                throw new UpdateException('更新内容の確認結果を保存できませんでした。', 'staging');
            }
            return $summary;
        } finally {
            $zip->close();
        }
    }

    public function apply(string $id, string $owner): array
    {
        $summary = $this->inspectStaged($id, $owner);
        $lockPath = UpdateLock::path($this->rootDir);
        if (is_file($lockPath) && !UpdateLock::isActive($this->rootDir)) {
            @unlink($lockPath);
        }
        $lockHandle = @fopen($lockPath, 'x');
        if (!is_resource($lockHandle)) {
            throw new UpdateException('別の更新処理が実行中です。しばらく待ってからお試しください。', 'lock');
        }
        $startedAt = gmdate('c');
        fwrite($lockHandle, json_encode([
            'started_at' => $startedAt,
            'target_version' => $summary['version'],
            'session' => substr(hash('sha256', $owner), 0, 16),
            'state' => 'running',
        ], JSON_UNESCAPED_SLASHES));
        fclose($lockHandle);

        $backupId = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $backupDir = $this->storageDir . DIRECTORY_SEPARATOR . 'update-backups' . DIRECTORY_SEPARATOR . $backupId;
        $updated = [];
        $newFiles = [];
        $createdDirectories = [];
        $originalPermissions = [];
        $rollbackAttempted = false;
        $rollbackSucceeded = false;
        $stage = 'backup';
        try {
            if (!@mkdir($backupDir, 0700, true)) {
                throw new RuntimeException('backup_directory');
            }
            foreach ($summary['files'] as $relative) {
                $target = $this->targetPath($relative);
                if (is_file($target)) {
                    $originalPermissions[$relative] = fileperms($target) & 0777;
                    $backup = $backupDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                    if (!is_dir(dirname($backup)) && !@mkdir(dirname($backup), 0700, true)) {
                        throw new RuntimeException('backup_parent');
                    }
                    if (!@copy($target, $backup)) {
                        throw new RuntimeException('backup_file');
                    }
                } else {
                    $newFiles[] = $relative;
                }
            }
            $stage = 'replace';
            $extractRoot = $this->storageDir . DIRECTORY_SEPARATOR . 'update-tmp' . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'extracted' . DIRECTORY_SEPARATOR . 'files';
            foreach ($summary['files'] as $relative) {
                $source = $extractRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $target = $this->targetPath($relative);
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    $this->makeTargetDirectories($parent, $createdDirectories);
                }
                $temporary = $parent . DIRECTORY_SEPARATOR . '.tomos-update-' . bin2hex(random_bytes(8)) . '.tmp';
                if (!@copy($source, $temporary)) {
                    throw new RuntimeException('temporary_copy');
                }
                $permissions = is_file($target) ? (fileperms($target) & 0777) : 0644;
                @chmod($temporary, $permissions);
                if (strtolower(pathinfo($target, PATHINFO_EXTENSION)) === 'php' && strpos((string) file_get_contents($temporary), '<?php') !== 0) {
                    @unlink($temporary);
                    throw new RuntimeException('php_sanity');
                }
                if (!@rename($temporary, $target)) {
                    @unlink($temporary);
                    throw new RuntimeException('rename');
                }
                $updated[] = $relative;
            }
            $stage = 'verify';
            if ($this->currentVersion() !== $summary['version']) {
                throw new RuntimeException('version_verify');
            }
            $meta = $this->resultMeta($summary, $startedAt, true, 'complete', false, false);
            if (!$this->writeMeta($backupDir, $meta) || !$this->writeLog($meta)) {
                throw new RuntimeException('result_log');
            }
            return [
                'ok' => true,
                'previous_version' => $summary['current_version'],
                'version' => $summary['version'],
                'file_count' => count($summary['files']),
                'backup_id' => $backupId,
            ];
        } catch (Throwable $exception) {
            $rollbackAttempted = true;
            $rollbackSucceeded = $this->rollback($backupDir, $updated, $newFiles, $createdDirectories, $originalPermissions);
            $meta = $this->resultMeta($summary, $startedAt, false, $stage . ':' . $exception->getMessage(), $rollbackAttempted, $rollbackSucceeded);
            $this->writeMeta($backupDir, $meta);
            $this->writeLog($meta);
            if (!$rollbackSucceeded) {
                throw new UpdateException('更新に失敗し、自動復元も完了できませんでした。バックアップは保存されています。管理者による確認が必要です。', $stage, true);
            }
            throw new UpdateException('更新中にエラーが発生しました。更新前の状態へ復元しました。', $stage);
        } finally {
            @unlink($lockPath);
            $this->removeTree($this->storageDir . DIRECTORY_SEPARATOR . 'update-tmp' . DIRECTORY_SEPARATOR . $id);
        }
    }

    public function cleanupStaleTemporaryFiles(): void
    {
        $base = $this->storageDir . DIRECTORY_SEPARATOR . 'update-tmp';
        foreach ((array) glob($base . DIRECTORY_SEPARATOR . '*') as $path) {
            if (is_dir($path) && filemtime($path) !== false && filemtime($path) < time() - 86400) {
                $this->removeTree($path);
            }
        }
    }

    private function inspectEntries(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_FILES) {
            throw new UpdateException('更新ZIP内のファイル数が上限を超えています。', 'zip_limits');
        }
        $entries = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                throw new UpdateException('更新ZIPの内容を確認できませんでした。', 'zip_contents');
            }
            $name = (string) ($stat['name'] ?? '');
            if (!$this->isSafeZipPath($name) || isset($entries[$name])) {
                throw new UpdateException('更新ZIPに安全でないパスまたは重複したパスが含まれています。', 'zip_path');
            }
            $attributes = (int) ($stat['external_attributes'] ?? 0);
            $operationsSystem = 0;
            $externalAttributes = 0;
            if (method_exists($zip, 'getExternalAttributesIndex')
                && $zip->getExternalAttributesIndex($i, $operationsSystem, $externalAttributes)
            ) {
                $attributes = $externalAttributes;
            }
            $mode = ($attributes >> 16) & 0170000;
            if ($mode === 0120000) {
                throw new UpdateException('更新ZIPにシンボリックリンクが含まれています。', 'zip_path');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_EXPANDED_BYTES) {
                throw new UpdateException('更新ZIPの展開後容量が上限を超えています。', 'zip_limits');
            }
            $entries[$name] = true;
        }
        return $entries;
    }

    private function copyDownloadedPackage(string $sourcePath, string $destination): void
    {
        $input = @fopen($sourcePath, 'rb');
        if (!is_resource($input)) {
            throw new UpdateException('ダウンロード済み更新ZIPを読み取れません。', 'source');
        }
        $output = @fopen($destination, 'xb');
        if (!is_resource($output)) {
            fclose($input);
            throw new UpdateException('更新ZIPの正式な一時保存先を作成できません。', 'staging');
        }
        $bytes = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 65536);
                if (!is_string($chunk)) {
                    throw new UpdateException('ダウンロード済み更新ZIPを読み取れません。', 'source');
                }
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_ZIP_BYTES) {
                    throw new UpdateException('ダウンロード済み更新ZIPのサイズが上限を超えています。', 'source');
                }
                if ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new UpdateException('更新ZIPを一時保存できません。', 'staging');
                }
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        $size = @filesize($destination);
        if ($size === false || $size < 1 || $size > self::MAX_ZIP_BYTES || (int) $size !== $bytes) {
            throw new UpdateException('一時保存した更新ZIPのサイズを確認できません。', 'source');
        }
    }

    private function validateManifest($manifest): void
    {
        if (!is_array($manifest)
            || ($manifest['product'] ?? null) !== 'Tomos'
            || !is_string($manifest['from_version'] ?? null)
            || !is_string($manifest['version'] ?? null)
            || !is_array($manifest['files'] ?? null)
            || $manifest['files'] === []
        ) {
            throw new UpdateException('manifestの形式が正しくありません。', 'manifest');
        }
        $versionPattern = '/\A[0-9]+(?:\.[0-9]+)*(?:-[0-9A-Za-z.-]+)?\z/';
        if (preg_match($versionPattern, $manifest['from_version']) !== 1
            || preg_match($versionPattern, $manifest['version']) !== 1
            || version_compare($manifest['from_version'], $manifest['version'], '>=')
        ) {
            throw new UpdateException('manifestのバージョン情報が正しくありません。', 'version');
        }
        if (array_key_exists('minimum_version', $manifest)) {
            if (!is_string($manifest['minimum_version'])
                || preg_match($versionPattern, $manifest['minimum_version']) !== 1
            ) {
                throw new UpdateException('manifestのminimum_versionが正しくありません。', 'version');
            }
            if ($manifest['minimum_version'] !== $manifest['from_version']) {
                throw new UpdateException('manifestのminimum_versionはfrom_versionと一致しません。', 'update_sequence');
            }
        }
        $current = $this->currentVersion();
        if ($current === '') {
            throw new UpdateException('現在のTomosバージョンを確認できません。', 'version');
        }
        if ($manifest['from_version'] !== $current) {
            throw new UpdateException(
                'この更新ZIPは ' . $manifest['from_version'] . ' → ' . $manifest['version']
                    . ' 用です。現在のTomosは ' . $current . ' のため使用できません。',
                'update_sequence'
            );
        }
        if (version_compare($manifest['version'], $current, '<=')) {
            throw new UpdateException('同じバージョン、または現在より古いバージョンは適用できません。', 'version');
        }
        if (!array_key_exists('VERSION', $manifest['files'])) {
            throw new UpdateException('更新ZIPにVERSIONの更新情報がありません。', 'manifest');
        }
        foreach ($manifest['files'] as $relative => $hash) {
            if (!is_string($relative) || !$this->isAllowedTarget($relative)
                || !is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/i', $hash) !== 1
            ) {
                throw new UpdateException('manifestに許可されていない更新対象があります。', 'allowed_paths');
            }
        }
    }

    private function verifySignature(string $manifest, string $signature): void
    {
        $publicKey = @file_get_contents($this->publicKeyPath);
        if (!is_string($publicKey) || openssl_verify($manifest, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new UpdateException('更新ZIPの署名を確認できませんでした。正規のTomos更新ZIPを選択してください。', 'signature');
        }
    }

    private function isAllowedTarget(string $path): bool
    {
        if (!$this->isSafeZipPath($path) || substr($path, -1) === '/') {
            return false;
        }
        $forbiddenExact = ['config.php', 'config.sample.php', '.htaccess'];
        $forbiddenPrefixes = ['content/', 'cache/', 'storage/', 'trash/', 'update/', 'tests/', 'tools/', 'build/', 'backups/', 'staging/', '書類/'];
        if (in_array($path, $forbiddenExact, true)) {
            return false;
        }
        foreach ($forbiddenPrefixes as $prefix) {
            if (strpos($path, $prefix) === 0) {
                return false;
            }
        }
        if (preg_match('#\Acore/Update(?:Lock|Service|Exception)\.php\z#', $path) === 1) {
            return false;
        }
        if (strpos($path, 'themes/') === 0) {
            return preg_match('#\Athemes/(tomos-90s|tomos-blog|tomos-dark|tomos-journal|tomos-minimal|tomos-note)/[A-Za-z0-9._/-]+\z#', $path) === 1;
        }
        return $path === 'VERSION'
            || $path === 'index.php'
            || preg_match('#\A(core|post|setup|assets)/[A-Za-z0-9._/-]+\z#', $path) === 1;
    }

    private function isSafeZipPath(string $path): bool
    {
        return $path !== ''
            && strpos($path, "\0") === false
            && strpos($path, '\\') === false
            && strpos($path, ':') === false
            && strpos($path, '/') !== 0
            && preg_match('#(^|/)\.\.?(/|$)#', $path) !== 1
            && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1
            && preg_match('//u', $path) === 1;
    }

    private function assertWritableTarget(string $relative): void
    {
        $target = $this->targetPath($relative);
        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new UpdateException('更新対象に安全でないファイルがあります。', 'writable');
        }
        $probe = $this->nearestExistingDirectory(dirname($target));
        if ($probe === '' || !is_writable($probe)) {
            $area = strtok($relative, '/');
            throw new UpdateException($area . '/ に書き込みできないため更新できません。FTPまたはサーバーのファイル管理機能で更新してください。', 'writable');
        }
    }

    private function targetPath(string $relative): string
    {
        return $this->rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function nearestExistingDirectory(string $path): string
    {
        while (!is_dir($path) && $path !== dirname($path)) {
            $path = dirname($path);
        }
        $rootReal = realpath($this->rootDir);
        $pathReal = realpath($path);
        if ($rootReal === false || $pathReal === false || ($pathReal !== $rootReal && strpos($pathReal, $rootReal . DIRECTORY_SEPARATOR) !== 0)) {
            return '';
        }
        return $path;
    }

    private function makeTargetDirectories(string $directory, array &$created): void
    {
        $missing = [];
        $cursor = $directory;
        while (!is_dir($cursor)) {
            $missing[] = $cursor;
            $cursor = dirname($cursor);
        }
        foreach (array_reverse($missing) as $path) {
            if (!@mkdir($path, 0755)) {
                throw new RuntimeException('target_directory');
            }
            $created[] = $path;
        }
    }

    private function rollback(
        string $backupDir,
        array $updated,
        array $newFiles,
        array $createdDirectories,
        array $originalPermissions
    ): bool
    {
        $ok = true;
        foreach (array_reverse($updated) as $relative) {
            $target = $this->targetPath($relative);
            $backup = $backupDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (in_array($relative, $newFiles, true)) {
                if (file_exists($target) && !@unlink($target)) {
                    $ok = false;
                }
                continue;
            }
            if (!is_file($backup)) {
                $ok = false;
                continue;
            }
            $temporary = dirname($target) . DIRECTORY_SEPARATOR . '.tomos-restore-' . bin2hex(random_bytes(8)) . '.tmp';
            if (!@copy($backup, $temporary)) {
                @unlink($temporary);
                $ok = false;
                continue;
            }
            @chmod($temporary, (int) ($originalPermissions[$relative] ?? 0644));
            if (!@rename($temporary, $target)) {
                @unlink($temporary);
                $ok = false;
            }
        }
        foreach (array_reverse($createdDirectories) as $directory) {
            @rmdir($directory);
        }
        return $ok;
    }

    private function resultMeta(array $summary, string $startedAt, bool $ok, string $stage, bool $rollbackAttempted, bool $rollbackSucceeded): array
    {
        return [
            'started_at' => $startedAt,
            'finished_at' => gmdate('c'),
            'previous_version' => $summary['current_version'],
            'target_version' => $summary['version'],
            'file_count' => count($summary['files']),
            'files' => $summary['files'],
            'result' => $ok ? 'success' : 'failure',
            'stage' => $stage,
            'rollback_attempted' => $rollbackAttempted,
            'rollback_succeeded' => $rollbackSucceeded,
        ];
    }

    private function writeMeta(string $backupDir, array $meta): bool
    {
        if (is_dir($backupDir)) {
            return @file_put_contents($backupDir . DIRECTORY_SEPARATOR . 'update-meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
        }
        return false;
    }

    private function writeLog(array $meta): bool
    {
        $path = $this->storageDir . DIRECTORY_SEPARATOR . 'update-logs' . DIRECTORY_SEPARATOR . gmdate('Y-m') . '.log';
        return @file_put_contents($path, json_encode($meta, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
    }
}

final class UpdateException extends RuntimeException
{
    private $stage;
    private $rollbackFailed;

    public function __construct(string $message, string $stage, bool $rollbackFailed = false)
    {
        parent::__construct($message);
        $this->stage = $stage;
        $this->rollbackFailed = $rollbackFailed;
    }

    public function stage(): string
    {
        return $this->stage;
    }

    public function rollbackFailed(): bool
    {
        return $this->rollbackFailed;
    }
}
