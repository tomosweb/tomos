<?php

declare(strict_types=1);

namespace Tomos;

final class PostUploadCapabilities
{
    public const TOMOS_IMAGE_MAX_BYTES = 10485760;
    public const MAX_IMAGES = 5;
    private const REQUEST_OVERHEAD_BYTES = 262144;
    private const SAFE_FALLBACK_BYTES = 2097152;

    /** @return array<string,mixed> */
    public static function current(): array
    {
        $uploadRaw = (string) ini_get('upload_max_filesize');
        $postRaw = (string) ini_get('post_max_size');
        $uploadMax = self::iniBytes($uploadRaw);
        $postMax = self::iniBytes($postRaw);
        $postPayloadMax = $postMax > self::REQUEST_OVERHEAD_BYTES
            ? $postMax - self::REQUEST_OVERHEAD_BYTES
            : 0;
        $knownLimits = array_values(array_filter([
            self::TOMOS_IMAGE_MAX_BYTES,
            $uploadMax,
            $postPayloadMax,
        ], static fn (int $value): bool => $value > 0));
        $settingsKnown = self::isIniSize($uploadRaw) && self::isIniSize($postRaw);
        $effective = $settingsKnown ? min($knownLimits) : min(self::TOMOS_IMAGE_MAX_BYTES, self::SAFE_FALLBACK_BYTES);

        return [
            'sequential_upload' => true,
            'chunk_upload' => true,
            'max_upload_bytes' => $uploadMax,
            'max_post_bytes' => $postMax,
            'tomos_image_max_bytes' => self::TOMOS_IMAGE_MAX_BYTES,
            'effective_image_max_bytes' => $effective,
            'max_images' => self::MAX_IMAGES,
            'image_processing' => true,
            'gd_available' => extension_loaded('gd'),
            'exif_available' => extension_loaded('exif'),
            'exif_read_data_available' => function_exists('exif_read_data'),
            'supported_formats' => ['jpeg', 'png', 'webp', 'gif'],
        ];
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(\d+(?:\.\d+)?)\s*([KMG])?$/i', $value, $matches)) {
            return 0;
        }

        $bytes = (float) $matches[1];
        $unit = strtoupper((string) ($matches[2] ?? ''));
        $powers = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3];
        return (int) floor($bytes * (1024 ** $powers[$unit]));
    }

    private static function isIniSize(string $value): bool
    {
        return preg_match('/^\s*\d+(?:\.\d+)?\s*[KMG]?\s*$/i', $value) === 1;
    }
}
