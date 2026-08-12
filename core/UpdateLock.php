<?php

declare(strict_types=1);

namespace Tomos;

final class UpdateLock
{
    public const STALE_AFTER_SECONDS = 1800;

    public static function path(string $rootDir): string
    {
        return $rootDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'update.lock';
    }

    public static function isActive(string $rootDir): bool
    {
        $path = self::path($rootDir);
        if (!is_file($path)) {
            return false;
        }

        $data = json_decode((string) @file_get_contents($path), true);
        $started = is_array($data) ? strtotime((string) ($data['started_at'] ?? '')) : false;
        if ($started !== false && time() - $started > self::STALE_AFTER_SECONDS) {
            return false;
        }

        return true;
    }
}
