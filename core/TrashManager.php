<?php

declare(strict_types=1);

namespace Tomos;

if (!class_exists(__NAMESPACE__ . '\\PostBasicPage')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'PostBasicPage.php';
}

final class TrashSummary
{
    public int $count;
    public int $bytes;

    public function __construct(int $count, int $bytes)
    {
        $this->count = $count;
        $this->bytes = $bytes;
    }
}

final class TrashClearResult
{
    public bool $ok;
    /** @var string[] */
    public array $errors;
    public int $deletedCount;

    /**
     * @param string[] $errors
     */
    public function __construct(bool $ok, array $errors = [], int $deletedCount = 0)
    {
        $this->ok = $ok;
        $this->errors = $errors;
        $this->deletedCount = $deletedCount;
    }
}

final class TrashManager
{
    private string $trashDir;

    public function __construct(string $rootDir)
    {
        $this->trashDir = rtrim($rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'trash';
    }

    public function summary(): TrashSummary
    {
        $count = 0;
        $bytes = 0;
        foreach ($this->files() as $file) {
            $count++;
            $size = @filesize($file);
            if ($size !== false) {
                $bytes += (int) $size;
            }
        }

        return new TrashSummary($count, $bytes);
    }

    public function clear(): TrashClearResult
    {
        $realTrash = realpath($this->trashDir);
        if ($realTrash === false || !is_dir($realTrash)) {
            return new TrashClearResult(true, [], 0);
        }

        $deleted = 0;
        foreach ($this->files() as $file) {
            if (!$this->isInsideTrash($file) || is_link($file)) {
                continue;
            }
            if (!@unlink($file)) {
                return new TrashClearResult(false, ['trash内のファイルを削除できませんでした。'], $deleted);
            }
            $deleted++;
        }

        $this->removeEmptyDirs($realTrash);
        return new TrashClearResult(true, [], $deleted);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . 'B';
        }
        if ($bytes < 1024 * 1024) {
            return (string) round($bytes / 1024, 1) . 'KB';
        }

        return (string) round($bytes / 1024 / 1024, 1) . 'MB';
    }

    /**
     * @return string[]
     */
    private function files(): array
    {
        if (!is_dir($this->trashDir)) {
            return [];
        }

        $files = [];
        $directory = new \RecursiveDirectoryIterator($this->trashDir, \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile()) {
                continue;
            }

            $name = $file->getFilename();
            if ($name === '.htaccess' || $name === '.gitkeep') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($this->trashDir, DIRECTORY_SEPARATOR)) + 1));
            if (strpos($relative, 'content/') === 0 && PostBasicPage::isProtectedContentPath(substr($relative, strlen('content/')))) {
                continue;
            }

            $realPath = realpath($file->getPathname());
            if ($realPath !== false && $this->isInsideTrash($realPath)) {
                $files[] = $realPath;
            }
        }

        sort($files);
        return $files;
    }

    private function isInsideTrash(string $path): bool
    {
        $realPath = realpath($path);
        $realTrash = realpath($this->trashDir);
        if ($realPath === false || $realTrash === false) {
            return false;
        }

        return strpos($realPath, rtrim($realTrash, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0;
    }

    private function removeEmptyDirs(string $realTrash): void
    {
        $dirs = [];
        $directory = new \RecursiveDirectoryIterator($realTrash, \FilesystemIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directory, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isDir() && !$file->isLink()) {
                $path = $file->getPathname();
                if ($this->isInsideTrash($path)) {
                    $dirs[] = $path;
                }
            }
        }

        foreach ($dirs as $dir) {
            @rmdir($dir);
        }
    }
}
