<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;
use Throwable;

final class UpdaterSelfUpdate
{
    private const TARGET = 'update/index.php';
    private const PENDING_FILE = 'update-index.php';
    private const METADATA_FILE = 'update-index.json';

    private $rootDir;
    private $storageDir;
    private $pendingDir;
    private $targetPath;

    public function __construct(string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->storageDir = $this->rootDir . DIRECTORY_SEPARATOR . 'storage';
        $this->pendingDir = $this->rootDir . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'updater-pending';
        $this->targetPath = $this->rootDir . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR . 'index.php';
    }

    public function hasPendingUpdate(): bool
    {
        return file_exists($this->pendingFilePath())
            || is_link($this->pendingFilePath())
            || file_exists($this->metadataPath())
            || is_link($this->metadataPath());
    }

    public function apply(): array
    {
        $startedAt = gmdate('c');
        $backupId = 'updater-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(16));
        $backupDir = $this->storageDir . DIRECTORY_SEPARATOR . 'update-backups' . DIRECTORY_SEPARATOR . $backupId;
        $backupPath = $backupDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'update' . DIRECTORY_SEPARATOR . 'index.php';
        $oldHash = '';
        $expectedHash = '';
        $replacementHash = '';
        $installedHash = '';
        $permissions = 0;
        $stage = 'preflight';
        $backupCreated = false;
        $replacementStarted = false;
        $rollbackAttempted = false;
        $rollbackSucceeded = false;
        $temporary = '';
        $lockHandle = null;

        try {
            $stage = 'self_update_lock';
            $lockHandle = $this->acquireOperationLock();
            if (class_exists(UpdateLock::class) && UpdateLock::isActive($this->rootDir)) {
                throw new RuntimeException('tomos_update_locked');
            }

            $stage = 'current_file';
            $this->assertSafeTarget();
            $oldHash = $this->hashFile($this->targetPath);
            $installedHash = $oldHash;
            $permissions = fileperms($this->targetPath) & 0777;

            $stage = 'backup';
            $this->createBackup($backupDir, $backupPath, $oldHash, $permissions);
            $backupCreated = true;

            $stage = 'pending_validation';
            $metadata = $this->readMetadata();
            $expectedHash = strtolower((string) $metadata['sha256']);
            $sourcePath = $this->pendingFilePath();
            $this->assertSafePendingFile($sourcePath, $expectedHash);

            if (hash_equals($oldHash, $expectedHash)) {
                $meta = $this->resultMeta(
                    $startedAt,
                    $backupId,
                    $oldHash,
                    $expectedHash,
                    $oldHash,
                    true,
                    'no_change',
                    false,
                    false,
                    $permissions
                );
                $recordingOk = $this->recordOutcome($backupDir, $meta);
                $cleanupOk = $recordingOk ? $this->removePendingFiles($backupId) : false;

                return [
                    'ok' => true,
                    'applied' => false,
                    'backup_id' => $backupId,
                    'previous_sha256' => $oldHash,
                    'sha256' => $oldHash,
                    'recording_ok' => $recordingOk,
                    'cleanup_ok' => $cleanupOk,
                ];
            }

            $stage = 'temporary_copy';
            $temporary = $this->temporaryPath('.tomos-updater-');
            $this->copyToExclusiveTemporary($sourcePath, $temporary);

            $stage = 'temporary_validation';
            $this->assertPhpAndHash($temporary, $expectedHash);
            if (!@chmod($temporary, $permissions)
                || (fileperms($temporary) & 0777) !== $permissions
            ) {
                throw new RuntimeException('temporary_permissions');
            }
            $this->assertPhpAndHash($temporary, $expectedHash);

            $stage = 'replace';
            if (!@rename($temporary, $this->targetPath)) {
                throw new RuntimeException('replace_rename');
            }
            $temporary = '';
            $replacementStarted = true;

            $stage = 'post_replace_validation';
            $replacementHash = $this->hashFile($this->targetPath);
            $this->assertPhpAndHash($this->targetPath, $expectedHash);
            if ((fileperms($this->targetPath) & 0777) !== $permissions) {
                throw new RuntimeException('post_replace_permissions');
            }
            $installedHash = $replacementHash;

            $meta = $this->resultMeta(
                $startedAt,
                $backupId,
                $oldHash,
                $expectedHash,
                $installedHash,
                true,
                'complete',
                false,
                false,
                $permissions
            );
            $recordingOk = $this->recordOutcome($backupDir, $meta);
            $cleanupOk = $recordingOk ? $this->removePendingFiles($backupId) : false;

            return [
                'ok' => true,
                'applied' => true,
                'backup_id' => $backupId,
                'previous_sha256' => $oldHash,
                'sha256' => $installedHash,
                'recording_ok' => $recordingOk,
                'cleanup_ok' => $cleanupOk,
            ];
        } catch (Throwable $exception) {
            if ($replacementStarted) {
                $rollbackAttempted = true;
                $rollbackSucceeded = $this->restore($backupPath, $oldHash, $permissions);
                if ($rollbackSucceeded) {
                    $installedHash = $oldHash;
                }
            }

            $meta = $this->resultMeta(
                $startedAt,
                $backupId,
                $oldHash,
                $expectedHash,
                $replacementHash,
                false,
                $stage,
                $rollbackAttempted,
                $rollbackSucceeded,
                $permissions
            );
            $recordingOk = $this->recordOutcome(
                ($backupCreated || (is_dir($backupDir) && !is_link($backupDir))) ? $backupDir : '',
                $meta
            );

            if ($rollbackAttempted && !$rollbackSucceeded) {
                throw new UpdaterSelfUpdateException(
                    'Updater更新に失敗し、自動復元も完了できませんでした。バックアップを確認してください。',
                    $stage,
                    true,
                    !$recordingOk
                );
            }
            throw new UpdaterSelfUpdateException(
                'Updater更新を完了できませんでした。現在のUpdaterは変更されていません。',
                $stage,
                false,
                !$recordingOk
            );
        } finally {
            if ($temporary !== '') {
                @unlink($temporary);
            }
            $this->releaseOperationLock($lockHandle);
        }
    }

