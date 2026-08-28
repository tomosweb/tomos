<?php

declare(strict_types=1);

namespace Tomos;

use Throwable;

final class ThemeSettingsConfigWriter
{
    public static function load(string $settingsPath): array
    {
        if (!is_file($settingsPath) || !is_readable($settingsPath)) {
            return [[], []];
        }

        try {
            if (!defined('TOMOS_THEME_SETTINGS_CONTEXT')) {
                define('TOMOS_THEME_SETTINGS_CONTEXT', true);
            }
            $loaded = require $settingsPath;
        } catch (Throwable $exception) {
            return [[], ['現在のテーマ設定を読み込めませんでした。既存設定を維持しました。']];
        }

        if (!is_array($loaded)) {
            return [[], ['テーマ設定の形式が正しくありません。既存設定を維持しました。']];
        }

        return [$loaded, []];
    }

    public static function update(array $currentSettings, array $input, array $autoItems): array
    {
        $mode = $input['navigation_mode'] ?? '';
        if (!is_string($mode) || !in_array($mode, ['auto', 'manual'], true)) {
            return [$currentSettings, ['ナビゲーションモードが正しくありません。']];
        }

        $submittedItems = $input['navigation_items'] ?? [];
        if (!is_array($submittedItems)) {
            return [$currentSettings, ['ナビゲーション項目の形式が正しくありません。']];
        }

        $allowed = [];
        foreach ($autoItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $path = self::normalizePath($item['path'] ?? null);
            if ($path !== '') {
                $allowed[$path] = (string) ($item['label'] ?? '');
            }
        }

        $items = [];
        $seen = [];
        foreach ($submittedItems as $submitted) {
            if (!is_array($submitted)) {
                return [$currentSettings, ['ナビゲーション項目の形式が正しくありません。']];
            }

            $path = self::normalizePath($submitted['path'] ?? null);
            if ($path === '' || !isset($allowed[$path])) {
                return [$currentSettings, ['利用できないナビゲーション先が指定されています。']];
            }
            if (isset($seen[$path])) {
                return [$currentSettings, ['同じナビゲーション先を複数登録できません。']];
            }
            $seen[$path] = true;

            $label = $submitted['label'] ?? '';
            if (!is_string($label)) {
                return [$currentSettings, ['ナビゲーションラベルの形式が正しくありません。']];
            }
            $hidden = $submitted['hidden'] ?? null;
            if ($hidden !== null && (!is_string($hidden) || $hidden !== '1')) {
                return [$currentSettings, ['ナビゲーション表示設定の形式が正しくありません。']];
            }

            $items[] = [
                'path' => $path,
                'label' => self::cleanText($label, 120),
                'hidden' => $hidden === '1',
            ];
        }

        $newSettings = $currentSettings;
        $newSettings['navigation'] = [
            'mode' => $mode,
            'items' => $items,
        ];

        return [$newSettings, []];
    }

    public static function write(string $settingsPath, array $currentSettings, array $newSettings, string $rootDir): bool
    {
        $expected = self::fingerprint($currentSettings);
        try {
            return ConfigWriteLock::run($rootDir, static function () use ($settingsPath, $expected, $newSettings): bool {
                [$latest, $errors] = self::load($settingsPath);
                if ($errors !== [] || !hash_equals($expected, self::fingerprint($latest))) {
                    return false;
                }

                $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($newSettings, true) . ";\n";
                $tmpPath = $settingsPath . '.tmp-' . bin2hex(random_bytes(8));
                if (@file_put_contents($tmpPath, $content, LOCK_EX) === false) {
                    @unlink($tmpPath);
                    return false;
                }
                $mode = @fileperms($settingsPath);
                if (is_int($mode)) {
                    @chmod($tmpPath, $mode & 0777);
                }
                if (!@rename($tmpPath, $settingsPath)) {
                    @unlink($tmpPath);
                    return false;
                }
                return true;
            });
        } catch (Throwable $exception) {
            return false;
        }
    }

    private static function normalizePath($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '' || strpos($value, '?') !== false || strpos($value, '#') !== false) {
            return '';
        }
        $result = Security::validateUrlPath($value);
        if (empty($result['is_valid'])) {
            return '';
        }
        $path = (string) $result['path'];
        return $path === '/' || substr($path, -1) !== '/' ? $path : rtrim($path, '/') . '/';
    }

    private static function cleanText(string $value, int $limit): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }
        return substr($value, 0, $limit);
    }

    private static function fingerprint(array $settings): string
    {
        return hash('sha256', serialize($settings));
    }
}
