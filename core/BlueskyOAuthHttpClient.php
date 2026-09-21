<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyOAuthHttpResponse
{
    public int $status;
    public string $body;
    /** @var array<string,string> */
    public array $headers;

    /** @param array<string,string> $headers */
    public function __construct(int $status, string $body, array $headers)
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
    }

    public function header(string $name): string
    {
        return (string) ($this->headers[strtolower($name)] ?? '');
    }
}

final class BlueskyOAuthHttpClient
{
    private const MAX_BYTES = 262144;
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 12;

    public function get(string $url, array $headers = []): BlueskyOAuthHttpResponse
    {
        return $this->request('GET', $url, null, $headers, self::MAX_BYTES);
    }

    public function getWithLimit(string $url, int $maxBytes, array $headers = []): BlueskyOAuthHttpResponse
    {
        if ($maxBytes < 1 || $maxBytes > 2000000) {
            throw new \InvalidArgumentException('HTTP response byte limit is invalid.');
        }

        return $this->request('GET', $url, null, $headers, $maxBytes);
    }

    public function postForm(string $url, array $form, array $headers = []): BlueskyOAuthHttpResponse
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        return $this->request('POST', $url, http_build_query($form, '', '&', PHP_QUERY_RFC3986), $headers, self::MAX_BYTES);
    }

    public function postJson(string $url, array $payload, array $headers = []): BlueskyOAuthHttpResponse
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('JSON payload could not be encoded.');
        }
        $headers[] = 'Content-Type: application/json';
        return $this->request('POST', $url, $json, $headers, self::MAX_BYTES);
    }

    public function postBinary(string $url, string $body, string $contentType, array $headers = []): BlueskyOAuthHttpResponse
    {
        $contentType = strtolower(trim($contentType));
        if (!in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \InvalidArgumentException('Unsupported Bluesky blob content type.');
        }
        $headers[] = 'Content-Type: ' . $contentType;
        $headers[] = 'Content-Length: ' . strlen($body);
        return $this->request('POST', $url, $body, $headers, self::MAX_BYTES);
    }

    /** @return array<string,mixed> */
    public function json(BlueskyOAuthHttpResponse $response): array
    {
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('OAuth server returned invalid JSON.');
        }
        return $decoded;
    }

    private function request(string $method, string $url, ?string $body, array $headers, int $maxBytes): BlueskyOAuthHttpResponse
    {
        [$host, $port, $address] = $this->resolvePublicTarget($url);
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Bluesky OAuth requires the PHP cURL extension.');
        }

        $responseHeaders = [];
        $responseBody = '';
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('HTTP client could not be initialized.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => array_values($headers),
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (strpos($address, ':') !== false ? '[' . $address . ']' : $address)],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if ($trimmed !== '' && strpos($trimmed, ':') !== false) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, $maxBytes): int {
                if (strlen($responseBody) + strlen($chunk) > $maxBytes) {
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($ok === false) {
            throw new \RuntimeException($error !== '' ? 'OAuth HTTP request failed: ' . $error : 'OAuth HTTP request failed.');
        }
        return new BlueskyOAuthHttpResponse($status, $responseBody, $responseHeaders);
    }

    /** @return array{0:string,1:int,2:string} */
    private function resolvePublicTarget(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new \InvalidArgumentException('OAuth URL must be a public HTTPS URL.');
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($host === '' || $port < 1 || $port > 65535 || $host === 'localhost' || substr($host, -6) === '.local') {
            throw new \InvalidArgumentException('OAuth URL host is not public.');
        }

        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses[] = $host;
        } else {
            foreach ([DNS_A, DNS_AAAA] as $recordType) {
                $records = @dns_get_record($host, $recordType);
                if (!is_array($records)) {
                    continue;
                }
                foreach ($records as $record) {
                    if (isset($record['ip'])) {
                        $addresses[] = (string) $record['ip'];
                    } elseif (isset($record['ipv6'])) {
                        $addresses[] = (string) $record['ipv6'];
                    }
                }
            }
        }

        $public = [];
        foreach (array_values(array_unique($addresses)) as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false) {
                $public[] = $address;
            }
        }
        if ($public === [] || count($public) !== count(array_values(array_unique($addresses)))) {
            throw new \InvalidArgumentException('OAuth URL resolves to a non-public address.');
        }

        usort($public, static function (string $left, string $right): int {
            $leftV4 = filter_var($left, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $rightV4 = filter_var($right, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            return $leftV4 === $rightV4 ? 0 : ($leftV4 ? -1 : 1);
        });

        return [$host, $port, $public[0]];
    }
}
