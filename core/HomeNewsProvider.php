<?php

declare(strict_types=1);

namespace Tomos;

final class HomeNewsProvider
{
    private array $pages;
    private array $settings;
    private string $publicBasePath;

    public function __construct(array $pages, array $settings, string $publicBasePath = '')
    {
        $this->pages = $pages;
        $this->settings = $settings;
        $this->publicBasePath = $publicBasePath;
    }

    public static function fromConfig(array $config, ThemeSettings $themeSettings, string $publicBasePath = ''): self
    {
        $pages = [];
        $features = is_array($config['features'] ?? null) ? $config['features'] : [];
        $metadataCacheEnabled = !array_key_exists('metadata_cache', $features) || !empty($features['metadata_cache']);
        if ($metadataCacheEnabled) {
            $cacheDir = rtrim((string) ($config['paths']['cache_dir'] ?? ''), DIRECTORY_SEPARATOR);
            $indexFile = $cacheDir . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'pages.json';
            if (is_file($indexFile) && is_readable($indexFile)) {
                $json = @file_get_contents($indexFile);
                $decoded = $json === false ? null : json_decode($json, true);
                if (is_array($decoded)) {
                    $pages = $decoded;
                }
            }
        }

        return new self($pages, $themeSettings->settings(), $publicBasePath);
    }

    public function context(): array
    {
        $news = is_array($this->settings['news'] ?? null) ? $this->settings['news'] : [];
        $enabled = array_key_exists('enabled', $news) ? !empty($news['enabled']) : true;
        $path = $this->normalizePath((string) ($news['path'] ?? '/news/'));
        $limit = $this->normalizeLimit($news['limit'] ?? 5);

        if (!$enabled) {
            return [
                'has_news' => false,
                'news_items' => [],
                'news_url' => Security::publicUrl($path, $this->publicBasePath),
            ];
        }

        $items = [];
        foreach ($this->pages as $page) {
            if (!is_array($page) || !empty($page['draft'])) {
                continue;
            }

            $url = $this->normalizePageUrl((string) ($page['url'] ?? ''));
            if (!$this->isDescendantOf($url, $path)) {
                continue;
            }

            $items[] = $page;
        }

        $items = PageSorter::sort($items);
        $items = array_slice($items, 0, $limit);
        $result = [];
        foreach ($items as $page) {
            $date = $this->dateValue((string) ($page['date'] ?? ''));
            $title = trim((string) ($page['title'] ?? ''));
            $url = trim((string) ($page['url'] ?? ''));
            if ($title === '' || $url === '') {
                continue;
            }
            $result[] = [
                'date' => $date,
                'date_display' => $date,
                'title' => $title,
                'url' => Security::publicUrl($url, $this->publicBasePath),
            ];
        }

        return [
            'has_news' => $result !== [],
            'news_items' => $result,
            'news_url' => Security::publicUrl($path, $this->publicBasePath),
        ];
    }

    private function normalizePath(string $path): string
    {
        $validated = Security::validateUrlPath(trim($path));
        if (empty($validated['is_valid'])) {
            return '/news/';
        }

        $path = (string) $validated['path'];
        if ($path === '/') {
            return '/';
        }

        return rtrim($path, '/') . '/';
    }

    private function normalizePageUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $path = preg_replace('#/+#', '/', $path) ?? '/';
        return $path === '/' ? '/' : rtrim($path, '/') . '/';
    }

    private function isDescendantOf(string $url, string $path): bool
    {
        if ($path === '/') {
            return $url !== '/';
        }

        return $url !== $path && strpos($url, $path) === 0;
    }

    private function normalizeLimit($value): int
    {
        if (is_int($value)) {
            return max(1, min(10, $value));
        }
        if (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1) {
            return max(1, min(10, (int) $value));
        }
        return 5;
    }

    private function dateValue(string $value): string
    {
        $value = trim($value);
        return preg_match('/\A\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : '';
    }
}
