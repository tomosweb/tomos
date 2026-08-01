<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;

final class InstalledIntegrityVerifier
{
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
        $missing = $this->missingRequiredFiles();
        if ($missing === []) {
            return $result;
        }

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
        $failureMeta['stage'] = 'verify:required_files_missing';
        $failureMeta['missing_required_files'] = $missing;
        $failureMeta['rollback_attempted'] = true;
        $failureMeta['rollback_succeeded'] = $rollbackSucceeded;
        $this->writeMeta($metaPath, $failureMeta);
        $this->writeLog($failureMeta);

        if (!$rollbackSucceeded) {
            throw new UpdateException('更新後に必須ファイルの欠落を検出し、自動復元も完了できませんでした。バックアップは保存されています。管理者による確認が必要です。', 'verify_required_files', true);
        }

        throw new UpdateException('更新後に必須ファイルの欠落を検出したため、更新前の状態へ復元しました。', 'verify_required_files');
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

    private function writeMeta(string $path, array $meta): void
    {
        @file_put_contents($path, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
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
