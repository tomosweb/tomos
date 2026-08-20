<?php

declare(strict_types=1);

namespace Tomos;

use Throwable;

final class ThemeSettings
{
    private string $rootDir;
    private string $settingsPath;
    private string $assetsDir;
    private ?array $settings = null;

    public function __construct(string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->settingsPath = $this->rootDir . DIRECTORY_SEPARATOR . 'theme-settings.php';
        $this->assetsDir = $this->rootDir . DIRECTORY_SEPARATOR . 'theme-assets';
    }

    public function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $defaults = $this->defaults();
        if (!is_file($this->settingsPath) || !is_readable($this->settingsPath)) {
            return $this->settings = $defaults;
        }

        try {
            if (!defined('TOMOS_THEME_SETTINGS_CONTEXT')) {
                define('TOMOS_THEME_SETTINGS_CONTEXT', true);
            }
            $loaded = require $this->settingsPath;
        } catch (Throwable $exception) {
            error_log('[Tomos theme settings] theme-settings.php could not be loaded.');
            return $this->settings = $defaults;
        }

        if (!is_array($loaded)) {
            error_log('[Tomos theme settings] theme-settings.php must return an array.');
            return $this->settings = $defaults;
        }

        $hero = is_array($loaded['hero'] ?? null) ? $loaded['hero'] : [];
        $news = is_array($loaded['news'] ?? null) ? $loaded['news'] : [];
        $design = is_array($loaded['design'] ?? null) ? $loaded['design'] : [];
        $folders = is_array($loaded['folders'] ?? null) ? $loaded['folders'] : [];

        $settings = $defaults;
        $settings['hero']['enabled'] = $this->boolValue($hero, 'enabled', $defaults['hero']['enabled']);
        $settings['hero']['image'] = $this->assetPath((string) ($hero['image'] ?? ''));
        $settings['hero']['title'] = $this->cleanText((string) ($hero['title'] ?? ''), 200);
        $settings['hero']['subtitle'] = $this->cleanText((string) ($hero['subtitle'] ?? ''), 300);
        $settings['hero']['button_label'] = $this->cleanText((string) ($hero['button_label'] ?? ''), 120);
        $settings['hero']['button_url'] = $this->internalUrl((string) ($hero['button_url'] ?? ''));

        $settings['news']['enabled'] = $this->boolValue($news, 'enabled', $defaults['news']['enabled']);
        $settings['news']['path'] = $this->internalPath((string) ($news['path'] ?? $defaults['news']['path']), $defaults['news']['path']);
        $settings['news']['limit'] = $this->limitValue($news['limit'] ?? $defaults['news']['limit']);
        $settings['news']['heading'] = $this->cleanText((string) ($news['heading'] ?? $defaults['news']['heading']), 120);
        if ($settings['news']['heading'] === '') {
            $settings['news']['heading'] = $defaults['news']['heading'];
        }
        $settings['news']['more_label'] = $this->cleanText((string) ($news['more_label'] ?? $defaults['news']['more_label']), 120);
        if ($settings['news']['more_label'] === '') {
            $settings['news']['more_label'] = $defaults['news']['more_label'];
        }

        $settings['design']['logo'] = $this->assetPath((string) ($design['logo'] ?? ''));
        $settings['design']['key_color'] = $this->colorValue((string) ($design['key_color'] ?? ''));
        $settings['folders'] = $this->folderTitles($folders);

