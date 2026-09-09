<?php

declare(strict_types=1);

namespace Tomos;

use Throwable;

/** Recognizes Amazon product URLs without changing the user's source URL. */
final class AmazonUrlResolver
{
    public const MAX_REDIRECTS = 3;

    private const INITIAL_HOSTS = [
        'amazon.co.jp',
        'www.amazon.co.jp',
        'amzn.asia',
        'link.amazon',
    ];
    private const FINAL_HOSTS = ['amazon.co.jp', 'www.amazon.co.jp'];

    private ExternalUrlHttpClient $http;
    private $dnsResolver;

    public function __construct(?callable $fixtureTransport = null, ?callable $dnsResolver = null)
    {
        $this->http = new ExternalUrlHttpClient($fixtureTransport);
        $this->dnsResolver = $dnsResolver;
    }

    public function resolve(string $sourceUrl): ?array
    {
        try {
            $parts = $this->parseUrl($sourceUrl);
            $host = strtolower((string) $parts['host']);
            if (!in_array($host, self::INITIAL_HOSTS, true)) {
                return null;
            }
            $asin = $this->asinFromPath($host, (string) ($parts['path'] ?? ''));
            if ($asin !== null) {
                return $this->result($sourceUrl, $sourceUrl, $asin, false);
            }
            if (!in_array($host, ['amzn.asia', 'link.amazon'], true)) {
                return null;
            }
            return $this->resolveShortUrl($sourceUrl);
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function resolveShortUrl(string $sourceUrl): ?array
    {
        $current = $sourceUrl;
        $seen = [];
        for ($redirect = 0; $redirect < self::MAX_REDIRECTS; $redirect++) {
            if (isset($seen[$current])) {
                return null;
            }
            $seen[$current] = true;
            $parts = $this->parseUrl($current);
            $host = strtolower((string) $parts['host']);
            if (!in_array($host, self::INITIAL_HOSTS, true)) {
                return null;
            }
            $this->assertPublicHost($host);
            $response = $this->http->get($current, self::INITIAL_HOSTS);
            $status = (int) ($response['status'] ?? 0);
            $location = $response['location'] ?? ($response['headers']['location'] ?? null);
            if ($status < 300 || $status >= 400 || !is_string($location) || trim($location) === '') {
                return null;
            }
            $current = $this->resolveLocation($current, $location);
            $target = $this->parseUrl($current);
            $targetHost = strtolower((string) $target['host']);
            if (!in_array($targetHost, self::INITIAL_HOSTS, true)) {
                return null;
            }
            $this->assertPublicHost($targetHost);
            if (in_array($targetHost, self::FINAL_HOSTS, true)) {
                $asin = $this->asinFromPath($targetHost, (string) ($target['path'] ?? ''));
                if ($asin !== null) {
                    return $this->result($sourceUrl, $current, $asin, true);
                }
                return null;
            }
        }
        return null;
    }

    private function result(string $sourceUrl, string $resolvedUrl, string $asin, bool $resolutionRequired): array
    {
        return [
            'sourceUrl' => $sourceUrl,
            'resolvedUrl' => $resolvedUrl,
            'asin' => $asin,
            'marketplace' => 'amazon.co.jp',
            'resolutionRequired' => $resolutionRequired,
        ];
    }

    private function asinFromPath(string $host, string $path): ?string
    {
        if (!in_array($host, self::FINAL_HOSTS, true)) {
            return null;
        }
        $patterns = [
            '~\A/dp/([A-Za-z0-9]{10})(?:/[^/]*)*/?\z~',
            '~\A/.+/dp/([A-Za-z0-9]{10})(?:/[^/]*)*/?\z~',
            '~\A/gp/product/([A-Za-z0-9]{10})(?:/[^/]*)*/?\z~',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path, $matches) === 1) {
                return strtoupper($matches[1]);
            }
        }
        return null;
    }

    private function parseUrl(string $url): array
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new \InvalidArgumentException('Amazon URL contains control characters.');
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || filter_var((string) $parts['host'], FILTER_VALIDATE_IP) !== false
        ) {
            throw new \InvalidArgumentException('Amazon URL is not an allowed HTTPS URL.');
        }
        return $parts;
    }

    private function assertPublicHost(string $host): void
    {
        $addresses = $this->dnsResolver !== null
            ? call_user_func($this->dnsResolver, $host)
            : $this->resolveDns($host);
        if (!is_array($addresses) || $addresses === []) {
            throw new \RuntimeException('Amazon redirect host did not resolve.');
        }
        foreach ($addresses as $address) {
            if (!is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \RuntimeException('Amazon redirect host resolves to an internal address.');
            }
        }
    }

    private function resolveDns(string $host): array
    {
        $addresses = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip'])) {
                        $addresses[] = (string) $record['ip'];
                    }
                    if (isset($record['ipv6'])) {
                        $addresses[] = (string) $record['ipv6'];
                    }
                }
            }
        }
        if ($addresses === []) {
            $addresses = @gethostbynamel($host) ?: [];
        }
        return array_values(array_unique($addresses));
    }

    private function resolveLocation(string $base, string $location): string
    {
        $location = trim($location);
        if ($location === '' || preg_match('/[\x00-\x20\x7f]/', $location) === 1) {
            throw new \InvalidArgumentException('Amazon redirect location is invalid.');
        }
        $locationParts = parse_url($location);
        if (is_array($locationParts) && isset($locationParts['scheme'])) {
            return $location;
        }
        $baseParts = $this->parseUrl($base);
        $origin = 'https://' . $baseParts['host'];
        if (isset($baseParts['port'])) {
            $origin .= ':' . $baseParts['port'];
        }
        if (strpos($location, '//') === 0) {
            return 'https:' . $location;
        }
        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }
        $basePath = (string) ($baseParts['path'] ?? '/');
        $directory = substr($basePath, 0, strrpos($basePath, '/') + 1);
        return $origin . $directory . $location;
    }
}
