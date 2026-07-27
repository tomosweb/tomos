<?php

declare(strict_types=1);

namespace Tomos;

final class SitemapGenerator
{
    private array $pages;
    private string $siteUrl;
    private string $publicBasePath;

    public function __construct(array $pages, string $siteUrl, string $publicBasePath = '')
    {
        $this->pages = array_values(array_filter($pages, function ($page): bool {
            return is_array($page) && empty($page['draft']);
        }));
        $this->siteUrl = $siteUrl;
        $this->publicBasePath = $publicBasePath;
    }

    public function xml(): string
    {
        $pages = $this->pagesWithVirtualFolders();
        usort($pages, function (array $a, array $b): int {
            return strcmp((string) ($a['url'] ?? ''), (string) ($b['url'] ?? ''));
        });

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($pages as $page) {
            $url = $this->absolutePublicUrl((string) ($page['url'] ?? '/'));
            if ($url === '') {
                continue;
            }

            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . $this->escape($url) . '</loc>' . "\n";

            $lastmod = $this->lastmod($page);
            if ($lastmod !== null) {
                $xml .= '    <lastmod>' . $this->escape($lastmod) . '</lastmod>' . "\n";
            }

            $xml .= '  </url>' . "\n";
        }

        $xml .= '</urlset>' . "\n";

        return $xml;
    }

    private function absolutePublicUrl(string $internalUrl): string
    {
        $sitePath = parse_url($this->siteUrl, PHP_URL_PATH);
        if (is_string($sitePath) && trim($sitePath, '/') !== '') {
            return Security::absoluteUrl($this->siteUrl, $internalUrl);
        }

        return Security::absoluteUrl($this->siteUrl, Security::publicUrl($internalUrl, $this->publicBasePath));
    }

    private function pagesWithVirtualFolders(): array
    {
        $byUrl = [];
        foreach ($this->pages as $page) {
            $url = (string) ($page['url'] ?? '');
            if ($url !== '') {
                $byUrl[$url] = $page;
            }
        }

        foreach ($this->pages as $page) {
            $path = trim(str_replace('\\', '/', (string) ($page['path'] ?? '')), '/');
            if ($path === '' || strpos($path, '/') === false) {
                continue;
            }

            $folder = dirname($path);
            if ($folder === '.' || $folder === '') {
                continue;
            }

            $url = '/' . $folder . '/';
            if (!isset($byUrl[$url])) {
                $byUrl[$url] = [
                    'url' => $url,
                    'date' => (string) ($page['date'] ?? ''),
                    'updated' => (string) ($page['updated'] ?? ''),
                    'mtime' => $page['mtime'] ?? null,
                ];
            }
        }

        return array_values($byUrl);
    }

    private function lastmod(array $page): ?string
    {
        foreach (['updated', 'date'] as $key) {
            $value = trim((string) ($page[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }
        }

        $mtime = $page['mtime'] ?? null;
        return is_numeric($mtime) ? date('Y-m-d', (int) $mtime) : null;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
