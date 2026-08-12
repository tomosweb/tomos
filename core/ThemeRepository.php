<?php

declare(strict_types=1);

namespace Tomos;

final class ThemeRepository
{
    private string $themesDir;
    private ThemeValidator $validator;

    public function __construct(string $themesDir, ?ThemeValidator $validator = null)
    {
        $this->themesDir = rtrim($themesDir, DIRECTORY_SEPARATOR);
        $this->validator = $validator ?? new ThemeValidator($this->themesDir);
    }

    public function all(): array
    {
        $themes = [];
        if (!is_dir($this->themesDir)) {
            return $themes;
        }

        $items = scandir($this->themesDir);
        if ($items === false) {
            return $themes;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item[0] === '.') {
                continue;
            }

            if (!is_dir($this->themesDir . DIRECTORY_SEPARATOR . $item)) {
                continue;
            }

            $result = $this->validator->validate($item);
            $theme = is_array($result['theme'] ?? null) ? $result['theme'] : [];
            $themes[$item] = [
                'directory' => $item,
                'name' => (string) ($theme['name'] ?? $item),
                'display_name' => (string) ($theme['display_name'] ?? $item),
                'version' => (string) ($theme['version'] ?? ''),
                'description' => (string) ($theme['description'] ?? ''),
                'author' => (string) ($theme['author'] ?? ''),
                'supports' => is_array($theme['supports'] ?? null) ? $theme['supports'] : [],
                'is_valid' => !empty($result['valid']),
                'valid' => !empty($result['valid']),
                'errors' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
                'warnings' => is_array($result['warnings'] ?? null) ? $result['warnings'] : [],
                'theme' => $result['theme'] ?? null,
            ];
        }

        ksort($themes, SORT_NATURAL);
        return $themes;
    }

    public function validThemes(): array
    {
        return array_filter($this->all(), function (array $result): bool {
            return !empty($result['valid']);
        });
    }

    public function invalidThemes(): array
    {
        return array_filter($this->all(), function (array $result): bool {
            return empty($result['valid']);
        });
    }
}
