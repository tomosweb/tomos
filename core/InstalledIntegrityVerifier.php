<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;
use Throwable;

final class InstalledIntegrityVerifier
{
    private const PENDING_RUNTIME_FILES = [
        'docs/theme/theme-rules.json' => [
            'pending' => 'core/updater-pending/theme-rules.json',
            'metadata' => 'core/updater-pending/theme-rules.meta.json',
        ],
    ];

    private $rootDir;
    private $storageDir;
    private $requiredFilesPath;

    public function __construct(string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->storageDir = $this->rootDir . DIRECTORY_SEPARATOR . 'storage';
        $this->requiredFilesPath = $this->rootDir . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'required-installed-files.txt';
    }

    public function verifyAfterUpdate(array $result): array
    {
        try {
            $this->materializePendingRuntimeFiles($result);
        } catch (Throwable $exception) {
            $this->rollbackAfterVerificationFailure($result, [], 'verify:pending_runtime');
            throw new UpdateException('更新後の必須ファイル確認に失敗したため、更新前の状態へ復元しました。', 'verify_required_files');
        }
        try {
            $missing = $this->missingRequiredFiles();
        } catch (Throwable $exception) {
            $this->rollbackAfterVerificationFailure($result, [], 'verify:required_files_list');
            throw new UpdateException('更新後の必須ファイル確認に失敗したため、更新前の状態へ復元しました。', 'verify_required_files');
        }
        if ($missing === []) {
            return $result;
        }

        $this->rollbackAfterVerificationFailure($result, $missing, 'verify:required_files_missing');
        throw new UpdateException('更新後の必須ファイルを確認できなかったため、更新前の状態へ復元しました。', 'verify_required_files');
    }

