<?php

declare(strict_types=1);

namespace Tomos;

final class LanguageTag
{
    public static function normalizeOrNull($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        if (strlen($value) > 35 || preg_match('/[\x00-\x1F\x7F\s"\'<>]/', $value) === 1 || strpos($value, '/') !== false || strpos($value, '\\') !== false) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/\A[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8}){0,7}\z/', $value) !== 1) {
            return null;
        }

        $parts = explode('-', $value);
        $parts[0] = strtolower($parts[0]);
        for ($index = 1; $index < count($parts); $index++) {
            if (strlen($parts[$index]) === 4 && ctype_alpha($parts[$index])) {
                $parts[$index] = ucfirst(strtolower($parts[$index]));
            } elseif ((strlen($parts[$index]) === 2 || strlen($parts[$index]) === 3) && ctype_alnum($parts[$index])) {
                $parts[$index] = strtoupper($parts[$index]);
            } else {
                $parts[$index] = strtolower($parts[$index]);
            }
        }

        return implode('-', $parts);
    }

    public static function fallback($value, string $fallback = 'ja'): string
    {
        return self::normalizeOrNull($value) ?? (self::normalizeOrNull($fallback) ?? 'ja');
    }
}
