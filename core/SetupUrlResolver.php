<?php

declare(strict_types=1);

namespace Tomos;

use InvalidArgumentException;

/**
 * Resolves the public Tomos URL from the request that opened setup.
 *
 * Forwarded headers are intentionally not trusted here. Deployments behind a
 * proxy can keep using the explicit public_base_path override in config.php.
 */
final class SetupUrlResolver
{
    /**
     * @param array<string,mixed> $server
     * @return array{site_url:string,base_path:string}
     */
    public static function resolve(array $server): array
    {
        $scheme = self::scheme($server);
        $authority = self::authorityFromServer($server, $scheme);
        $basePath = self::basePathFromServer($server);

        return [
            'site_url' => $scheme . '://' . $authority . $basePath,
            'base_path' => $basePath,
        ];
    }

    /**
     * Normalize a site URL and derive its URL path.
     *
     * @return array{site_url:string,base_path:string}|null
     */
    public static function normalizeSiteUrl(string $siteUrl): ?array
    {
        if ($siteUrl === '' || preg_match('/[\x00-\x1F\x7F\s]/', $siteUrl) === 1) {
            return null;
        }

        $parts = parse_url($siteUrl);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return null;
        }

        $authority = self::buildAuthority($host, isset($parts['port']) ? (int) $parts['port'] : null);
        if ($authority === null) {
            return null;
        }

        $basePath = self::normalizeBasePath((string) ($parts['path'] ?? ''));
        if ($basePath === null) {
            return null;
        }

        return [
            'site_url' => $scheme . '://' . $authority . $basePath,
            'base_path' => $basePath,
        ];
    }

    public static function normalizeBasePath(string $path): ?string
    {
        if ($path === '' || $path === '/') {
            return '';
        }
        if ($path[0] !== '/' || strpos($path, '\\') !== false || strpos($path, ':') !== false) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            return null;
        }

        $path = preg_replace('#/+#', '/', $path) ?? '';
        return $path === '' || $path === '/' ? '' : rtrim($path, '/');
    }

    private static function scheme(array $server): string
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        return in_array($https, ['on', '1', 'true', 'https'], true) ? 'https' : 'http';
    }

    private static function authorityFromServer(array $server, string $scheme): string
    {
        $rawHost = (string) ($server['HTTP_HOST'] ?? '');
        if ($rawHost === '') {
            $rawHost = (string) ($server['SERVER_NAME'] ?? '');
            if ($rawHost === '') {
                throw new InvalidArgumentException('HTTP_HOST or SERVER_NAME is required.');
            }

            $serverPort = (string) ($server['SERVER_PORT'] ?? '');
            if ($serverPort !== '' && self::isValidPort($serverPort)) {
                $defaultPort = ($scheme === 'https') ? '443' : '80';
                if ($serverPort !== $defaultPort && strpos($rawHost, ':') === false) {
                    $rawHost .= ':' . $serverPort;
                }
            }
        }

        $authority = self::normalizeAuthority($rawHost);
        if ($authority === null) {
            throw new InvalidArgumentException('The request host is invalid.');
        }

        return $authority;
    }

    private static function basePathFromServer(array $server): string
    {
        $candidates = [
            (string) ($server['SCRIPT_NAME'] ?? ''),
            (string) ($server['PHP_SELF'] ?? ''),
            (string) ($server['REQUEST_URI'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $path = parse_url($candidate, PHP_URL_PATH);
            if (!is_string($path) || $path === '') {
                continue;
            }
            $basePath = self::basePathFromSetupPath($path);
            if ($basePath !== null) {
                return $basePath;
            }
        }

        throw new InvalidArgumentException('The setup URL path could not be determined.');
    }

    private static function basePathFromSetupPath(string $path): ?string
    {
        if ($path === '' || $path[0] !== '/') {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || strpos($path, '\\') !== false) {
            return null;
        }

        $path = rawurldecode($path);
        $path = preg_replace('#/+#', '/', $path) ?? '/';
        if (preg_match('#/setup(?:/index\.php)?/?$#i', $path) !== 1) {
            return null;
        }

        $basePath = preg_replace('#/setup(?:/index\.php)?/?$#i', '', $path);
        if (!is_string($basePath)) {
            return null;
        }

        return self::normalizeBasePath($basePath);
    }

    private static function normalizeAuthority(string $authority): ?string
    {
        if ($authority === '' || preg_match('/[\x00-\x20\x7F]/', $authority) === 1) {
            return null;
        }
        if ($authority[0] === '[') {
            $close = strpos($authority, ']');
            if ($close === false) {
                return null;
            }
            $host = substr($authority, 1, $close - 1);
            $port = substr($authority, $close + 1);
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
            if ($port !== '' && ($port[0] !== ':' || !self::isValidPort(substr($port, 1)))) {
                return null;
            }
            return '[' . strtolower($host) . ']' . $port;
        }

        if (substr_count($authority, ':') > 1) {
            return null;
        }

        $host = $authority;
        $port = '';
        if (strpos($authority, ':') !== false) {
            [$host, $portNumber] = explode(':', $authority, 2);
            if (!self::isValidPort($portNumber)) {
                return null;
            }
            $port = ':' . $portNumber;
        }

        if (!self::isValidHostName($host)) {
            return null;
        }

        return strtolower($host) . $port;
    }

    private static function buildAuthority(string $host, ?int $port): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $authority = '[' . strtolower($host) . ']';
        } elseif (self::isValidHostName($host)) {
            $authority = strtolower($host);
        } else {
            return null;
        }

        if ($port !== null) {
            if ($port < 1 || $port > 65535) {
                return null;
            }
            $authority .= ':' . $port;
        }

        return $authority;
    }

    private static function isValidHostName(string $host): bool
    {
        if ($host === '' || preg_match('/[\x00-\x20\x7F\/@?#\\:]/', $host) === 1) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false || strtolower($host) === 'localhost') {
            return true;
        }

        return preg_match('/\A[^.\s]+(?:\.[^.\s]+)*\z/u', $host) === 1;
    }

    private static function isValidPort(string $port): bool
    {
        return preg_match('/\A[0-9]{1,5}\z/', $port) === 1 && (int) $port >= 1 && (int) $port <= 65535;
    }
}
