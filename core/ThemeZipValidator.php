<?php

declare(strict_types=1);

namespace Tomos;

require_once __DIR__ . '/ThemeZipInspector.php';
require_once __DIR__ . '/ThemeValidator.php';

/**
 * Validates a ZIP as a Tomos theme before any GitHub repository write.
 * Never executes files from the archive.
 */
final class ThemeZipValidator
{
    public static function validate(string $zipPath): array
    {
        $summary = ThemeZipInspector::inspect($zipPath);
        $base = sys_get_temp_dir() . '/tomos-theme-verify-' . bin2hex(random_bytes(12));
        $themeDir = $base . '/' . $summary['name'];
        if (!mkdir($themeDir, 0700, true)) {
            throw new \RuntimeException('Could not create isolated theme inspection directory.');
        }
        $zip = new \ZipArchive();
        try {
            if ($zip->open($zipPath) !== true) {
                throw new \InvalidArgumentException('Cannot reopen theme ZIP.');
            }
            foreach ($summary['entries'] as $relative => $index) {
                $target = $themeDir . '/' . $relative;
                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                    throw new \RuntimeException('Could not create temporary theme directory.');
                }
                $stream = $zip->getStream($zip->getNameIndex($index));
                if (!is_resource($stream)) {
                    throw new \InvalidArgumentException('Cannot read ZIP entry.');
                }
                $out = fopen($target, 'xb');
                if (!is_resource($out)) {
                    fclose($stream);
                    throw new \RuntimeException('Could not create temporary theme file.');
                }
                try {
                    $size = 0;
                    while (!feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false) {
                            throw new \InvalidArgumentException('Cannot read ZIP entry.');
                        }
                        $size += strlen($chunk);
                        if ($size > 2097152) {
                            throw new \InvalidArgumentException('Decompressed entry exceeds limit.');
                        }
                        if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) {
                            throw new \RuntimeException('Temporary theme write failed.');
                        }
                    }
                } finally {
                    fclose($out);
                    fclose($stream);
                }
            }
            $result = (new ThemeValidator($base))->validate($summary['name']);
            if (!$result['valid']) {
                throw new \InvalidArgumentException('Theme validation failed: ' . implode('; ', $result['errors']));
            }
            return [
                'name' => $summary['name'],
                'display_name' => $summary['display_name'],
                'version' => $summary['version'],
                'warnings' => $result['warnings'],
                'entries' => $summary['entries'],
                'uncompressed_bytes' => $summary['uncompressed_bytes'],
            ];
        } finally {
            $zip->close();
            self::removeTree($base);
        }
    }

    private static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