        return $this->settings = $settings;
    }

    public function virtualFolderTitle(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        $segments = $folder === '' ? [] : explode('/', $folder);
        $fallback = (string) end($segments);
        if ($fallback === '') {
            return '';
        }

        $title = $this->settings()['folders'][$fallback]['title'] ?? null;
        return is_string($title) && $title !== '' ? $title : $fallback;
    }

    public function templateContext(string $publicBasePath): array
    {
        $settings = $this->settings();
        $heroImageUrl = $this->assetUrl((string) $settings['hero']['image'], $publicBasePath);
        $logoUrl = $this->assetUrl((string) $settings['design']['logo'], $publicBasePath);
        $buttonUrl = (string) $settings['hero']['button_url'];

        return [
            'hero_enabled' => !empty($settings['hero']['enabled']),
            'hero_image_url' => $heroImageUrl,
            'hero_title' => (string) $settings['hero']['title'],
            'hero_subtitle' => (string) $settings['hero']['subtitle'],
            'hero_button_enabled' => $buttonUrl !== '' && (string) $settings['hero']['button_label'] !== '',
            'hero_button_label' => (string) $settings['hero']['button_label'],
            'hero_button_url' => $buttonUrl === '' ? '' : Security::publicUrl($buttonUrl, $publicBasePath),
            'logo_url' => $logoUrl,
            'key_color' => (string) $settings['design']['key_color'],
            'news_enabled' => !empty($settings['news']['enabled']),
            'news_heading' => (string) $settings['news']['heading'],
            'news_more_label' => (string) $settings['news']['more_label'],
        ];
    }

    private function defaults(): array
    {
        return [
            'hero' => [
                'enabled' => false,
                'image' => '',
                'title' => '',
                'subtitle' => '',
                'button_label' => '',
                'button_url' => '',
            ],
            'news' => [
                'enabled' => true,
                'path' => '/news/',
                'limit' => 5,
                'heading' => 'NEWS',
                'more_label' => 'View all',
            ],
            'design' => [
                'logo' => '',
                'key_color' => '',
            ],
            'folders' => [],
        ];
    }

    private function folderTitles(array $folders): array
    {
        $normalized = [];
        foreach ($folders as $folder => $settings) {
            if (!is_string($folder) || !is_array($settings) || !is_string($settings['title'] ?? null)) {
                continue;
            }

            $title = $this->cleanText($settings['title'], 200);
            if ($title !== '') {
                $normalized[$folder] = ['title' => $title];
            }
        }

        return $normalized;
    }

    private function boolValue(array $values, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $values)) {
            return $default;
        }

        return is_bool($values[$key]) ? $values[$key] : $default;
    }

    private function limitValue($value): int
    {
        if (is_int($value)) {
            return max(1, min(10, $value));
        }

        if (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1) {
            return max(1, min(10, (int) $value));
        }

        return 5;
    }

    private function cleanText(string $value, int $limit): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }

        return substr($value, 0, $limit);
    }

    private function internalUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $result = Security::validateUrlPath($value);
        return !empty($result['is_valid']) ? (string) $result['path'] : '';
    }

    private function internalPath(string $value, string $default): string
    {
        $result = Security::validateUrlPath(trim($value));
        if (empty($result['is_valid'])) {
            return $default;
        }

        $path = (string) $result['path'];
        if ($path !== '/') {
            $path = rtrim($path, '/') . '/';
        }
        return $path;
    }

    private function colorValue(string $value): string
    {
        $value = trim($value);
        return preg_match('/\A#[0-9A-Fa-f]{6}\z/', $value) === 1 ? strtolower($value) : '';
    }

    private function assetPath(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '' || strlen($value) > 240 || !Security::isSafeRelativePath($value)) {
            return '';
        }
        if (preg_match('/[?#<>"|*]/u', $value) === 1) {
            return '';
        }
        if (!Security::hasAllowedExtension($value, ['png', 'jpg', 'jpeg', 'webp', 'svg'])) {
            return '';
        }

        $candidate = $this->assetsDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $value);
        if (!is_file($candidate) || !Security::isPathInside($candidate, $this->assetsDir)) {
            return '';
        }

        if (strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) === 'svg' && !$this->safeSvg($candidate)) {
            return '';
        }

        return $value;
    }

    private function assetUrl(string $relativePath, string $publicBasePath): string
    {
        if ($relativePath === '') {
            return '';
        }

        return Security::publicUrl('/theme-assets/' . $relativePath, $publicBasePath);
    }

    private function safeSvg(string $path): bool
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return false;
        }

        if (preg_match('/<script\b/i', $content) === 1) {
            return false;
        }
        if (preg_match('/\s(?:href|xlink:href)\s*=\s*["\'](?:https?:)?\/\//i', $content) === 1) {
            return false;
        }
        if (preg_match('/\s(?:href|xlink:href)\s*=\s*["\']\s*(?:javascript:|data:text\/html)/i', $content) === 1) {
            return false;
        }
        if (preg_match('/\son[a-z]+\s*=/i', $content) === 1) {
            return false;
        }

        return true;
    }
}
