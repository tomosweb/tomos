<?php

declare(strict_types=1);

namespace Tomos;

final class SeoMetadata
{
    public static function build(
        array $page,
        array $site,
        string $publicBasePath,
        ?string $defaultSocialImageUrl,
        string $contentDir
    ): array {
        $siteName = trim((string) ($site['name'] ?? ''));
        if ($siteName === '') {
            $siteName = 'Tomos Site';
        }

        $rawPageType = (string) ($page['page_type'] ?? '');
        $pageType = self::pageType($page);
        $pageTitle = trim((string) ($page['title'] ?? ''));
        $homeFallback = $rawPageType === 'home'
            && empty($page['title_explicit'])
            && ($pageTitle === '' || strtolower($pageTitle) === 'index');
        if ($pageTitle === '' || $homeFallback) {
            $pageTitle = $siteName;
        }

        $documentTitle = $pageTitle;
        if ($rawPageType !== 'home' || !$homeFallback) {
            $documentTitle .= ' - ' . $siteName;
        }

        $internalUrl = self::pathOnly((string) ($page['internal_url'] ?? $page['url'] ?? '/'));
        $canonicalUrl = Security::absolutePublicUrl(
            (string) ($site['url'] ?? ''),
            $internalUrl,
            $publicBasePath
        );

        $description = self::description($page, $site);
        $socialImageUrl = self::socialImageUrl(
            (string) ($page['image'] ?? ''),
            (string) ($page['path'] ?? ''),
            $contentDir,
            (string) ($site['url'] ?? ''),
            $publicBasePath,
            $defaultSocialImageUrl
        );

        return [
            'title' => $pageTitle,
            'document_title' => $documentTitle,
            'description' => $description,
            'canonical_url' => $canonicalUrl,
            'public_url' => Security::publicUrl($internalUrl, $publicBasePath),
            'page_type' => $pageType === 'article' ? 'article' : 'website',
            'site_name' => $siteName,
            'social_image_url' => $socialImageUrl,
            'language' => LanguageTag::fallback($page['language'] ?? null, $site['language'] ?? 'ja'),
            'publication_date' => self::firstNonEmpty($page, ['date', 'published']),
            'modified_date' => trim((string) ($page['updated'] ?? '')),
        ];
    }

