<?php

declare(strict_types=1);

namespace Tomos;

final class ThemeConfigWriter
{
    public static function updateTheme(array $currentConfig, string $themeName, string $rootDir): array
    {
        $themeName = self::normalizeThemeName($themeName);

        if ($themeName === '' || !preg_match('/\A[A-Za-z0-9_-]+\z/', $themeName)) {
            return [$currentConfig, ['テーマ名が正しくありません。']];
        }

        $validator = new ThemeValidator($rootDir . DIRECTORY_SEPARATOR . 'themes');
        $result = $validator->validate($themeName);
        if (empty($result['valid'])) {
            $errors = ['指定されたテーマは利用できません。'];
            foreach (($result['errors'] ?? []) as $error) {
                $errors[] = (string) $error;
            }
            return [$currentConfig, $errors];
        }

        $newConfig = $currentConfig;
        if (!isset($newConfig['theme']) || !is_array($newConfig['theme'])) {
            $newConfig['theme'] = [];
        }

        $newConfig['theme']['name'] = $themeName;

        return [ConfigWriter::expectUnchangedSource($currentConfig, $newConfig), []];
    }

    private static function normalizeThemeName(string $themeName): string
    {
        $themeName = preg_replace('/[\x00-\x1F\x7F]/u', '', $themeName) ?? '';
        return trim($themeName);
    }
}
