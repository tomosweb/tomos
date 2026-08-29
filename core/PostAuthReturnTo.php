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
    ];

    public static function normalize(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return self::DEFAULT_ROUTE;
        }

        $validated = Security::validateUrlPath($value);
        if (!$validated['is_valid']) {
            return self::DEFAULT_ROUTE;
        }

        return self::ALLOWED_ROUTES[$validated['path']] ?? self::DEFAULT_ROUTE;
    }

    public static function url(string $route, string $publicBasePath): string
    {
        return Security::publicUrl(self::normalize($route), $publicBasePath);
    }

    public static function defaultRoute(): string
    {
        return self::DEFAULT_ROUTE;
    }
}
