<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;
use Throwable;

final class UpdaterSelfUpdate
{
    private const TARGETS = [
        'update/index.php' => [
            'pending_file' => 'update-index.php',
            'metadata_file' => 'update-index.json',
            'directory' => 'update',
        ],
        'core/UpdateService.php' => [
            'pending_file' => 'update-service.php',
            'metadata_file' => 'update-service.json',
            'directory' => 'core',
        ],
    ];

    private $rootDir;
    private $storageDir;
    private $pendingDir;
    private $replaceHook;

    /** The optional hook is only used by local failure-injection tests. */
    public function __construct(string $rootDir, ?callable $replaceHook = null)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->storageDir = $this->rootDir . DIRECTORY_SEPARATOR . 'storage';
        $this->pendingDir = $this->rootDir . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'updater-pending';
        $this->replaceHook = $replaceHook;
    }

    public function hasPendingUpdate(): bool
    {
        if (is_link($this->pendingDir)) {
            return true;
        }
        if (!is_dir($this->pendingDir)) {
            return false;
        }
        $items = @scandir($this->pendingDir);
        if (!is_array($items)) {
            return true;
        }
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                return true;
            }
        }
        return false;
    }

    public function apply(): array
    {
        $startedAt = gmdate('c');
        $backupId = 'updater-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(16));
        $backupDir = $this->storageDir . DIRECTORY_SEPARATOR . 'update-backups' . DIRECTORY_SEPARATOR . $backupId;
        $stage = 'preflight';
        $backupCreated = false;
        $replacementStarted = false;
        $rollbackAttempted = false;
        $rollbackSucceeded = false;
        $bundle = [];
        $temporaryPaths = [];
        $lockHandle = null;

        try {
            $stage = 'self_update_lock';
            $lockHandle = $this->acquireOperationLock();
            if (class_exists(UpdateLock::class) && UpdateLock::isActive($this->rootDir)) {
                throw new RuntimeException('tomos_update_locked');
            }

            // Validate every pending/current target before backup or replacement.
            $stage = 'pending_validation';
            $bundle = $this->collectPendingBundle();
            $stage = 'current_files';
            $bundle = $this->collectCurrentTargets($bundle);

            $stage = 'backup';
            $this->createBackup($backupDir, $bundle);
            $backupCreated = true;

            $stage = 'temporary_copy';
            foreach ($bundle as $target => $entry) {
                if (!$entry['changed']) {
                    continue;
                }
                $temporary = $this->temporaryPath($target, '.tomos-updater-bundle-');
                $this->copyToExclusiveTemporary($entry['source'], $temporary);
                $temporaryPaths[$target] = $temporary;
            }

            $stage = 'temporary_validation';
            foreach ($bundle as $target => $entry) {
                if (!$entry['changed']) {
                    continue;
                }
                $temporary = $temporaryPaths[$target];
                $this->assertPhpAndHash($temporary, $entry['expected_sha256']);
                if (!@chmod($temporary, $entry['permissions'])
                    || (fileperms($temporary) & 0777) !== $entry['permissions']
                ) {
                    throw new RuntimeException('temporary_permissions');
                }
                $this->assertPhpAndHash($temporary, $entry['expected_sha256']);
            }

            // All temporary files are ready before the first target is replaced.
            $stage = 'replace';
            foreach ($bundle as $target => &$entry) {
                if (!$entry['changed']) {
                    continue;
                }
                if (is_callable($this->replaceHook)) {
                    call_user_func($this->replaceHook, $target);
                }
                if (!@rename($temporaryPaths[$target], $entry['target_path'])) {
                    throw new RuntimeException('replace_rename');
                }
                unset($temporaryPaths[$target]);
                $replacementStarted = true;
                $entry['installed_sha256'] = $entry['expected_sha256'];
            }
            unset($entry);

            $stage = 'post_replace_validation';
            foreach ($bundle as $target => &$entry) {
                $installedHash = $entry['changed']
                    ? $entry['expected_sha256']
                    : $entry['old_sha256'];
                $this->assertPhpAndHash($entry['target_path'], $installedHash);
                if ((fileperms($entry['target_path']) & 0777) !== $entry['permissions']) {
                    throw new RuntimeException('post_replace_permissions');
                }
                $entry['installed_sha256'] = $this->hashFile($entry['target_path']);
            }
            unset($entry);

            $targetMeta = $this->targetMeta($bundle);
            $meta = $this->resultMeta($startedAt, $backupId, $targetMeta, true, 'complete', false, false);
            $recordingOk = $this->recordOutcome($backupDir, $meta);
            $cleanupOk = $recordingOk ? $this->removePendingFiles($backupId, $bundle) : false;

            return $this->publicResult($backupId, $targetMeta, $recordingOk, $cleanupOk);
        } catch (Throwable $exception) {
            if ($replacementStarted) {
                $rollbackAttempted = true;
                $rollbackSucceeded = $this->restoreBundle($backupDir, $bundle);
                if ($rollbackSucceeded) {
                    foreach ($bundle as &$entry) {
                        $entry['installed_sha256'] = $entry['old_sha256'];
                    }
                    unset($entry);
                }
            }

            $targetMeta = $this->targetMeta($bundle);
            $meta = $this->resultMeta(
                $startedAt,
                $backupId,
                $targetMeta,
                false,
                $stage,
                $rollbackAttempted,
                $rollbackSucceeded
            );
            $recordingOk = $this->recordOutcome(
                ($backupCreated || (is_dir($backupDir) && !is_link($backupDir))) ? $backupDir : '',
                $meta
            );

            if ($rollbackAttempted && !$rollbackSucceeded) {
                throw new UpdaterSelfUpdateException(
                    'Updater bundleの更新に失敗し、自動復元も完了できませんでした。バックアップを確認してください。',
                    $stage,
                    true,
                    !$recordingOk
                );
            }
            throw new UpdaterSelfUpdateException(
                'Updater bundleの更新を完了できませんでした。現在のUpdaterは変更されていません。',
                $stage,
                false,
                !$recordingOk
            );
        } finally {
            foreach ($temporaryPaths as $temporary) {
                @unlink($temporary);
            }
            $this->releaseOperationLock($lockHandle);
        }
    }

    private function collectPendingBundle(): array
    {
        $this->assertPendingDirectory();
        $bundle = [];
        foreach (self::TARGETS as $target => $definition) {
            $metadataPath = $this->pendingDir . DIRECTORY_SEPARATOR . $definition['metadata_file'];
            $sourcePath = $this->pendingDir . DIRECTORY_SEPARATOR . $definition['pending_file'];
            $hasMetadata = is_file($metadataPath) || is_link($metadataPath);
            $hasSource = is_file($sourcePath) || is_link($sourcePath);
            if (!$hasMetadata && !$hasSource) {
                continue;
            }
            if (!$hasMetadata || !$hasSource) {
                throw new RuntimeException('pending_pair');
            }
            $metadata = $this->readMetadata($metadataPath, $target);
            $expectedHash = strtolower((string) $metadata['sha256']);
            if (!is_file($sourcePath) || is_link($sourcePath) || !is_readable($sourcePath)) {
                throw new RuntimeException('pending_file');
            }
            $this->assertPhpAndHash($sourcePath, $expectedHash);
            $bundle[$target] = [
                'target' => $target,
                'source' => $sourcePath,
                'expected_sha256' => $expectedHash,
                'directory' => $definition['directory'],
            ];
        }
        return $bundle;
    }

    private function collectCurrentTargets(array $bundle): array
    {
        foreach ($bundle as $target => $entry) {
            $definition = self::TARGETS[$target];
            $targetPath = $this->rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $target);
            $targetDir = dirname($targetPath);
            $this->assertSafeTarget($target, $targetPath, $targetDir, $definition['directory']);
            $oldHash = $this->hashFile($targetPath);
            $permissions = fileperms($targetPath) & 0777;
            $bundle[$target]['target_path'] = $targetPath;
            $bundle[$target]['old_sha256'] = $oldHash;
            $bundle[$target]['permissions'] = $permissions;
            $bundle[$target]['changed'] = !hash_equals($oldHash, $bundle[$target]['expected_sha256']);
            $bundle[$target]['installed_sha256'] = $oldHash;
        }
        return $bundle;
    }

    private function assertPendingDirectory(): void
    {
        $rootReal = realpath($this->rootDir);
        $pendingReal = realpath($this->pendingDir);
        if (!is_dir($this->pendingDir) || is_link($this->pendingDir)
            || $rootReal === false || $pendingReal === false
            || $pendingReal !== $rootReal . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'updater-pending'
        ) {
            throw new RuntimeException('pending_directory');
        }
        $items = @scandir($this->pendingDir);
        $allowed = ['.', '..'];
        foreach (self::TARGETS as $definition) {
            $allowed[] = $definition['metadata_file'];
            $allowed[] = $definition['pending_file'];
        }
        if (!is_array($items)) {
            throw new RuntimeException('pending_directory');
        }
        sort($items);
        sort($allowed);
        if ($items === ['.', '..'] || array_diff($items, $allowed) !== []) {
            throw new RuntimeException('pending_directory_contents');
        }
    }

    private function readMetadata(string $path, string $expectedTarget): array
    {
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
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
            || ($metadata['target'] ?? null) !== $expectedTarget
            || !isset(self::TARGETS[$metadata['target'] ?? ''])
            || !is_string($metadata['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/i', $metadata['sha256']) !== 1
        ) {
            throw new RuntimeException('metadata_format');
        }
        return $metadata;
    }

    private function assertSafeTarget(string $target, string $targetPath, string $targetDir, string $expectedDirectory): void
    {
        $rootReal = realpath($this->rootDir);
        $targetDirReal = realpath($targetDir);
        if (!isset(self::TARGETS[$target])
            || !is_dir($targetDir) || is_link($targetDir)
            || !is_file($targetPath) || is_link($targetPath)
            || !is_readable($targetPath) || !is_writable($targetDir)
            || $rootReal === false || $targetDirReal === false
            || $targetDirReal !== $rootReal . DIRECTORY_SEPARATOR . $expectedDirectory
        ) {
            throw new RuntimeException('current_target');
        }
    }

    private function createBackup(string $backupDir, array $bundle): void
    {
        $backupBase = dirname($backupDir);
        if (!is_dir($this->storageDir) || is_link($this->storageDir)
            || !is_dir($backupBase) || is_link($backupBase) || !is_writable($backupBase)
            || !@mkdir($backupDir, 0700)
        ) {
            throw new RuntimeException('backup_directory');
        }
        foreach ($bundle as $target => $entry) {
            $backupPath = $this->backupPath($backupDir, $target);
            if (!@mkdir(dirname($backupPath), 0700, true)
                || !@copy($entry['target_path'], $backupPath)
                || !@chmod($backupPath, $entry['permissions'])
                || !hash_equals($entry['old_sha256'], $this->hashFile($backupPath))
                || (fileperms($backupPath) & 0777) !== $entry['permissions']
            ) {
                throw new RuntimeException('backup_file');
            }
        }
    }

    private function restoreBundle(string $backupDir, array $bundle): bool
    {
        $temporary = [];
        try {
            foreach ($bundle as $target => $entry) {
                if (!isset($entry['target_path'], $entry['old_sha256'], $entry['permissions'])) {
                    return false;
                }
                $backupPath = $this->backupPath($backupDir, $target);
                if (!is_file($backupPath) || is_link($backupPath)
                    || !hash_equals($entry['old_sha256'], $this->hashFile($backupPath))
                ) {
                    return false;
                }
                $restorePath = $this->temporaryPath($target, '.tomos-updater-restore-');
                $this->copyToExclusiveTemporary($backupPath, $restorePath);
                $this->assertPhpAndHash($restorePath, $entry['old_sha256']);
                if (!@chmod($restorePath, $entry['permissions'])
                    || (fileperms($restorePath) & 0777) !== $entry['permissions']
                ) {
                    return false;
                }
                $temporary[$target] = $restorePath;
            }
            foreach ($bundle as $target => $entry) {
                if (!@rename($temporary[$target], $entry['target_path'])) {
                    return false;
                }
                unset($temporary[$target]);
            }
            foreach ($bundle as $entry) {
                $this->assertPhpAndHash($entry['target_path'], $entry['old_sha256']);
                if ((fileperms($entry['target_path']) & 0777) !== $entry['permissions']) {
                    return false;
                }
            }
            return true;
        } catch (Throwable $exception) {
            return false;
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
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
        ) {
            throw new RuntimeException('php_file');
        }
        $this->assertPhpSyntax($path);
        if (!hash_equals(strtolower($expectedHash), $this->hashFile($path))) {
            throw new RuntimeException('php_or_hash');
        }
    }

    private function assertPhpSyntax(string $path): void
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('php_syntax_environment');
        }
        $binary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $process = @proc_open(
            escapeshellarg($binary) . ' -l ' . escapeshellarg($path),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('php_syntax_environment');
        }
        foreach ([1, 2] as $pipe) {
            if (isset($pipes[$pipe]) && is_resource($pipes[$pipe])) {
                stream_get_contents($pipes[$pipe]);
                fclose($pipes[$pipe]);
            }
        }
        if (proc_close($process) !== 0) {
            throw new RuntimeException('php_syntax');
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

    private function backupPath(string $backupDir, string $target): string
    {
        if (!isset(self::TARGETS[$target])) {
            throw new RuntimeException('target_whitelist');
        }
        return $backupDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $target);
    }

    private function temporaryPath(string $target, string $prefix): string
    {
        if (!isset(self::TARGETS[$target])) {
            throw new RuntimeException('target_whitelist');
        }
        $path = $this->rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $target);
        return dirname($path) . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(16)) . '.tmp';
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

    private function targetMeta(array $bundle): array
    {
        $result = [];
        foreach ($bundle as $target => $entry) {
            $result[$target] = [
                'previous_sha256' => (string) ($entry['old_sha256'] ?? ''),
                'target_sha256' => (string) ($entry['expected_sha256'] ?? ''),
                'installed_sha256' => (string) ($entry['installed_sha256'] ?? ''),
                'previous_permissions' => isset($entry['permissions']) && $entry['permissions'] > 0
                    ? sprintf('%04o', $entry['permissions'])
                    : '',
                'applied' => (bool) ($entry['changed'] ?? false),
                'no_change' => !(bool) ($entry['changed'] ?? false),
            ];
        }
        return $result;
    }

    private function resultMeta(
        string $startedAt,
        string $backupId,
        array $targets,
        bool $ok,
        string $stage,
        bool $rollbackAttempted,
        bool $rollbackSucceeded
    ): array {
        return [
            'type' => 'updater_self_update',
            'started_at' => $startedAt,
            'finished_at' => gmdate('c'),
            'backup_id' => $backupId,
            'target' => 'bundle',
            'targets' => $targets,
            'result' => $ok ? 'success' : 'failure',
            'stage' => $stage,
            'rollback_attempted' => $rollbackAttempted,
            'rollback_succeeded' => $rollbackSucceeded,
        ];
    }

    private function publicResult(string $backupId, array $targets, bool $recordingOk, bool $cleanupOk): array
    {
        $primary = $targets['update/index.php'] ?? reset($targets);
        $applied = false;
        foreach ($targets as $target) {
            if (!empty($target['applied'])) {
                $applied = true;
                break;
            }
        }
        return [
            'ok' => true,
            'applied' => $applied,
            'backup_id' => $backupId,
            'previous_sha256' => (string) ($primary['previous_sha256'] ?? ''),
            'sha256' => (string) ($primary['installed_sha256'] ?? ''),
            'recording_ok' => $recordingOk,
            'cleanup_ok' => $cleanupOk,
            'targets' => $targets,
        ];
    }

    private function recordOutcome(string $backupDir, array $meta): bool
    {
        $backupMetaOk = $backupDir === '' || $this->writeMeta($backupDir, $meta);
        return $backupMetaOk && $this->writeResultMeta($meta) && $this->writeLog($meta);
    }

    private function writeMeta(string $backupDir, array $meta): bool
    {
        if (!is_dir($backupDir) || is_link($backupDir)) {
            return false;
        }
        $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return is_string($json) && @file_put_contents(
            $backupDir . DIRECTORY_SEPARATOR . 'update-meta.json', $json, LOCK_EX
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
            $directory . DIRECTORY_SEPARATOR . gmdate('Y-m') . '.log', $json . PHP_EOL, FILE_APPEND | LOCK_EX
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
            $directory . DIRECTORY_SEPARATOR . $backupId . '.json', $json, LOCK_EX
        ) !== false;
    }

    private function removePendingFiles(string $backupId, array $bundle): bool
    {
        if (!is_dir($this->pendingDir) || is_link($this->pendingDir)) {
            return false;
        }
        $temporaryBase = $this->storageDir . DIRECTORY_SEPARATOR . 'update-tmp';
        if (!is_dir($temporaryBase) || is_link($temporaryBase) || !is_writable($temporaryBase)) {
            return false;
        }
        $completedDir = $temporaryBase . DIRECTORY_SEPARATOR . $backupId . '-complete';
        if (file_exists($completedDir) || is_link($completedDir) || !@rename($this->pendingDir, $completedDir)) {
            return false;
        }
        $allOk = true;
        foreach ($bundle as $entry) {
            $definition = self::TARGETS[$entry['target']];
            $allOk = @unlink($completedDir . DIRECTORY_SEPARATOR . $definition['pending_file']) && $allOk;
            $allOk = @unlink($completedDir . DIRECTORY_SEPARATOR . $definition['metadata_file']) && $allOk;
        }
        return $allOk && @rmdir($completedDir);
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