    public static function description(array $page, array $site): string
    {
        foreach ([
            (string) ($page['description'] ?? ''),
            (string) ($page['excerpt'] ?? ''),
            (string) ($site['description'] ?? ''),
        ] as $value) {
            $value = self::plainText($value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    public static function headHtml(array $metadata, string $feedUrl = ''): string
    {
        $html = [];
        $html[] = '<title>' . self::escape((string) ($metadata['document_title'] ?? 'Tomos Site')) . '</title>';

        $description = trim((string) ($metadata['description'] ?? ''));
        if ($description !== '') {
            $html[] = '<meta name="description" content="' . self::escape($description) . '">';
        }

        $canonical = trim((string) ($metadata['canonical_url'] ?? ''));
        if ($canonical !== '') {
            $html[] = '<link rel="canonical" href="' . self::escape($canonical) . '">';
        }

        $html[] = '<meta property="og:type" content="' . self::escape((string) ($metadata['page_type'] ?? 'website')) . '">';
        $html[] = '<meta property="og:title" content="' . self::escape((string) ($metadata['title'] ?? 'Tomos Site')) . '">';
        if ($description !== '') {
            $html[] = '<meta property="og:description" content="' . self::escape($description) . '">';
        }
        if ($canonical !== '') {
            $html[] = '<meta property="og:url" content="' . self::escape($canonical) . '">';
        }
        $siteName = trim((string) ($metadata['site_name'] ?? ''));
        if ($siteName !== '') {
            $html[] = '<meta property="og:site_name" content="' . self::escape($siteName) . '">';
        }
        $image = trim((string) ($metadata['social_image_url'] ?? ''));
        if ($image !== '') {
            $html[] = '<meta property="og:image" content="' . self::escape($image) . '">';
        }

        $html[] = '<meta name="twitter:card" content="summary_large_image">';
        $html[] = '<meta name="twitter:title" content="' . self::escape((string) ($metadata['title'] ?? 'Tomos Site')) . '">';
        if ($description !== '') {
            $html[] = '<meta name="twitter:description" content="' . self::escape($description) . '">';
        }
        if ($image !== '') {
            $html[] = '<meta name="twitter:image" content="' . self::escape($image) . '">';
        }

        if (trim($feedUrl) !== '') {
            $html[] = '<link rel="alternate" type="application/rss+xml" title="' . self::escape($siteName) . '" href="' . self::escape($feedUrl) . '">';
        }

        return implode("\n  ", $html);
    }

    private static function pageType(array $page): string
    {
        $type = (string) ($page['page_type'] ?? '');
        $internalUrl = self::pathOnly((string) ($page['internal_url'] ?? $page['url'] ?? '/'));
        if ($type === 'virtual_folder_index' || $type === 'home' || $type === 'fixed_page' || $type === 'website'
            || ($internalUrl !== '/' && substr($internalUrl, -1) === '/')) {
            return 'website';
        }

        if ($type === 'markdown_page' || $type === 'article') {
            return 'article';
        }

        return ($page['internal_url'] ?? $page['url'] ?? '/') === '/' ? 'website' : 'article';
    }

    private static function socialImageUrl(
        string $target,
        string $pagePath,
        string $contentDir,
        string $siteUrl,
        string $publicBasePath,
        ?string $defaultSocialImageUrl
    ): ?string {
        $target = trim($target);
        if ($target === '') {
            return $defaultSocialImageUrl;
        }

        $scheme = parse_url($target, PHP_URL_SCHEME);
        if (is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true)) {
            return self::validExternalImageUrl($target) ? $target : $defaultSocialImageUrl;
        }

        if ($scheme !== null || strpos($target, '//') === 0 || strpos($target, '\\') !== false) {
            return $defaultSocialImageUrl;
        }

        $projectRoot = dirname(rtrim($contentDir, DIRECTORY_SEPARATOR));
        if (strpos($target, '/') === 0) {
            $relative = ltrim($target, '/');
            $candidate = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!self::isLocalImage($candidate, $projectRoot)) {
                return $defaultSocialImageUrl;
            }

            return Security::absolutePublicUrl($siteUrl, '/' . $relative, $publicBasePath);
        }

        if (!Security::isSafeRelativePath($target)) {
            return $defaultSocialImageUrl;
        }

        $pageDirectory = dirname(str_replace('\\', '/', $pagePath));
        $base = rtrim($contentDir, DIRECTORY_SEPARATOR);
        if ($pageDirectory !== '' && $pageDirectory !== '.') {
            $base .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim($pageDirectory, '/'));
        }
        $candidate = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $target);
        if (!self::isLocalImage($candidate, $contentDir)) {
            return $defaultSocialImageUrl;
        }

        $realContentDir = realpath($contentDir);
        $realCandidate = realpath($candidate);
        if ($realContentDir === false || $realCandidate === false) {
            return $defaultSocialImageUrl;
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($realCandidate, strlen(rtrim($realContentDir, DIRECTORY_SEPARATOR)) + 1));

        return Security::absolutePublicUrl($siteUrl, '/content/' . $relative, $publicBasePath);
    }

    private static function isLocalImage(string $candidate, string $baseDir): bool
    {
        $realBase = realpath($baseDir);
        $realCandidate = realpath($candidate);
        if ($realBase === false || $realCandidate === false || !is_file($realCandidate)) {
            return false;
        }

        if (!Security::isPathInside($realCandidate, $realBase)) {
            return false;
        }

        return in_array(strtolower(pathinfo($realCandidate, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    private static function validExternalImageUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && trim((string) ($parts['host'] ?? '')) !== '';
    }

    private static function firstNonEmpty(array $page, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($page[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function pathOnly(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        if ($path === '/index.php' || $path === '/index.php/') {
            return '/';
        }
        if (strpos($path, '/index.php/') === 0) {
            $path = substr($path, strlen('/index.php'));
        }

        return $path === '' ? '/' : $path;
    }

    private static function plainText(string $value): string
    {
        $value = strip_tags($value);
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
