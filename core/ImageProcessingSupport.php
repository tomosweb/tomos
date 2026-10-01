<?php

declare(strict_types=1);

namespace Tomos;

/** Shared image inspection, memory estimates, and protected diagnostics. */
final class ImageProcessingSupport
{
    private const MEMORY_RESERVE_BYTES = 16 * 1024 * 1024;
    private const LOG_MAX_BYTES = 1048576;

    /** @return array{bytes:int,mime:string,width:int,height:int,orientation:int} */
    public static function inspect(string $path): array
    {
        $info = @getimagesize($path);
        $mime = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';
        return [
            'bytes' => max(0, (int) (@filesize($path) ?: 0)),
            'mime' => $mime,
            'width' => is_array($info) ? max(0, (int) ($info[0] ?? 0)) : 0,
            'height' => is_array($info) ? max(0, (int) ($info[1] ?? 0)) : 0,
            'orientation' => $mime === 'image/jpeg' ? self::jpegOrientation($path) : 1,
        ];
    }

    public static function jpegOrientation(string $path): int
    {
        if (!extension_loaded('exif') || !function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data($path);
        if (!is_array($exif)) {
            return 1;
        }
        $orientation = isset($exif['Orientation'])
            ? (int) $exif['Orientation']
            : (int) ($exif['IFD0']['Orientation'] ?? 1);
        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * Estimate a conservative peak for a GD decode, resize canvas, and optional
     * small-canvas orientation copies. This deliberately excludes Imagick and
     * other optional host extensions so the guard remains portable.
     */
    public static function estimateGdPeakBytes(int $width, int $height, int $maxEdge, bool $needsOrientation): int
    {
        if ($width <= 0 || $height <= 0 || $maxEdge <= 0) {
            return PHP_INT_MAX;
        }
        if ($width > intdiv(PHP_INT_MAX, $height)) {
            return PHP_INT_MAX;
        }
        $pixels = $width * $height;
        if ($pixels > intdiv(PHP_INT_MAX, 4)) {
            return PHP_INT_MAX;
        }
        $longEdge = max($width, $height);
        $scale = $longEdge > $maxEdge ? $maxEdge / $longEdge : 1.0;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $sourceBytes = $pixels * 4;
        $targetBytes = $targetWidth * $targetHeight * 4;
        // ImageProcessor's defensive orientation path can hold the source,
        // current canvas, working copy, and rotated canvas at once.
        $bufferBytes = $sourceBytes + $targetBytes + ($needsOrientation ? 2 * $targetBytes : 0);
        return (int) ceil($bufferBytes * 1.8);
    }

    /** @return array{allowed:bool,estimated_bytes:int,usage_bytes:int,limit_bytes:int,reserve_bytes:int} */
    public static function assessGdMemory(int $estimatedBytes): array
    {
        $usage = memory_get_usage(true);
        $limit = self::memoryLimitBytes((string) ini_get('memory_limit'));
        $allowed = $limit <= 0 || ($usage + $estimatedBytes + self::MEMORY_RESERVE_BYTES < $limit);
        return [
            'allowed' => $allowed,
            'estimated_bytes' => max(0, $estimatedBytes),
            'usage_bytes' => $usage,
            'limit_bytes' => $limit,
            'reserve_bytes' => self::MEMORY_RESERVE_BYTES,
        ];
    }

    /** @param array<string,int|string|bool> $details */
    public static function log(string $path, string $component, string $event, array $details = []): void
    {
        $entry = [
            'time' => gmdate('c'),
            'component' => $component,
            'event' => $event,
            'details' => $details,
        ];
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($line)) {
            return;
        }
        error_log('[Tomos image] ' . $line);
        if ($path === '') {
            return;
        }
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle) || !@flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            return;
        }
        $stat = @fstat($handle);
        if (is_array($stat) && (int) ($stat['size'] ?? 0) > self::LOG_MAX_BYTES) {
            @ftruncate($handle, 0);
            @rewind($handle);
        } else {
            @fseek($handle, 0, SEEK_END);
        }
        @fwrite($handle, $line . "\n");
        @fflush($handle);
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    private static function memoryLimitBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        if ($unit === 'g') {
            $number *= 1024;
            $unit = 'm';
        }
        if ($unit === 'm') {
            $number *= 1024;
            $unit = 'k';
        }
        if ($unit === 'k') {
            $number *= 1024;
        }
        return $number > 0 ? (int) $number : -1;
    }
}
