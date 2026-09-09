<?php

declare(strict_types=1);

namespace Tomos;

/** Fixed-host HTTPS client used by the service-specific resolvers. */
final class ExternalUrlHttpClient
{
    public const CONNECT_TIMEOUT = 5;
    public const TOTAL_TIMEOUT = 10;
    private const MAX_RESPONSE_BYTES = 131072;

    private $fixtureTransport;

    public function __construct(?callable $fixtureTransport = null)
    {
        $this->fixtureTransport = $fixtureTransport;
    }

    public function get(string $url, array $allowedHosts): array
    {
        $parts = $this->parseHttpsUrl($url);
        $host = strtolower((string) $parts['host']);
        if (!in_array($host, $allowedHosts, true)) {
            throw new \RuntimeException('External request host is not allowed.');
        }

        if ($this->fixtureTransport !== null) {
            $response = call_user_func($this->fixtureTransport, $url);
            if (!is_array($response)) {
                throw new \RuntimeException('External fixture response is invalid.');
            }
            return $this->normalizeResponse($response);
        }

        if (function_exists('curl_init')) {
            return $this->curl($url);
        }
        if ((bool) ini_get('allow_url_fopen')) {
            return $this->stream($url);
        }

        throw new \RuntimeException('HTTPS transport is unavailable.');
    }

    private function parseHttpsUrl(string $url): array
    {
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new \InvalidArgumentException('External URL contains control characters.');
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
            throw new \InvalidArgumentException('External URL is not an allowed HTTPS URL.');
        }
        if (filter_var((string) $parts['host'], FILTER_VALIDATE_IP) !== false) {
            throw new \InvalidArgumentException('IP literal is not allowed.');
        }
        return $parts;
    }

    private function curl(string $url): array
    {
        $body = '';
        $headers = [];
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Could not initialize HTTPS transport.');
        }
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HTTPGET => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/html;q=0.9, */*;q=0.1'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $trimmed = trim($line);
                if (strpos($trimmed, ':') !== false) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (&$body): int {
                if (strlen($body) + strlen($data) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $body .= $data;
                return strlen($data);
            },
        ]);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($curl);
        }
        if ($ok === false) {
            throw new \RuntimeException($error !== '' ? $error : 'HTTPS request failed.');
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
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
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $input = @fopen($url, 'rb', false, $context);
        if (!is_resource($input)) {
            throw new \RuntimeException('HTTPS stream could not be opened.');
        }
        $body = '';
        while (!feof($input)) {
            $chunk = fread($input, 8192);
            if (!is_string($chunk) || strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                fclose($input);
                throw new \RuntimeException('HTTPS response is too large or unreadable.');
            }
            $body .= $chunk;
        }
        fclose($input);
        $status = 0;
        $headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/\AHTTP\/\S+\s+(\d+)/', $line, $match) === 1) {
                $status = (int) $match[1];
            } elseif (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    private function normalizeResponse(array $response): array
    {
        return [
            'status' => (int) ($response['status'] ?? 0),
            'headers' => is_array($response['headers'] ?? null) ? $response['headers'] : [],
            'body' => is_string($response['body'] ?? null) ? $response['body'] : '',
            'location' => is_string($response['location'] ?? null) ? $response['location'] : null,
        ];
    }
}
