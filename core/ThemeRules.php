<?php

declare(strict_types=1);

namespace Tomos;

final class ThemeRules
{
    private static ?array $rules = null;

    public static function all(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'theme' . DIRECTORY_SEPARATOR . 'theme-rules.json';
        $contents = is_readable($path) ? file_get_contents($path) : false;
        $decoded = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($decoded)) {
            throw new \RuntimeException('Theme rules are unavailable.');
        }

        self::$rules = $decoded;
        return self::$rules;
    }

    public static function requiredFiles(): array
    {
        return self::all()['structure']['required_files'] ?? [];
    }

    public static function recommendedFiles(): array
    {
        return self::all()['structure']['recommended_files'] ?? [];
    }

    public static function canonicalJson(): string
    {
        $canonical = self::canonicalize(self::all());
        $encoded = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Theme rules could not be canonicalized.');
        }

        return $encoded;
    }

    public static function sha256(): string
    {
        return hash('sha256', self::canonicalJson());
    }

    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (self::isList($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }

        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = self::canonicalize($value[$key]);
        }

        return $result;
    }

    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $expected++) {
                return false;
            }
        }

        return true;
    }
}
