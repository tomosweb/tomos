<?php

declare(strict_types=1);

namespace Tomos;

use Throwable;

final class AmazonUrlResolver
{
    public const MAX_REDIRECTS = 3;
    public const CONNECT_TIMEOUT = 10;
    public const TOTAL_TIMEOUT = 20;
    private const MAX_RESPONSE_BYTES = 65536;

    private const INITIAL_HOSTS = [
        'amazon.co.jp',
        'www.amazon.co.jp',
        'link.amazon',
        'amzn.asia',
    ];

    private const REDIRECT_HOSTS = [
        'amazon.co.jp',
        'www.amazon.co.jp',
        'link.amazon',
        'amzn.asia',
        'amzlinks.in',
    ];

    private const FINAL_HOSTS = [
        'amazon.co.jp',
        'www.amazon.co.jp',
    ];

    private $fixtureTransport;
    private $dnsResolver;

    public function __construct(?callable $fixtureTransport = null, ?callable $dnsResolver = null)
    {
        $this->fixtureTransport = $fixtureTransport;
        $this->dnsResolver = $dnsResolver;
    }

    /**
     * Return normalized Amazon identity data without changing the source URL.
     */
    public function resolve(string $sourceUrl): ?array
    {
        try {
            $parts = $this->parseHttpsUrl($sourceUrl);
            $host = strtolower((string) $parts['host']);
            if (!in_array($host, self::INITIAL_HOSTS, true)) {
                return null;
            }

            $asin = $this->asinFromAmazonPath($host, (string) ($parts['path'] ?? ''));
            if ($asin !== null) {
                return $this->result($sourceUrl, $sourceUrl, $asin, false);
            }

            if (!in_array($host, ['link.amazon', 'amzn.asia'], true)) {
                return null;
            }

            return $this->resolveRedirects($sourceUrl);
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function resolveRedirects(string $sourceUrl): ?array
    {
        $url = $sourceUrl;
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $parts = $this->parseHttpsUrl($url);
            $host = strtolower((string) $parts['host']);
            if (!in_array($host, self::REDIRECT_HOSTS, true)) {
                return null;
            }

            $this->assertPublicHost($host);
            $response = $this->request($url);
            $status = (int) ($response['status'] ?? 0);
            $location = $response['location'] ?? null;
            if ($status < 300 || $status >= 400 || !is_string($location) || $location === '') {
                return null;
            }
            if ($redirect >= self::MAX_REDIRECTS) {
                return null;
            }

            $url = $this->resolveUrl($url, $location);
            $targetParts = $this->parseHttpsUrl($url);
            $targetHost = strtolower((string) $targetParts['host']);
            if (!in_array($targetHost, self::REDIRECT_HOSTS, true)) {
                return null;
            }
            $this->assertPublicHost($targetHost);

            if (in_array($targetHost, self::FINAL_HOSTS, true)) {
                $asin = $this->asinFromAmazonPath($targetHost, (string) ($targetParts['path'] ?? ''));
                if ($asin !== null) {
                    return $this->result($sourceUrl, $url, $asin, true);
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

    private function asinFromAmazonPath(string $host, string $path): ?string
    {
        if (!in_array($host, self::FINAL_HOSTS, true)) {
            return null;
        }

        if (preg_match('~\A/dp/([A-Za-z0-9]{10})(?:/[^/]*)*/?\z~', $path, $matches) === 1) {
            return strtoupper($matches[1]);
        }
        if (preg_match('~\A/.+/dp/([A-Za-z0-9]{10})(?:/[^/]*)*/?\z~u', $path, $matches) === 1) {
            return strtoupper($matches[1]);
        }
        if (preg_match('~\A/gp/product/([A-Za-z0-9]{10})(?:/[^/]*)*/?\z~', $path, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    private function parseHttpsUrl(string $url): array
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new \InvalidArgumentException('URL contains control characters.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new \InvalidArgumentException('URL is not an allowed HTTPS URL.');
        }

        $host = strtolower((string) $parts['host']);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new \InvalidArgumentException('IP literal is not an allowed Amazon host.');
        }

        return $parts;
    }

    private function assertPublicHost(string $host): void
    {
        $addresses = $this->fixtureDns($host);
        if ($addresses === []) {
            throw new \RuntimeException('Could not resolve redirect host.');
        }
        foreach ($addresses as $address) {
            if (!is_string($address) || $this->isPrivateOrReservedIp($address)) {
                throw new \RuntimeException('Redirect host resolves to a private or reserved IP.');
            }
        }
    }

    private function fixtureDns(string $host): array
    {
        if ($this->dnsResolver !== null) {
            $addresses = call_user_func($this->dnsResolver, $host);
            return is_array($addresses) ? array_values($addresses) : [];
        }

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
        if ($addresses === false || $addresses === []) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                $addresses = $ipv4;
            }
        }

        return array_values(array_unique(array_filter($addresses, 'is_string')));
    }

    private function isPrivateOrReservedIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private function request(string $url): array
    {
        if ($this->fixtureTransport !== null) {
            $response = call_user_func($this->fixtureTransport, $url);
            if (!is_array($response)) {
                throw new \RuntimeException('Amazon URL fixture response is invalid.');
            }
            return $response;
        }
        if (function_exists('curl_init')) {
            return $this->curl($url);
        }
        if ((bool) ini_get('allow_url_fopen')) {
            return $this->stream($url);
        }

        throw new \RuntimeException('cURL or HTTPS stream is unavailable.');
    }

    private function curl(string $url): array
    {
        $location = null;
        $bytes = 0;
        $tooLarge = false;
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Could not initialize cURL.');
        }
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HTTPGET => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location): int {
                if (stripos(trim($line), 'Location:') === 0) {
                    $location = trim(substr(trim($line), 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (&$bytes, &$tooLarge): int {
                $bytes += strlen($data);
                if ($bytes > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                return strlen($data);
            },
        ]);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($curl);
        }
        if ($tooLarge) {
            throw new \RuntimeException('Amazon URL response is too large.');
        }
        if ($ok === false) {
            throw new \RuntimeException($error !== '' ? $error : 'Amazon URL request failed.');
        }

        return ['status' => $status, 'location' => $location];
    }

    private function stream(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TOTAL_TIMEOUT,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $input = @fopen($url, 'rb', false, $context);
        if (!is_resource($input)) {
            throw new \RuntimeException('HTTPS stream could not be opened.');
        }

        $bytes = 0;
        while (!feof($input)) {
            $chunk = fread($input, 8192);
            if (!is_string($chunk)) {
                fclose($input);
                throw new \RuntimeException('HTTPS stream could not be read.');
            }
            $bytes += strlen($chunk);
            if ($bytes > self::MAX_RESPONSE_BYTES) {
                fclose($input);
                throw new \RuntimeException('Amazon URL response is too large.');
            }
        }
        fclose($input);

        $status = 0;
        $location = null;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/\AHTTP\/\S+\s+(\d+)/', $line, $match) === 1) {
                $status = (int) $match[1];
            } elseif (stripos($line, 'Location:') === 0) {
                $location = trim(substr(trim($line), 9));
            }
        }

        return ['status' => $status, 'location' => $location];
    }

    private function resolveUrl(string $base, string $location): string
    {
        $location = trim($location);
        if ($location === '' || preg_match('/[\x00-\x20\x7f]/', $location) === 1) {
            throw new \InvalidArgumentException('Redirect location is invalid.');
        }

        $locationParts = parse_url($location);
        if (is_array($locationParts) && isset($locationParts['scheme'])) {
            return $location;
        }

        $baseParts = $this->parseHttpsUrl($base);
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
