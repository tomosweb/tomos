<?php

declare(strict_types=1);

namespace Tomos;

use Throwable;

final class ThemePackageDeployment
{
    private const DEPLOY_LOCK_TTL = 600;

    private string $rootDir;
    private string $themesDir;
    private string $candidateRoot;
    private string $candidateThemesDir;
    private string $deployLockPath;
    private ThemePackageInstaller $installer;
    private ThemePackagePolicy $policy;

    public function __construct(string $rootDir, string $themesDir, string $owner)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->themesDir = rtrim($themesDir, DIRECTORY_SEPARATOR);
        $ownerHash = hash('sha256', $owner);
        $this->candidateRoot = $this->themesDir . DIRECTORY_SEPARATOR . '.tomos-theme-candidates';
        $this->candidateThemesDir = $this->candidateRoot . DIRECTORY_SEPARATOR . $ownerHash;
        $this->deployLockPath = $this->rootDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'theme-deploy.lock';
        $this->policy = new ThemePackagePolicy();

        $this->ensureCandidateDirectory();
        $this->installer = new ThemePackageInstaller($this->rootDir, $this->candidateThemesDir);
    }

    public function diagnostics(): array
    {
        return $this->installer->diagnostics();
    }

    public function uploadLimit(): array
    {
        return $this->installer->uploadLimit();
    }

    public function cleanupStaleTemporaryFiles(): void
    {
        $this->installer->cleanupStaleTemporaryFiles();
        $this->cleanupEmptyCandidateDirectory();
        $this->ensureCandidateDirectory();
    }

    public function discard(string $id): bool
    {
        return $this->installer->discard($id);
    }

    public function stageUpload(array $upload, string $owner): array
    {
        $summary = $this->installer->stageUpload($upload, $owner);
        $summary = $this->withInstalledThemeContext($summary);
        if (($summary['version_relation'] ?? '') === 'older') {
            $packageId = (string) ($summary['package_id'] ?? '');
            if ($packageId !== '') {
                $this->installer->discard($packageId);
            }
            throw new ThemePackageException('現在より古いversionのテーマへ更新できません。', 'version_downgrade');
        }
        return $summary;
    }

    public function apply(string $id, string $owner): array
    {
        $candidateResult = $this->installer->apply($id, $owner);
        $themeId = (string) ($candidateResult['theme_id'] ?? '');
        if (!ThemePackagePolicy::isThemeId($themeId)) {
            $this->cleanupCandidateTheme($themeId);
            throw new ThemePackageException('テーマ更新の準備結果を確認できませんでした。', 'candidate');
        }

        $candidate = $this->candidateThemesDir . DIRECTORY_SEPARATOR . $themeId;
        if (!is_dir($candidate) || is_link($candidate)) {
            $this->cleanupCandidateTheme($themeId);
            throw new ThemePackageException('テーマ更新の準備結果を確認できませんでした。', 'candidate');
        }

        $lockHandle = null;
        $backup = '';
        $failedTarget = '';
        $target = $this->themesDir . DIRECTORY_SEPARATOR . $themeId;
        $targetInstalled = false;
        $hadExisting = false;
        $previousVersion = '';
        $cleanupWarning = !empty($candidateResult['cleanup_warning']);

        try {
            $lockHandle = $this->acquireDeployLock($owner);
            $installed = $this->installedThemeInfo($themeId);
            $hadExisting = $installed !== null;
            $previousVersion = $hadExisting ? (string) $installed['version'] : '';

            $candidateValidation = $this->policy->validateExtracted($this->candidateThemesDir, $themeId);
            $version = (string) $candidateValidation['version'];
            $versionRelation = $this->versionRelation($previousVersion, $version);
            if ($versionRelation === 'older') {
                throw new ThemePackageException('現在より古いversionのテーマへ更新できません。', 'version_downgrade');
            }

            if ($hadExisting) {
                $backup = $this->themesDir . DIRECTORY_SEPARATOR . '.tomos-theme-backup-' . bin2hex(random_bytes(12));
                if (!@rename($target, $backup)) {
                    throw new ThemePackageException('既存テーマを更新用バックアップへ移動できませんでした。themesフォルダの書き込み権限を確認してください。', 'backup');
                }
            }

            if (!@rename($candidate, $target)) {
                if ($hadExisting && $backup !== '' && !@rename($backup, $target)) {
                    throw new ThemePackageException('テーマ更新に失敗し、既存テーマの自動復元も完了できませんでした。管理者による確認が必要です。', 'rollback');
                }
                if ($hadExisting) {
                    $backup = '';
                }
                throw new ThemePackageException('テーマを配置できませんでした。themesフォルダの書き込み権限を確認してください。', 'rename');
            }
            $targetInstalled = true;

            $finalValidation = $this->policy->validateExtracted($this->themesDir, $themeId);
            if ((string) $finalValidation['version'] !== $version) {
                throw new ThemePackageException('更新後のテーマ検証に失敗しました。', 'post_validation');
            }

            if ($backup !== '' && (file_exists($backup) || is_link($backup))) {
                if (!$this->removeTreeWithRetry($backup)) {
                    $cleanupWarning = true;
                    error_log('[Tomos theme deploy] backup cleanup failed theme=' . $themeId);
                } else {
                    $backup = '';
                }
            }

            $result = [
                'operation' => $hadExisting ? 'update' : 'install',
                'theme_id' => $themeId,
                'display_name' => (string) $finalValidation['display_name'],
                'version' => (string) $finalValidation['version'],
                'previous_version' => $previousVersion,
                'version_relation' => $versionRelation,
                'warnings' => is_array($finalValidation['warnings'] ?? null) ? $finalValidation['warnings'] : [],
                'cleanup_warning' => $cleanupWarning,
            ];

            $this->cleanupEmptyCandidateDirectory();
            return $result;
        } catch (Throwable $exception) {
            if ($targetInstalled) {
                $failedTarget = $this->candidateThemesDir . DIRECTORY_SEPARATOR . '.failed-' . $themeId . '-' . bin2hex(random_bytes(6));
                if (!@rename($target, $failedTarget) && !$this->removeTreeWithRetry($target)) {
                    $this->logFailure('rollback-remove-new', $themeId, $exception);
                    throw new ThemePackageException('テーマ更新に失敗し、新しいテーマを安全に退避できませんでした。管理者による確認が必要です。', 'rollback');
                }
            }

            if ($hadExisting && $backup !== '' && (file_exists($backup) || is_link($backup))) {
                if (!@rename($backup, $target)) {
                    $this->logFailure('rollback-restore-old', $themeId, $exception);
                    throw new ThemePackageException('テーマ更新に失敗し、既存テーマの自動復元も完了できませんでした。管理者による確認が必要です。', 'rollback');
                }
                $backup = '';
            }

            if ($failedTarget !== '' && (file_exists($failedTarget) || is_link($failedTarget))) {
                if (!$this->removeTreeWithRetry($failedTarget)) {
                    error_log('[Tomos theme deploy] failed target cleanup failed theme=' . $themeId);
                }
                $failedTarget = '';
            }
            if (is_dir($candidate) && !is_link($candidate) && !$this->removeTreeWithRetry($candidate)) {
                error_log('[Tomos theme deploy] candidate cleanup failed theme=' . $themeId);
            }

            $this->logFailure('deploy', $themeId, $exception);
            if ($exception instanceof ThemePackageException) {
                throw $exception;
            }
            throw new ThemePackageException('テーマを追加・更新できませんでした。もう一度お試しください。', 'unexpected');
        } finally {
            $this->releaseDeployLock($lockHandle);
            $this->cleanupEmptyCandidateDirectory();
        }
    }

    private function withInstalledThemeContext(array $summary): array
    {
        $themeId = (string) ($summary['theme_id'] ?? '');
        $installed = $this->installedThemeInfo($themeId);
        $previousVersion = $installed !== null ? (string) $installed['version'] : '';
        $version = (string) ($summary['version'] ?? '');

        $summary['operation'] = $installed === null ? 'install' : 'update';
        $summary['previous_version'] = $previousVersion;
        $summary['version_relation'] = $this->versionRelation($previousVersion, $version);

        if ($installed !== null && $summary['version_relation'] === 'same') {
            $summary['warnings'][] = '同じversionのテーマを再インストールします。制作中の調整などで同一versionを再配置する場合は、このまま続行できます。';
        }

        return $summary;
    }

    private function installedThemeInfo(string $themeId): ?array
    {
        if (!ThemePackagePolicy::isThemeId($themeId)) {
            throw new ThemePackageException('テーマIDが正しくありません。', 'theme_id');
        }

        $target = $this->themesDir . DIRECTORY_SEPARATOR . $themeId;
        if (!file_exists($target) && !is_link($target)) {
            return null;
        }
        if (!is_dir($target) || is_link($target)) {
            throw new ThemePackageException('同じテーマIDの既存パスを安全なテーマとして確認できないため更新できません。管理者に確認してください。', 'existing_theme');
        }

        return $this->policy->validateExtracted($this->themesDir, $themeId);
    }

    private function versionRelation(string $installedVersion, string $newVersion): string
    {
        if ($installedVersion === '') {
            return 'new';
        }
        $comparison = version_compare($newVersion, $installedVersion);
        if ($comparison > 0) {
            return 'newer';
        }
        if ($comparison < 0) {
            return 'older';
        }
        return 'same';
    }

    private function ensureCandidateDirectory(): void
    {
        if (!is_dir($this->themesDir) || !is_writable($this->themesDir)) {
            return;
        }
        if (!is_dir($this->candidateRoot) && !@mkdir($this->candidateRoot, 0700)) {
            return;
        }
        @chmod($this->candidateRoot, 0700);
        if (!is_dir($this->candidateThemesDir) && !@mkdir($this->candidateThemesDir, 0700)) {
            return;
        }
        @chmod($this->candidateThemesDir, 0700);
    }

    private function cleanupCandidateTheme(string $themeId): void
    {
        if (!ThemePackagePolicy::isThemeId($themeId)) {
            return;
        }
        $this->removeTreeWithRetry($this->candidateThemesDir . DIRECTORY_SEPARATOR . $themeId);
        $this->cleanupEmptyCandidateDirectory();
    }

    private function cleanupEmptyCandidateDirectory(): void
    {
        $this->removeDirectoryIfEmpty($this->candidateThemesDir);
        $this->removeDirectoryIfEmpty($this->candidateRoot);
    }

    private function removeDirectoryIfEmpty(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        $items = @scandir($path);
        if (is_array($items) && count($items) === 2) {
            @rmdir($path);
        }
    }

    private function acquireDeployLock(string $owner)
    {
        $handle = @fopen($this->deployLockPath, 'x');
        if (!is_resource($handle)) {
            $stale = @fopen($this->deployLockPath, 'r+');
            if (is_resource($stale) && @flock($stale, LOCK_EX | LOCK_NB)) {
                $raw = stream_get_contents($stale);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                $startedAt = is_array($decoded) ? (int) ($decoded['started_at'] ?? 0) : 0;
                if ($startedAt > 0 && $startedAt < time() - self::DEPLOY_LOCK_TTL) {
                    @unlink($this->deployLockPath);
                }
                @flock($stale, LOCK_UN);
                fclose($stale);
                $handle = @fopen($this->deployLockPath, 'x');
            } elseif (is_resource($stale)) {
                fclose($stale);
            }
        }

        if (!is_resource($handle) || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new ThemePackageException('別のテーマ追加・更新処理が実行中です。しばらくしてからもう一度お試しください。', 'deploy_lock');
        }

        $payload = json_encode([
            'started_at' => time(),
            'owner_hash' => hash('sha256', $owner),
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || fwrite($handle, $payload) === false) {
            @flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($this->deployLockPath);
            throw new ThemePackageException('テーマ追加・更新用の排他制御を開始できませんでした。', 'deploy_lock');
        }
        @chmod($this->deployLockPath, 0600);
        return $handle;
    }

    private function releaseDeployLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        @unlink($this->deployLockPath);
        @flock($handle, LOCK_UN);
        fclose($handle);
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

    private function logFailure(string $operation, string $themeId, Throwable $exception): void
    {
        $stage = $exception instanceof ThemePackageException ? $exception->stage() : 'unexpected';
        error_log('[Tomos theme deploy] operation=' . $operation . ' stage=' . $stage . ' theme=' . $themeId);
    }
}