    private function materializePendingRuntimeFiles(array $result): void
    {
        foreach (self::PENDING_RUNTIME_FILES as $targetRelative => $definition) {
            $pendingPath = $this->targetPath($definition['pending']);
            $metadataPath = $this->targetPath($definition['metadata']);
            $hasPending = is_file($pendingPath) || is_link($pendingPath);
            $hasMetadata = is_file($metadataPath) || is_link($metadataPath);
            if (!$hasPending && !$hasMetadata) {
                continue;
            }
            if (!$hasPending || !$hasMetadata || is_link($pendingPath) || is_link($metadataPath)) {
                throw new RuntimeException('pending_runtime_pair');
            }

            $metadataRaw = @file_get_contents($metadataPath);
            $metadata = is_string($metadataRaw) ? json_decode($metadataRaw, true) : null;
            if (!is_array($metadata) || json_last_error() !== JSON_ERROR_NONE
                || ($metadata['target'] ?? null) !== $targetRelative
                || !is_string($metadata['sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/i', $metadata['sha256']) !== 1
            ) {
                throw new RuntimeException('pending_runtime_metadata');
            }

            $payload = @file_get_contents($pendingPath);
            if (!is_string($payload)
                || json_decode($payload, true) === null
                || json_last_error() !== JSON_ERROR_NONE
                || !hash_equals(strtolower($metadata['sha256']), strtolower((string) hash('sha256', $payload)))
            ) {
                throw new RuntimeException('pending_runtime_payload');
            }

            $targetPath = $this->targetPath($targetRelative);
            $targetDir = dirname($targetPath);
            $rootReal = realpath($this->rootDir);
            $expectedTargetDir = $rootReal === false
                ? ''
                : $rootReal . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'theme';
            if ($rootReal === false || $expectedTargetDir === ''
                || is_link($targetDir) || (file_exists($targetDir) && !is_dir($targetDir))
            ) {
                throw new RuntimeException('pending_runtime_directory');
            }
            if (!is_dir($targetDir)) {
                $missing = [];
                $cursor = $targetDir;
                while (!is_dir($cursor)) {
                    if (is_link($cursor) || file_exists($cursor)) {
                        throw new RuntimeException('pending_runtime_directory');
                    }
                    $missing[] = $cursor;
                    $parent = dirname($cursor);
                    if ($parent === $cursor) {
                        throw new RuntimeException('pending_runtime_directory');
                    }
                    $cursor = $parent;
                }
                if (is_link($cursor) || realpath($cursor) === false
                    || ($cursor !== $this->rootDir && strpos((string) realpath($cursor), $rootReal . DIRECTORY_SEPARATOR) !== 0)
                    || !@mkdir($targetDir, 0755, true)
                ) {
                    throw new RuntimeException('pending_runtime_directory');
                }
            }
            $targetDirReal = realpath($targetDir);
            if ($targetDirReal === false || $targetDirReal !== $expectedTargetDir
                || !is_dir($targetDir) || is_link($targetDir)
            ) {
                throw new RuntimeException('pending_runtime_directory');
            }

            if (file_exists($targetPath) || is_link($targetPath)) {
                if (!is_file($targetPath) || is_link($targetPath)) {
                    throw new RuntimeException('pending_runtime_target');
                }
                $installedHash = hash_file('sha256', $targetPath);
                if (is_string($installedHash) && hash_equals(strtolower($metadata['sha256']), strtolower($installedHash))) {
                    continue;
                }
                throw new RuntimeException('pending_runtime_target_conflict');
            }

            $this->registerNewFileForRollback($result, $targetRelative);
            $temporary = $targetDir . DIRECTORY_SEPARATOR . '.tomos-pending-' . bin2hex(random_bytes(8)) . '.tmp';
            $written = @file_put_contents($temporary, $payload, LOCK_EX);
            $writtenOk = $written !== false
                && $written === strlen($payload)
                && @chmod($temporary, 0644)
                && hash_equals(strtolower($metadata['sha256']), strtolower((string) hash_file('sha256', $temporary)));
            if (!$writtenOk || !@rename($temporary, $targetPath)) {
                @unlink($temporary);
                throw new RuntimeException('pending_runtime_install');
            }
        }
    }

    private function registerNewFileForRollback(array $result, string $relative): void
    {
        $backupId = (string) ($result['backup_id'] ?? '');
        if (preg_match('/\A[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\z/', $backupId) !== 1) {
            throw new RuntimeException('backup_id');
        }
        $metaPath = $this->storageDir . DIRECTORY_SEPARATOR . 'update-backups' . DIRECTORY_SEPARATOR . $backupId . DIRECTORY_SEPARATOR . 'update-meta.json';
        $meta = json_decode((string) @file_get_contents($metaPath), true);
        if (!is_array($meta) || !is_array($meta['files'] ?? null)) {
            throw new RuntimeException('backup_meta');
        }
        if (!in_array($relative, $meta['files'], true)) {
            $meta['files'][] = $relative;
            if (!$this->writeMeta($metaPath, $meta)) {
                throw new RuntimeException('backup_meta_write');
            }
        }
    }

    private function rollbackAfterVerificationFailure(array $result, array $missing, string $stage): void
    {

        $backupId = (string) ($result['backup_id'] ?? '');
        if (preg_match('/\A[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\z/', $backupId) !== 1) {
            throw new UpdateException('更新後の必須ファイル確認に失敗しました。バックアップを特定できません。管理者による確認が必要です。', 'verify_required_files', true);
        }

        $backupDir = $this->storageDir . DIRECTORY_SEPARATOR . 'update-backups' . DIRECTORY_SEPARATOR . $backupId;
        $metaPath = $backupDir . DIRECTORY_SEPARATOR . 'update-meta.json';
        $meta = json_decode((string) @file_get_contents($metaPath), true);
        if (!is_array($meta) || !is_array($meta['files'] ?? null)) {
            throw new UpdateException('更新後の必須ファイル確認に失敗し、自動復元に必要な記録を確認できませんでした。管理者による確認が必要です。', 'verify_required_files', true);
        }

        $rollbackSucceeded = $this->rollback($backupDir, $meta['files']);
        $failureMeta = $meta;
        $failureMeta['finished_at'] = gmdate('c');
        $failureMeta['result'] = 'failure';
        $failureMeta['stage'] = $stage;
        if ($missing !== []) {
            $failureMeta['missing_required_files'] = $missing;
        }
        $failureMeta['rollback_attempted'] = true;
        $failureMeta['rollback_succeeded'] = $rollbackSucceeded;
        $this->writeMeta($metaPath, $failureMeta);
        $this->writeLog($failureMeta);

        if (!$rollbackSucceeded) {
            throw new UpdateException('更新後に必須ファイルの欠落を検出し、自動復元も完了できませんでした。バックアップは保存されています。管理者による確認が必要です。', 'verify_required_files', true);
        }
    }

    private function missingRequiredFiles(): array
    {
        if (!is_file($this->requiredFilesPath) || !is_readable($this->requiredFilesPath)) {
            return ['core/required-installed-files.txt'];
        }

        $lines = file($this->requiredFilesPath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return ['core/required-installed-files.txt'];
        }

        $missing = [];
        foreach ($lines as $line) {
            $relative = trim($line);
            if ($relative === '' || strpos($relative, '#') === 0) {
                continue;
            }
            if (!$this->isSafeRelativePath($relative)) {
                throw new RuntimeException('required_files_list');
            }
            if (!is_file($this->targetPath($relative))) {
                $missing[] = $relative;
            }
        }
        return $missing;
    }

    private function rollback(string $backupDir, array $files): bool
    {
        $ok = true;
        foreach (array_reverse($files) as $relative) {
            if (!is_string($relative) || !$this->isSafeRelativePath($relative)) {
                $ok = false;
                continue;
            }

            $target = $this->targetPath($relative);
            $backup = $backupDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($backup)) {
                if (file_exists($target) && !@unlink($target)) {
                    $ok = false;
                }
                continue;
            }

            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
                $ok = false;
                continue;
            }
            $temporary = $parent . DIRECTORY_SEPARATOR . '.tomos-integrity-restore-' . bin2hex(random_bytes(8)) . '.tmp';
            if (!@copy($backup, $temporary)) {
                @unlink($temporary);
                $ok = false;
                continue;
            }
            $permissions = is_file($target) ? (fileperms($target) & 0777) : 0644;
            @chmod($temporary, $permissions);
            if (!@rename($temporary, $target)) {
                @unlink($temporary);
                $ok = false;
            }
        }
        return $ok;
    }

    private function writeMeta(string $path, array $meta): bool
    {
        return @file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
    }

    private function writeLog(array $meta): void
    {
        $path = $this->storageDir . DIRECTORY_SEPARATOR . 'update-logs' . DIRECTORY_SEPARATOR . gmdate('Y-m') . '.log';
        @file_put_contents($path, json_encode($meta, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function targetPath(string $relative): string
    {
        return $this->rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function isSafeRelativePath(string $path): bool
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
}
