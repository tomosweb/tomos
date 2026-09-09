<?php

declare(strict_types=1);

namespace Tomos;

final class AmazonCreatorsApiClient
{
    public const TOKEN_ENDPOINT = 'https://api.amazon.co.jp/auth/o2/token';
    public const API_ENDPOINT = 'https://creatorsapi.amazon/catalog/v1/getItems';
    public const MARKETPLACE = 'www.amazon.co.jp';
    public const TOKEN_REFRESH_MARGIN = 60;
    public const CONNECT_TIMEOUT = 10;
    public const TOTAL_TIMEOUT = 20;
    private const MAX_RESPONSE_BYTES = 262144;

    private array $config;
    private string $cacheDir;
    private $fixtureTransport;
    private $clock;

    public function __construct(
        array $config,
        string $cacheDir,
        ?callable $fixtureTransport = null,
        ?callable $clock = null
    ) {
        $this->config = $config;
        $this->cacheDir = rtrim($cacheDir, DIRECTORY_SEPARATOR);
        $this->fixtureTransport = $fixtureTransport;
        $this->clock = $clock ?? static function (): int {
            return time();
        };
    }

    /**
     * Return raw GetItems data for one ASIN. Callers must not use detailPageURL
     * from this response as a replacement for the user's source URL.
     */
    public function getItem(string $asin): ?array
    {
        if (preg_match('/\A[A-Za-z0-9]{10}\z/', $asin) !== 1) {
            return null;
        }

        try {
            $this->validateCredentials();
            $token = $this->accessToken();
            $payload = [
                'itemIds' => [strtoupper($asin)],
                'itemIdType' => 'ASIN',
                'marketplace' => self::MARKETPLACE,
                'partnerTag' => $this->credential('partner_tag'),
                'resources' => [
                    'itemInfo.title',
                    'images.primary.medium',
                    'images.primary.large',
                    'images.primary.small',
                ],
            ];
            $body = $this->encodeJson($payload);
            $response = $this->request('POST', self::API_ENDPOINT, [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'x-marketplace: ' . self::MARKETPLACE,
            ], $body);
            if ($response['status'] < 200 || $response['status'] >= 300) {
                return null;
            }

            $decoded = json_decode($response['body'], true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
            $items = $decoded['itemsResult']['items'] ?? null;
            if (!is_array($items)) {
                return null;
            }
            foreach ($items as $item) {
                if (is_array($item) && strtoupper((string) ($item['asin'] ?? '')) === strtoupper($asin)) {
                    return $item;
                }
            }
        } catch (\Throwable $exception) {
            return null;
        }

        return null;
    }

    private function accessToken(): string
    {
        $now = $this->now();
        $cached = $this->readTokenCache();
        if ($cached !== null && (int) $cached['expires_at'] > $now + self::TOKEN_REFRESH_MARGIN) {
            return $cached['access_token'];
        }

        $body = $this->encodeJson([
            'grant_type' => 'client_credentials',
            'client_id' => $this->credential('client_id'),
            'client_secret' => $this->credential('client_secret'),
            'scope' => 'creatorsapi::default',
        ]);
        $response = $this->request('POST', self::TOKEN_ENDPOINT, [
            'Content-Type: application/json',
        ], $body);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Amazon access token request failed.');
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE
            || !is_string($decoded['access_token'] ?? null)
            || $decoded['access_token'] === ''
            || !is_numeric($decoded['expires_in'] ?? null)
            || (int) $decoded['expires_in'] <= 0
        ) {
            throw new \RuntimeException('Amazon access token response is invalid.');
        }

        $token = $decoded['access_token'];
        $expiresAt = $now + (int) $decoded['expires_in'];
        $this->writeTokenCache($token, $expiresAt);
        return $token;
    }

    private function credential(string $name): string
    {
        $amazon = is_array($this->config['amazon'] ?? null) ? $this->config['amazon'] : [];
        $value = $amazon[$name] ?? '';
        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException('Amazon credential is not configured.');
        }
        return $value;
    }

    private function validateCredentials(): void
    {
        $this->credential('client_id');
        $this->credential('client_secret');
        $this->credential('partner_tag');
    }

    private function readTokenCache(): ?array
    {
        $raw = @file_get_contents($this->tokenCachePath());
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || !is_string($decoded['access_token'] ?? null)
            || $decoded['access_token'] === ''
            || !is_numeric($decoded['expires_at'] ?? null)
        ) {
            return null;
        }
        return [
            'access_token' => $decoded['access_token'],
            'expires_at' => (int) $decoded['expires_at'],
        ];
    }

    private function writeTokenCache(string $token, int $expiresAt): void
    {
        $directory = dirname($this->tokenCachePath());
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }
        $json = json_encode([
            'access_token' => $token,
            'expires_at' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $temporary = $this->tokenCachePath() . '.tmp-' . bin2hex(random_bytes(8));
        if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            return;
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->tokenCachePath())) {
            @unlink($temporary);
        }
    }

    private function tokenCachePath(): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . 'amazon' . DIRECTORY_SEPARATOR
            . 'token-' . hash('sha256', $this->credential('client_id')) . '.json';
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }

    private function encodeJson(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Amazon request could not be encoded.');
        }
        return $json;
    }

    private function request(string $method, string $url, array $headers, string $body): array
    {
        if ($url !== self::TOKEN_ENDPOINT && $url !== self::API_ENDPOINT) {
            throw new \RuntimeException('Amazon API endpoint is not allowed.');
        }
        if ($this->fixtureTransport !== null) {
            $response = call_user_func($this->fixtureTransport, $method, $url, $headers, $body);
            if (!is_array($response) || !is_numeric($response['status'] ?? null) || !is_string($response['body'] ?? null)) {
                throw new \RuntimeException('Amazon API fixture response is invalid.');
            }
            return ['status' => (int) $response['status'], 'body' => $response['body']];
        }
        if (function_exists('curl_init')) {
            return $this->curl($method, $url, $headers, $body);
        }
        if ((bool) ini_get('allow_url_fopen')) {
            return $this->stream($method, $url, $headers, $body);
        }
        throw new \RuntimeException('cURL or HTTPS stream is unavailable.');
    }

    private function curl(string $method, string $url, array $headers, string $body): array
    {
        $responseBody = '';
        $tooLarge = false;
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Could not initialize Amazon API request.');
        }
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_POST => $method === 'POST',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (&$responseBody, &$tooLarge): int {
                if (strlen($responseBody) + strlen($data) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $data;
                return strlen($data);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($curl);
        }
        if ($tooLarge || $ok === false) {
            throw new \RuntimeException('Amazon API request failed.');
        }
        return ['status' => $status, 'body' => $responseBody];
    }

    private function stream(string $method, string $url, array $headers, string $body): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body,
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
            throw new \RuntimeException('Amazon API stream could not be opened.');
        }
        $responseBody = '';
        while (!feof($input)) {
            $chunk = fread($input, 8192);
            if (!is_string($chunk) || strlen($responseBody) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                fclose($input);
                throw new \RuntimeException('Amazon API response is invalid.');
            }
            $responseBody .= $chunk;
        }
        fclose($input);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/\AHTTP\/\S+\s+(\d+)/', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }
        return ['status' => $status, 'body' => $responseBody];
    }
}
