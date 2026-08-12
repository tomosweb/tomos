<?php

declare(strict_types=1);

namespace Tomos;

final class WikiLinkParser
{
    private array $pagesByUrl = [];
    private array $aliases = [];
    private array $conflicts = [];
    private string $publicBasePath;
    private array $placeholders = [];

    public function __construct(array $pages, string $publicBasePath = '', array $linkAliases = [])
    {
        $this->publicBasePath = $publicBasePath;
        $this->aliases = is_array($linkAliases['aliases'] ?? null) ? $linkAliases['aliases'] : [];
        $this->conflicts = is_array($linkAliases['conflicts'] ?? null) ? $linkAliases['conflicts'] : [];

        foreach ($pages as $page) {
            if (!is_array($page) || !empty($page['draft']) || empty($page['url'])) {
                continue;
            }

            $url = $this->normalizeExistingUrl((string) $page['url']);
            $this->pagesByUrl[$url] = $page;
        }
    }

    public function replace(string $markdown): string
    {
        $this->placeholders = [];

        return preg_replace_callback('/(?<!!)\[\[([^\]\n]+)\]\]/u', function (array $matches): string {
            $html = $this->linkHtml($matches[1]);
            $placeholder = 'TOMOSWIKILINK' . count($this->placeholders) . 'TOKEN';
            $this->placeholders[$placeholder] = $html;

            return $placeholder;
        }, $markdown) ?? $markdown;
    }

    public function restore(string $html): string
    {
        if ($this->placeholders === []) {
            return $html;
        }

        return strtr($html, $this->placeholders);
    }

    private function linkHtml(string $source): string
    {
        [$target, $alias] = $this->splitTargetAndAlias($source);
        [$pageTarget, $headingTarget] = $this->splitPageAndHeading($target);
        $normalized = $this->resolveTarget($pageTarget);
        $label = $alias !== '' ? $alias : $this->labelForTarget($target, $normalized);

        if ($normalized === null || !isset($this->pagesByUrl[$normalized])) {
            return $this->missingLinkHtml($target, $label);
        }

        $page = $this->pagesByUrl[$normalized];
        if ($alias === '') {
            $label = $this->pageTitle($page, $target);
        }

        $href = Security::publicUrl($normalized, $this->publicBasePath);
        if ($headingTarget !== '') {
            $href .= '#' . rawurlencode($headingTarget);
        }

        return '<a href="' . $this->escape($href) . '" class="wiki-link">' . $this->escape($label) . '</a>';
    }

    private function splitTargetAndAlias(string $source): array
    {
        $parts = explode('|', $source, 2);
        $target = trim($parts[0]);
        $alias = isset($parts[1]) ? trim($parts[1]) : '';

        return [$target, $alias];
    }

    private function normalizeTarget(string $target): ?string
    {
        $target = trim($target);
        if ($target === '' || strpos($target, "\0") !== false || preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            return null;
        }

        if (strpos($target, '\\') !== false || strpos($target, '//') === 0 || strpos($target, ':') !== false) {
            return null;
        }

        $target = preg_replace('#/+#', '/', $target) ?? $target;
        $target = '/' . ltrim($target, '/');

        if (substr($target, -3) === '.md') {
            $target = substr($target, 0, -3);
        }

        if ($target === '/index') {
            $target = '/';
        } elseif (substr($target, -6) === '/index') {
            $target = substr($target, 0, -5);
        }

        $validation = Security::validateUrlPath($target);
        if (empty($validation['is_valid'])) {
            return null;
        }

        $path = (string) $validation['path'];
        if ($path !== '/' && substr($path, -1) === '/') {
            return $path;
        }

        return $path;
    }

    private function resolveTarget(string $target): ?string
    {
        foreach ($this->aliasKeysForTarget($target) as $aliasKey) {
            if ($aliasKey === '') {
                continue;
            }

            if (isset($this->conflicts[$aliasKey])) {
                return null;
            }

            if (isset($this->aliases[$aliasKey])) {
                return $this->normalizeExistingUrl((string) $this->aliases[$aliasKey]);
            }
        }

        return $this->normalizeTarget($target);
    }

    private function aliasKeysForTarget(string $target): array
    {
        $key = $this->normalizeAlias($target);
        $keys = [$key];

        if (substr($key, -3) === '.md') {
            $keys[] = substr($key, 0, -3);
        }

        return array_values(array_unique($keys));
    }

    private function splitPageAndHeading(string $target): array
    {
        $parts = explode('#', $target, 2);
        $page = trim($parts[0]);
        $heading = isset($parts[1]) ? trim($parts[1]) : '';

        return [$page, $heading];
    }

    private function normalizeAlias(string $alias): string
    {
        $alias = rawurldecode(trim($alias));
        $alias = str_replace('\\', '/', $alias);
        $alias = preg_replace('#/+#', '/', $alias) ?? $alias;
        $alias = trim($alias, " /\t\n\r\0\x0B");
        $alias = preg_replace('/[\x00-\x1F\x7F]/u', '', $alias) ?? '';
        $alias = preg_replace('/[\x{3000}\s]+/u', ' ', $alias) ?? $alias;
        $alias = trim($alias);

        return $alias;
    }

    private function normalizeExistingUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '/';
        }

        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        return preg_replace('#/+#', '/', $path) ?? '/';
    }

    private function labelForTarget(string $target, ?string $normalized): string
    {
        if ($normalized !== null && isset($this->pagesByUrl[$normalized])) {
            return $this->pageTitle($this->pagesByUrl[$normalized], $target);
        }

        return trim($target) !== '' ? trim($target) : 'Untitled';
    }

    private function pageTitle(array $page, string $fallback): string
    {
        $title = trim((string) ($page['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        return trim($fallback) !== '' ? trim($fallback) : 'Untitled';
    }

    private function missingLinkHtml(string $target, string $label): string
    {
        return '<span class="tomos-missing-link" data-link-target="' . $this->escape($target) . '">' . $this->escape($label) . '</span>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