    private function readMetadata(): array
    {
        $path = $this->metadataPath();
        $rootReal = realpath($this->rootDir);
        $pendingReal = realpath($this->pendingDir);
        if (!is_dir($this->pendingDir) || is_link($this->pendingDir)
            || !is_file($path) || is_link($path) || !is_readable($path)
            || $rootReal === false || $pendingReal === false
            || $pendingReal !== $rootReal . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'updater-pending'
        ) {
            throw new RuntimeException('metadata_file');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > 4096) {
            throw new RuntimeException('metadata_size');
        }
        $raw = @file_get_contents($path);
        $metadata = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($metadata) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('metadata_json');
        }
        $keys = array_keys($metadata);
        sort($keys);
        if ($keys !== ['sha256', 'target']
            || ($metadata['target'] ?? null) !== self::TARGET
            || !is_string($metadata['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/i', $metadata['sha256']) !== 1
        ) {
            throw new RuntimeException('metadata_format');
        }
        return $metadata;
    }

    private function assertSafePendingFile(string $path, string $expectedHash): void
    {
        $items = @scandir($this->pendingDir);
        if (!is_array($items)) {
            throw new RuntimeException('pending_directory');
        }
        sort($items);
        if ($items !== ['.', '..', self::METADATA_FILE, self::PENDING_FILE]) {
            throw new RuntimeException('pending_directory_contents');
        }
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException('pending_file');
        }
        $this->assertPhpAndHash($path, $expectedHash);
    }

    private function assertSafeTarget(): void
    {
        $targetDir = dirname($this->targetPath);
        if (!is_dir($targetDir) || is_link($targetDir)
            || !is_file($this->targetPath) || is_link($this->targetPath)
            || !is_readable($this->targetPath) || !is_writable($targetDir)
        ) {
            throw new RuntimeException('current_target');
        }
        $rootReal = realpath($this->rootDir);
        $targetDirReal = realpath($targetDir);
        if ($rootReal === false || $targetDirReal === false
            || $targetDirReal !== $rootReal . DIRECTORY_SEPARATOR . 'update'
        ) {
            throw new RuntimeException('target_directory');
        }
    }

    private function createBackup(string $backupDir, string $backupPath, string $oldHash, int $permissions): void
    {
        $backupBase = dirname($backupDir);
        if (!is_dir($this->storageDir) || is_link($this->storageDir)
            || !is_dir($backupBase) || is_link($backupBase) || !is_writable($backupBase)
            || !@mkdir($backupDir, 0700)
            || !@mkdir(dirname($backupPath), 0700, true)
        ) {
            throw new RuntimeException('backup_directory');
        }
        if (!@copy($this->targetPath, $backupPath)
            || !@chmod($backupPath, $permissions)
            || !hash_equals($oldHash, $this->hashFile($backupPath))
            || (fileperms($backupPath) & 0777) !== $permissions
        ) {
            throw new RuntimeException('backup_file');
        }
    }

    private function restore(string $backupPath, string $oldHash, int $permissions): bool
    {
        $temporary = '';
        try {
            if ($oldHash === '' || !is_file($backupPath) || is_link($backupPath)
                || !hash_equals($oldHash, $this->hashFile($backupPath))
            ) {
                return false;
            }
            $temporary = $this->temporaryPath('.tomos-updater-restore-');
            $this->copyToExclusiveTemporary($backupPath, $temporary);
            $this->assertPhpAndHash($temporary, $oldHash);
            if (!@chmod($temporary, $permissions)
                || (fileperms($temporary) & 0777) !== $permissions
                || !@rename($temporary, $this->targetPath)
            ) {
                return false;
            }
            $temporary = '';
            $this->assertPhpAndHash($this->targetPath, $oldHash);
            return (fileperms($this->targetPath) & 0777) === $permissions;
        } catch (Throwable $exception) {
            return false;
        } finally {
            if ($temporary !== '') {
                @unlink($temporary);
            }
        }
    }

    private function copyToExclusiveTemporary(string $sourcePath, string $temporary): void
    {
        $source = @fopen($sourcePath, 'rb');
        $destination = @fopen($temporary, 'xb');
        if (!is_resource($source) || !is_resource($destination)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }
            @unlink($temporary);
            throw new RuntimeException('temporary_open');
        }
        $copied = stream_copy_to_stream($source, $destination);
        $flushed = fflush($destination);
        fclose($source);
        fclose($destination);
        if ($copied === false || !$flushed) {
            @unlink($temporary);
            throw new RuntimeException('temporary_copy');
        }
    }

    private function assertPhpAndHash(string $path, string $expectedHash): void
    {
        if (!is_file($path) || is_link($path) || !is_readable($path)
            || @file_get_contents($path, false, null, 0, 5) !== '<?php'
            || !hash_equals(strtolower($expectedHash), $this->hashFile($path))
        ) {
            throw new RuntimeException('php_or_hash');
        }
    }

    private function hashFile(string $path): string
    {
        $hash = @hash_file('sha256', $path);
        if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
            throw new RuntimeException('hash');
        }
        return strtolower($hash);
    }

    private function temporaryPath(string $prefix): string
    {
        return dirname($this->targetPath) . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(16)) . '.tmp';
    }

    private function acquireOperationLock()
    {
        if (!is_dir($this->storageDir) || is_link($this->storageDir)) {
            throw new RuntimeException('storage_directory');
        }
        $temporaryBase = $this->storageDir . DIRECTORY_SEPARATOR . 'update-tmp';
        if (!is_dir($temporaryBase) || is_link($temporaryBase) || !is_writable($temporaryBase)) {
            throw new RuntimeException('temporary_directory');
        }
        $path = $temporaryBase . DIRECTORY_SEPARATOR . 'updater-self-update.lock';
        $handle = @fopen($path, 'c');
        if (!is_resource($handle) || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('self_update_lock');
        }
        return $handle;
    }

    private function releaseOperationLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function resultMeta(
        string $startedAt,
        string $backupId,
        string $oldHash,
        string $expectedHash,
        string $installedHash,
        bool $ok,
        string $stage,
        bool $rollbackAttempted,
        bool $rollbackSucceeded,
        int $permissions
    ): array {
        return [
            'type' => 'updater_self_update',
            'started_at' => $startedAt,
            'finished_at' => gmdate('c'),
            'backup_id' => $backupId,
            'target' => self::TARGET,
            'previous_sha256' => $oldHash,
            'target_sha256' => $expectedHash,
            'installed_sha256' => $installedHash,
            'previous_permissions' => $permissions > 0 ? sprintf('%04o', $permissions) : '',
            'result' => $ok ? 'success' : 'failure',
            'stage' => $stage,
            'rollback_attempted' => $rollbackAttempted,
            'rollback_succeeded' => $rollbackSucceeded,
        ];
    }

    private function recordOutcome(string $backupDir, array $meta): bool
    {
        $backupMetaOk = $backupDir === '' || $this->writeMeta($backupDir, $meta);
        $resultMetaOk = $this->writeResultMeta($meta);
        $logOk = $this->writeLog($meta);
        return $backupMetaOk && $resultMetaOk && $logOk;
    }

    private function writeMeta(string $backupDir, array $meta): bool
    {
        if (!is_dir($backupDir) || is_link($backupDir)) {
            return false;
        }
        $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return is_string($json) && @file_put_contents(
            $backupDir . DIRECTORY_SEPARATOR . 'update-meta.json',
            $json,
            LOCK_EX
        ) !== false;
    }

    private function writeLog(array $meta): bool
    {
        $directory = $this->storageDir . DIRECTORY_SEPARATOR . 'update-logs';
        if (!is_dir($this->storageDir) || is_link($this->storageDir)
            || !is_dir($directory) || is_link($directory)
        ) {
            return false;
        }
        $json = json_encode($meta, JSON_UNESCAPED_SLASHES);
        return is_string($json) && @file_put_contents(
            $directory . DIRECTORY_SEPARATOR . gmdate('Y-m') . '.log',
            $json . PHP_EOL,
            FILE_APPEND | LOCK_EX
        ) !== false;
    }

    private function writeResultMeta(array $meta): bool
    {
        $directory = $this->storageDir . DIRECTORY_SEPARATOR . 'update-logs';
        if (!is_dir($this->storageDir) || is_link($this->storageDir)
            || !is_dir($directory) || is_link($directory)
        ) {
            return false;
        }
        $backupId = (string) ($meta['backup_id'] ?? '');
        if (preg_match('/\Aupdater-[0-9]{8}-[0-9]{6}-[a-f0-9]{32}\z/', $backupId) !== 1) {
            return false;
        }
        $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return is_string($json) && @file_put_contents(
            $directory . DIRECTORY_SEPARATOR . $backupId . '.json',
            $json,
            LOCK_EX
        ) !== false;
    }

    private function removePendingFiles(string $backupId): bool
    {
        if (!is_dir($this->pendingDir) || is_link($this->pendingDir)) {
            return false;
        }
        $temporaryBase = $this->storageDir . DIRECTORY_SEPARATOR . 'update-tmp';
        if (!is_dir($temporaryBase) || is_link($temporaryBase) || !is_writable($temporaryBase)) {
            return false;
        }
        $completedDir = $temporaryBase . DIRECTORY_SEPARATOR . $backupId . '-complete';
        if (file_exists($completedDir) || is_link($completedDir)
            || !@rename($this->pendingDir, $completedDir)
        ) {
            return false;
        }
        $fileOk = @unlink($completedDir . DIRECTORY_SEPARATOR . self::PENDING_FILE);
        $metadataOk = @unlink($completedDir . DIRECTORY_SEPARATOR . self::METADATA_FILE);
        $directoryOk = @rmdir($completedDir);
        return $fileOk && $metadataOk && $directoryOk;
    }

    private function pendingFilePath(): string
    {
        return $this->pendingDir . DIRECTORY_SEPARATOR . self::PENDING_FILE;
    }

    private function metadataPath(): string
    {
        return $this->pendingDir . DIRECTORY_SEPARATOR . self::METADATA_FILE;
    }
}

final class UpdaterSelfUpdateException extends RuntimeException
{
    private $stage;
    private $rollbackFailed;
    private $recordingFailed;

    public function __construct(string $message, string $stage, bool $rollbackFailed, bool $recordingFailed)
    {
        parent::__construct($message);
        $this->stage = $stage;
        $this->rollbackFailed = $rollbackFailed;
        $this->recordingFailed = $recordingFailed;
    }

    public function stage(): string
    {
        return $this->stage;
    }

    public function rollbackFailed(): bool
    {
        return $this->rollbackFailed;
    }

    public function recordingFailed(): bool
    {
        return $this->recordingFailed;
    }
}
