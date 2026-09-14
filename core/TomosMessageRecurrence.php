<?php

declare(strict_types=1);

namespace Tomos;

final class TomosMessageRecurrence
{
    public const COOKIE_NAME = 'tomos_post_daily_message_at';
    public const INTERVAL_SECONDS = 21600;

    public static function isEligible($rawTimestamp, int $now): bool
    {
        if (!is_string($rawTimestamp) || preg_match('/\A[0-9]{1,10}\z/', $rawTimestamp) !== 1) {
            return true;
        }

        $shownAt = (int) $rawTimestamp;
        if ($shownAt <= 0 || $shownAt > $now) {
            return true;
        }

        return ($now - $shownAt) >= self::INTERVAL_SECONDS;
    }

    public static function cookiePath(array $config): string
    {
        $site = isset($config['site']) && is_array($config['site']) ? $config['site'] : [];
        $basePath = (string) (($site['public_base_path'] ?? '') ?: ($site['base_path'] ?? ''));
        $basePath = trim($basePath, '/');

        return $basePath === '' ? '/post/' : '/' . $basePath . '/post/';
    }

    public static function cookieOptions(array $config, array $server, int $shownAt): array
    {
        return [
            'expires' => $shownAt + self::INTERVAL_SECONDS,
            'path' => self::cookiePath($config),
            'secure' => self::isSecure($config, $server),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function isSecure(array $config, array $server): bool
    {
        if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
            return true;
        }

        $site = isset($config['site']) && is_array($config['site']) ? $config['site'] : [];
        $url = (string) ($site['url'] ?? '');

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
