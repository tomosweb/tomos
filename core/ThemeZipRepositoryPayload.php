<?php

declare(strict_types=1);

namespace Tomos;

require_once __DIR__ . '/ThemeZipValidator.php';

/**
 * Prepare an immutable list of GitHub Git-tree blobs for an uploaded theme.
 * No GitHub credentials, network operations or repository mutations occur here.
 * Caller must validate target-repository authorization and collision-free paths,
 * and commit all blobs in one non-force ref update against the expected HEAD.
 */
final class ThemeZipRepositoryPayload
{
    public static function prepare(string $zipPath): array
    {
        $validated = ThemeZipValidator::validate($zipPath);
        $theme = (string) $validated['name'];
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \InvalidArgumentException('Cannot reopen validated ZIP.');
        }
        try {
            $files = [];
            $total = 0;
            foreach ($validated['entries'] as $relative => $index) {
                $expected = $zip->statIndex((int) $index);
                if (!is_array($expected) || (int) $expected['size'] > 2097152) {
                    throw new \InvalidArgumentException('Theme ZIP entry changed.');
                }
                $binary = $zip->getFromIndex((int) $index);
                if (!is_string($binary) || strlen($binary) !== (int) $expected['size']) {
                    throw new \InvalidArgumentException('Unable to read theme ZIP entry.');
                }
                $total += strlen($binary);
                if ($total > 20971520) {
                    throw new \InvalidArgumentException('Theme ZIP exceeds maximum expanded size.');
                }
                $files[] = [
                    'path' => 'themes/' . $theme . '/' . $relative,
                    'mode' => '100644',
                    'type' => 'blob',
                    'encoding' => 'base64',
                    'content' => base64_encode($binary),
                ];
            }
            usort($files, static function (array $a, array $b): int {
                return strcmp($a['path'], $b['path']);
            });
            return [
                'name' => $theme,
                'display_name' => $validated['display_name'],
                'version' => $validated['version'],
                'files' => $files,
                'count' => count($files),
                'total_bytes' => $total,
                'warnings' => $validated['warnings'],
            ];
        } finally {
            $zip->close();
        }
    }
}
