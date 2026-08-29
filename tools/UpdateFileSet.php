<?php

declare(strict_types=1);

final class UpdateFileSet
{
    public static function fromGitDiff(string $rootDir, string $fromRef, string $toRef = 'HEAD'): array
    {
        $command = 'git -C ' . escapeshellarg($rootDir)
            . ' diff --name-status --diff-filter=ACDMRT '
            . escapeshellarg($fromRef) . ' ' . escapeshellarg($toRef) . ' --';
        $lines = [];
        $status = 0;
        exec($command, $lines, $status);
        if ($status !== 0) {
            throw new RuntimeException('could not calculate update runtime diff');
        }

        $files = [];
        foreach ($lines as $line) {
            $parts = explode("\t", (string) $line);
            $kind = (string) ($parts[0] ?? '');
            $path = (string) ($parts[count($parts) - 1] ?? '');
            if ($path === '' || self::isProtectedPath($path)) {
                continue;
            }
            if ($kind === 'D') {
                if (self::isAllowedUpdatePath($path)) {
                    throw new RuntimeException('Browser Update cannot represent deleted runtime path: ' . $path);
                }
                continue;
            }
            if (!self::isAllowedUpdatePath($path)) {
                continue;
            }
            $files[$path] = true;
        }
        ksort($files);
        return array_keys($files);
    }

    public static function isProtectedPath(string $path): bool
    {
        if (in_array($path, ['config.php', 'config.sample.php', '.htaccess', 'theme-settings.php'], true)) {
            return true;
        }
        foreach ([
            'content/', 'cache/', 'storage/', 'trash/', 'update/', 'tests/', 'tools/', 'build/',
            'backups/', 'staging/', 'images/', 'uploads/', 'post-upload-sessions/',
            'security/post-auth/', 'security/post-submissions/', 'theme-assets/',
            'core/updater-pending/',
            'themes/tomos-lab/',
        ] as $prefix) {
            if (strpos($path, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    public static function packagePaths(array $runtimeFiles): array
    {
        $paths = [];
        foreach ($runtimeFiles as $path) {
            if ($path === 'update/index.php') {
                $paths[] = 'core/updater-pending/update-index.php';
                $paths[] = 'core/updater-pending/update-index.json';
            } elseif ($path === 'core/UpdateService.php') {
                $paths[] = 'core/updater-pending/update-service.php';
                $paths[] = 'core/updater-pending/update-service.json';
            } elseif ($path === 'core/UpdateLock.php') {
                $paths[] = 'core/updater-pending/update-lock.php';
                $paths[] = 'core/updater-pending/update-lock.json';
            } else {
                $paths[] = $path;
            }
        }
        sort($paths);
        return $paths;
    }

    public static function isAllowedUpdatePath(string $path): bool
    {
        if (self::isProtectedPath($path) || $path === '' || substr($path, -1) === '/') {
            return false;
        }
        if ($path === 'core/UpdateService.php' || $path === 'core/UpdateLock.php') {
            return true;
        }
        if (strpos($path, 'themes/') === 0) {
            return preg_match('#\Athemes/(tomos-90s|tomos-blog|tomos-dark|tomos-journal|tomos-minimal|tomos-note)/[A-Za-z0-9._/-]+\z#', $path) === 1;
        }
        return $path === 'VERSION'
            || $path === 'index.php'
            || preg_match('#\A(core|post|setup|assets)/[A-Za-z0-9._/-]+\z#', $path) === 1;
    }
}
