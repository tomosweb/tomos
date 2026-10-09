<?php

declare(strict_types=1);

namespace Tomos;

/**
 * Inspects a user-supplied theme ZIP without extracting or executing content.
 * The returned entries are normalized repository-relative paths.
 */
final class ThemeZipInspector
{
    public static function inspect(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZIP support is not available.');
        }
        if (!is_file($zipPath) || filesize($zipPath) > 10485760) {
            throw new \InvalidArgumentException('ZIP must be a file of 10 MiB or less.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \InvalidArgumentException('Cannot open theme ZIP.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 300) {
                throw new \InvalidArgumentException('Invalid number of ZIP entries.');
            }
            $files = [];
            $total = 0;
            foreach (range(0, $zip->numFiles - 1) as $index) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) {
                    throw new \InvalidArgumentException('Invalid ZIP entry.');
                }
                $name = (string) $stat['name'];
                if (strpos($name, chr(0)) !== false || strpos($name, '\\') !== false ||
                    $name === '' || $name[0] === '/' ||
                    preg_match('~(^|/)\\.\\.(/|$)|(^|/)\\.(/|$)|^[A-Za-z]:~', $name)) {
                    throw new \InvalidArgumentException('Unsafe ZIP entry path.');
                }
                $isDir = substr($name, -1) === '/';
                $parts = explode('/', rtrim($name, '/'));
                if (count($parts) > 12) {
                    throw new \InvalidArgumentException('Theme ZIP nesting is too deep.');
                }
                $normalized = implode('/', $parts);
                if (isset($files[$normalized])) {
                    throw new \InvalidArgumentException('Duplicate ZIP entry.');
                }
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
                    $type = ($attributes >> 16) & 0170000;
                    if ($type === 0120000 || ($type !== 0 && $type !== 0100000 && $type !== 0040000)) {
                        throw new \InvalidArgumentException('Links and special files are not allowed.');
                    }
                }
                if ($isDir) {
                    continue;
                }
                $size = (int) $stat['size'];
                if ($size < 0 || $size > 2097152) {
                    throw new \InvalidArgumentException('Theme file exceeds 2 MiB.');
                }
                $total += $size;
                if ($total > 20971520) {
                    throw new \InvalidArgumentException('Uncompressed theme exceeds 20 MiB.');
                }
                if (preg_match('/\\.(?:php[0-9]?|phtml|phar)$/i', $normalized)) {
                    throw new \InvalidArgumentException('PHP files are not allowed in theme ZIP.');
                }
                $files[$normalized] = $index;
            }

            // Accept either direct theme contents or a single enclosing directory.
            $roots = [];
            foreach (array_keys($files) as $name) {
                $roots[] = explode('/', $name, 2)[0];
            }
            $roots = array_unique($roots);
            $prefix = count($roots) === 1 ? reset($roots) . '/' : '';
            if ($prefix !== '' && isset($files['theme.json'])) {
                $prefix = '';
            }
            $manifest = $prefix . 'theme.json';
            if (!isset($files[$manifest])) {
                throw new \InvalidArgumentException('theme.json is required.');
            }
            $json = $zip->getFromIndex($files[$manifest], 65537);
            if (!is_string($json) || strlen($json) > 65536) {
                throw new \InvalidArgumentException('Invalid theme.json.');
            }
            $meta = json_decode($json, true);
            $themeName = is_array($meta) ? (string) ($meta['name'] ?? '') : '';
            if (!preg_match('/\\A[A-Za-z0-9_-]+\\z/', $themeName) ||
                trim((string) ($meta['display_name'] ?? '')) === '' ||
                trim((string) ($meta['version'] ?? '')) === '') {
                throw new \InvalidArgumentException('Invalid theme metadata.');
            }
            $normalizedFiles = [];
            foreach ($files as $path => $index) {
                if ($prefix !== '' && strpos($path, $prefix) !== 0) {
                    throw new \InvalidArgumentException('Unexpected ZIP root.');
                }
                $relative = substr($path, strlen($prefix));
                $normalizedFiles[$relative] = $index;
            }
            require_once __DIR__ . '/ThemeRules.php';
            foreach (ThemeRules::requiredFiles() as $required) {
                if (!isset($normalizedFiles[$required])) {
                    throw new \InvalidArgumentException('Missing required theme file: ' . $required);
                }
            }
            return [
                'name' => $themeName,
                'display_name' => $meta['display_name'],
                'version' => $meta['version'],
                'entries' => $normalizedFiles,
                'uncompressed_bytes' => $total,
            ];
        } finally {
            $zip->close();
        }
    }
}
