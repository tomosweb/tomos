<?php

declare(strict_types=1);

namespace Tomos;

final class Security
{
    public static function validateUrlPath(string $rawPath): array
    {
        if ($rawPath === '') {
            $rawPath = '/';
        }

        if (strpos($rawPath, "\0") !== false || self::hasControlCharacters($rawPath)) {
            return self::invalidPath('invalid_path');
        }

        $path = $rawPath;
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($path);
            if ($decoded === $path) {
                break;
            }

            $path = $decoded;
            if (strpos($path, "\0") !== false || self::hasControlCharacters($path)) {
                return self::invalidPath('invalid_path');
            }
        }

        if (strpos($path, '\\') !== false) {
            return self::invalidPath('invalid_path');
        }

        if (strpos($path, ':') !== false) {
            return self::invalidPath('invalid_path');
        }

        if (!self::isUtf8($path)) {
            return self::invalidPath('invalid_path');
        }

        $path = preg_replace('#/+#', '/', $path) ?? '/';

        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        if (self::hasDotDotSegment($path)) {
            return self::invalidPath('forbidden_path');
        }

        if (!self::hasAllowedUrlPathCharacters($path)) {
            return self::invalidPath('invalid_path');
        }

        return [
            'is_valid' => true,
            'path' => $path,
            'reason' => 'ok',
        ];
    }

    public static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || strpos($path, "\0") !== false || self::hasControlCharacters($path)) {
            return false;
        }

        if (strpos($path, '\\') !== false || strpos($path, ':') !== false) {
            return false;
        }

        if (strpos($path, '/') === 0 || self::hasDotDotSegment($path)) {
            return false;
        }

        return true;
    }

    public static function isPathInside(string $path, string $baseDir): bool
    {
        $realPath = realpath($path);
        $realBase = realpath($baseDir);

        if ($realPath === false || $realBase === false) {
            return false;
        }

        $realBase = rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strpos($realPath, $realBase) === 0;
    }

    public static function hasAllowedExtension(string $path, array $allowedExtensions): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', $allowedExtensions);

        return in_array($extension, $allowed, true);
    }

    public static function safeHref(string $href): string
    {
        $href = trim($href);
        $scheme = parse_url($href, PHP_URL_SCHEME);

        if ($scheme !== null && !in_array(strtolower($scheme), ['http', 'https', 'mailto'], true)) {
            return '#';
        }

        if (strpos($href, '//') === 0) {
            return '#';
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            return '#';
        }

        return $href;
    }

    public static function sanitizeAttributeUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return '#';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== null || strpos($url, '//') === 0) {
            return '#';
        }

        return $url;
    }

    public static function normalizeBasePath(string $basePath): string
    {
        $basePath = trim($basePath);
        if ($basePath === '' || $basePath === '/') {
            return '';
        }

        if (strpos($basePath, "\0") !== false || self::hasControlCharacters($basePath) || strpos($basePath, '\\') !== false) {
            return '';
        }

        $basePath = '/' . trim($basePath, '/');
        $basePath = preg_replace('#/+#', '/', $basePath) ?? '';

        if ($basePath === '' || self::hasDotDotSegment($basePath) || strpos($basePath, ':') !== false) {
            return '';
        }

        return rtrim($basePath, '/');
    }

    public static function publicUrl(string $internalUrl, string $publicBasePath = ''): string
    {
        $publicBasePath = self::normalizeBasePath($publicBasePath);
        if ($internalUrl === '') {
            $internalUrl = '/';
        }

        if (strpos($internalUrl, '/') !== 0) {
            $internalUrl = '/' . $internalUrl;
        }

        $internalUrl = self::encodeUrlPath($internalUrl);

        if ($internalUrl === '/') {
            return $publicBasePath === '' ? '/' : $publicBasePath . '/';
        }

        return $publicBasePath . $internalUrl;
    }

    public static function absoluteUrl(string $siteUrl, string $internalUrl): string
    {
        $siteUrl = trim($siteUrl);
        if ($siteUrl === '' || self::hasControlCharacters($siteUrl)) {
            return '';
        }

        $scheme = parse_url($siteUrl, PHP_URL_SCHEME);
        if ($scheme === null || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return '';
        }

        if ($internalUrl === '') {
            $internalUrl = '/';
        }

        if (strpos($internalUrl, '/') !== 0) {
            $internalUrl = '/' . $internalUrl;
        }

        $base = rtrim($siteUrl, '/');
        $internalUrl = self::encodeUrlPath($internalUrl);

        if ($internalUrl === '/') {
            return $base . '/';
        }

        return $base . $internalUrl;
    }

    private static function hasDotDotSegment(string $path): bool
    {
        return preg_match('#(^|/)\.\.?(/|$)#', $path) === 1;
    }

    private static function hasControlCharacters(string $path): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $path) === 1;
    }

    private static function isUtf8(string $path): bool
    {
        return preg_match('//u', $path) === 1;
    }

    private static function hasAllowedUrlPathCharacters(string $path): bool
    {
        return preg_match('/[<>"|*?#]/u', $path) !== 1;
    }

    private static function encodeUrlPath(string $url): string
    {
        $suffix = '';
        $queryPosition = strpos($url, '?');
        $fragmentPosition = strpos($url, '#');
        $positions = array_filter([$queryPosition, $fragmentPosition], static function ($position): bool {
            return $position !== false;
        });

        if ($positions !== []) {
            $splitAt = min($positions);
            $suffix = substr($url, $splitAt);
            $url = substr($url, 0, $splitAt);
        }

        $url = preg_replace('#/+#', '/', $url) ?? '/';
        if ($url === '' || $url[0] !== '/') {
            $url = '/' . $url;
        }

        if ($url === '/') {
            return '/' . $suffix;
        }

        $hasTrailingSlash = substr($url, -1) === '/';
        $segments = explode('/', trim($url, '/'));
        $encodedSegments = array_map(static function (string $segment): string {
            return rawurlencode($segment);
        }, $segments);

        return '/' . implode('/', $encodedSegments) . ($hasTrailingSlash ? '/' : '') . $suffix;
    }

    private static function invalidPath(string $reason): array
    {
        return [
            'is_valid' => false,
            'path' => '/',
            'reason' => $reason,
        ];
    }
}
