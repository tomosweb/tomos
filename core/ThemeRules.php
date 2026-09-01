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
}
