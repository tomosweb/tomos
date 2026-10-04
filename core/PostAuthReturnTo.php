<?php

declare(strict_types=1);

namespace Tomos;

final class PostAuthReturnTo
{
    private const DEFAULT_ROUTE = '/post/?section=settings';

    /** @var array<string, string> */
    private const ALLOWED_ROUTES = [
        '/post/theme/' => '/post/theme/',
        '/post/site-settings.php' => '/post/site-settings.php',
        '/post/social/bluesky/' => '/post/social/bluesky/',
    ];

    /** @param mixed $value */
    public static function normalize($value): string
    {
        if (!is_string($value) || $value === '') {
            return self::DEFAULT_ROUTE;
        }

        $parts = parse_url($value);
        if (!is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return self::DEFAULT_ROUTE;
        }

        $rawPath = is_string($parts['path'] ?? null) ? $parts['path'] : '';
        $validated = Security::validateUrlPath($rawPath);
        if (!$validated['is_valid']) {
            return self::DEFAULT_ROUTE;
        }

        if (array_key_exists('query', $parts)) {
            $query = (string) $parts['query'];
            if ($validated['path'] !== '/post/'
                || !preg_match('/^write_import=1&session=([a-f0-9-]{32,36})$/i', $query, $matches)
            ) {
                return self::DEFAULT_ROUTE;
            }
            return '/post/?write_import=1&session=' . strtolower($matches[1]);
        }

        return self::ALLOWED_ROUTES[$validated['path']] ?? self::DEFAULT_ROUTE;
    }

    public static function url(string $route, string $publicBasePath): string
    {
        $normalized = self::normalize($route);
        $parts = parse_url($normalized);
        $path = is_array($parts) && is_string($parts['path'] ?? null) ? $parts['path'] : '/post/';
        $url = Security::publicUrl($path, $publicBasePath);
        $query = is_array($parts) && is_string($parts['query'] ?? null) ? $parts['query'] : '';
        return $query === '' ? $url : $url . '?' . $query;
    }

    public static function defaultRoute(): string
    {
        return self::DEFAULT_ROUTE;
    }
}
