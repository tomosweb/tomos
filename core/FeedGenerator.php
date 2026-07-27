<?php

declare(strict_types=1);

namespace Tomos;

final class FeedGenerator
{
    private array $pages;
    private array $site;
    private int $maxItems;
    private string $pathPrefix;
    private string $publicBasePath;

    public function __construct(array $pages, array $site, int $maxItems = 20, string $pathPrefix = '')
    {
        $this->pathPrefix = $this->normalizePathPrefix($pathPrefix);
        $this->pages = array_values(array_filter($pages, function ($page): bool {
            return is_array($page) && empty($page['draft']) && $this->matchesPathPrefix($page);
        }));
        $this->site = $site;
        $this->maxItems = max(1, $maxItems);
        $this->publicBasePath = (string) ($site['public_base_path'] ?? '');
        if ($this->publicBasePath === '') {
            $this->publicBasePath = (string) ($site['base_path'] ?? '');
        }
    }

    private function matchesPathPrefix(array $page): bool
    {
        if ($this->pathPrefix === '') {
            return true;
        }

        $url = '/' . ltrim((string) ($page['url'] ?? ''), '/');

        return $url !== $this->pathPrefix && strpos($url, $this->pathPrefix) === 0;
    }

    private function normalizePathPrefix(string $pathPrefix): string
    {
        $pathPrefix = trim($pathPrefix);
        if ($pathPrefix === '' || $pathPrefix === '/') {
            return '';
        }

        return '/' . trim($pathPrefix, '/') . '/';
    }

    public function xml(): string
    {
        $siteUrl = (string) ($this->site['url'] ?? '');
        $items = $this->pages;
        usort($items, [$this, 'comparePages']);
        $items = array_slice($items, 0, $this->maxItems);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0">' . "\n";
        $xml .= '  <channel>' . "\n";
        $xml .= '    <title>' . $this->escape((string) ($this->site['name'] ?? 'Tomos Site')) . '</title>' . "\n";
        $xml .= '    <link>' . $this->escape($this->absolutePublicUrl('/')) . '</link>' . "\n";
        $xml .= '    <description>' . $this->escape((string) ($this->site['description'] ?? '')) . '</description>' . "\n";
        $xml .= '    <language>' . $this->escape((string) ($this->site['language'] ?? 'ja')) . '</language>' . "\n";

        foreach ($items as $page) {
            $url = $this->absolutePublicUrl((string) ($page['url'] ?? '/'));
            if ($url === '') {
                continue;
            }

            $description = trim((string) ($page['description'] ?? ''));
            if ($description === '') {
                $description = trim((string) ($page['excerpt'] ?? ''));
            }

            $xml .= '    <item>' . "\n";
            $xml .= '      <title>' . $this->escape($this->pageTitle($page)) . '</title>' . "\n";
            $xml .= '      <link>' . $this->escape($url) . '</link>' . "\n";
            $xml .= '      <guid isPermaLink="true">' . $this->escape($url) . '</guid>' . "\n";
            $xml .= '      <description>' . $this->escape($description) . '</description>' . "\n";

            $pubDate = $this->rssDate($page);
            if ($pubDate !== null) {
                $xml .= '      <pubDate>' . $this->escape($pubDate) . '</pubDate>' . "\n";
            }

            $xml .= '    </item>' . "\n";
        }

        $xml .= '  </channel>' . "\n";
        $xml .= '</rss>' . "\n";

        return $xml;
    }

    private function absolutePublicUrl(string $internalUrl): string
    {
        $siteUrl = (string) ($this->site['url'] ?? '');
        $sitePath = parse_url($siteUrl, PHP_URL_PATH);
        if (is_string($sitePath) && trim($sitePath, '/') !== '') {
            return Security::absoluteUrl($siteUrl, $internalUrl);
        }

        return Security::absoluteUrl($siteUrl, Security::publicUrl($internalUrl, $this->publicBasePath));
    }

    private function comparePages(array $a, array $b): int
    {
        return strcmp($this->sortDate($b), $this->sortDate($a));
    }

    private function rssDate(array $page): ?string
    {
        $timestamp = $this->timestamp($page, ['date', 'updated']);
        return $timestamp === null ? null : date('r', $timestamp);
    }

    private function sortDate(array $page): string
    {
        $timestamp = $this->timestamp($page, ['date', 'updated']);
        return str_pad((string) ($timestamp ?? 0), 10, '0', STR_PAD_LEFT);
    }

    private function timestamp(array $page, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = trim((string) ($page[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        $mtime = $page['mtime'] ?? null;
        return is_numeric($mtime) ? (int) $mtime : null;
    }

    private function pageTitle(array $page): string
    {
        $title = trim((string) ($page['title'] ?? ''));
        return $title !== '' ? $title : (string) ($page['path'] ?? 'Untitled');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
