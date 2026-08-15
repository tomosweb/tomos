<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;

final class UpdateReleaseProvider
{
    public const CATALOG_URL = 'https://tomoswords.org/assets/updates/catalog.json';
    public const CATALOG_MAX_BYTES = 65536;
    public const CONNECT_TIMEOUT = 10;
    public const TOTAL_TIMEOUT = 30;
    public const MAX_REDIRECTS = 3;

    private $fixtureTransport;

    public function __construct(?callable $fixtureTransport = null)
    {
        $this->fixtureTransport = $fixtureTransport;
    }

    /**
     * Fetch and validate the official catalog, then return only the next step.
     * A missing from-version is a normal "no update" result; transport and
     * validation failures are reported as UpdateReleaseProviderException.
     */
    public function getNextUpdate(string $currentVersion): array
    {
        $this->assertVersion($currentVersion, 'current_version');
        $catalog = $this->decodeCatalog($this->fetchCatalog());
        $updates = $catalog['updates'];
        foreach ($updates as $update) {
            if ($update['from'] === $currentVersion) {
                return [
                    'current_version' => $currentVersion,
                    'update_available' => true,
                    'next_version' => $update['to'],
                    'package_url' => $update['package_url'],
                    'sha256' => $update['sha256'],
                ];
            }
        }

        return [
            'current_version' => $currentVersion,
            'update_available' => false,
            'next_version' => null,
            'package_url' => null,
            'sha256' => null,
        ];
    }

    private function fetchCatalog(): string
    {
        $url = self::CATALOG_URL;
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $this->assertCatalogUrl($url);
            $response = $this->fixtureTransport !== null
                ? $this->fixture($url)
                : $this->production($url);
            $status = (int) ($response['status'] ?? 0);
            if ($status >= 300 && $status < 400 && isset($response['location'])) {
                if ($redirect >= self::MAX_REDIRECTS) {
                    $this->fail('redirect_limit', '更新カタログのリダイレクト回数が上限を超えています。');
                }
                $url = $this->resolveUrl($url, (string) $response['location']);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                $this->fail('http', '更新カタログを取得できませんでした。HTTPエラーが返されました。');
            }
            $body = $response['body'] ?? null;
            if (!is_string($body)) {
                $this->fail('transport', '更新カタログの応答を読み取れませんでした。');
            }
            if (strlen($body) > self::CATALOG_MAX_BYTES) {
                $this->fail('size', '更新カタログのサイズが上限を超えています。');
            }
            return $body;
        }
        $this->fail('redirect_limit', '更新カタログの取得に失敗しました。');
    }

    private function fixture(string $url): array
    {
        $response = call_user_func($this->fixtureTransport, $url, self::CATALOG_MAX_BYTES);
        if (!is_array($response)) {
            $this->fail('transport', '更新カタログのfixture応答が不正です。');
        }
        return $response;
    }

    private function production(string $url): array
    {
        if (function_exists('curl_init')) {
            return $this->curl($url);
        }
        if ((bool) ini_get('allow_url_fopen')) {
            return $this->stream($url);
        }
        $this->fail('environment', 'cURLまたはHTTPS streamを利用できません。');
    }

    private function curl(string $url): array
    {
        $body = '';
        $location = null;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
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
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (&$body): int {
                $body .= $data;
                return strlen($body) > self::CATALOG_MAX_BYTES ? 0 : strlen($data);
            },
        ]);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($ok === false && strlen($body) <= self::CATALOG_MAX_BYTES) {
            $this->fail('transport', $error !== '' ? $error : '更新カタログの取得に失敗しました。');
        }
        return ['status' => $status, 'body' => $body, 'location' => $location];
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
            $this->fail('transport', 'HTTPS streamを開けませんでした。');
        }
        $body = '';
        while (!feof($input)) {
            $chunk = fread($input, 8192);
            if (!is_string($chunk)) {
                fclose($input);
                $this->fail('transport', 'HTTPS streamを読み取れませんでした。');
            }
            $body .= $chunk;
            if (strlen($body) > self::CATALOG_MAX_BYTES) {
                fclose($input);
                $this->fail('size', '更新カタログのサイズが上限を超えています。');
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
        return ['status' => $status, 'body' => $body, 'location' => $location];
    }

    private function decodeCatalog(string $raw): array
    {
        $catalog = json_decode($raw, true);
        if (!is_array($catalog) || json_last_error() !== JSON_ERROR_NONE
            || ($catalog['schema'] ?? null) !== 1
            || ($catalog['product'] ?? null) !== 'Tomos'
            || !is_array($catalog['updates'] ?? null)
        ) {
            $this->fail('catalog', '更新カタログの形式が正しくありません。');
        }
        $seenFrom = [];
        foreach ($catalog['updates'] as $update) {
            if (!is_array($update)
                || !is_string($update['from'] ?? null)
                || !is_string($update['to'] ?? null)
                || !is_string($update['package_url'] ?? null)
                || !is_string($update['sha256'] ?? null)
            ) {
                $this->fail('catalog', '更新カタログのupdates構造が正しくありません。');
            }
            $from = $update['from'];
            $to = $update['to'];
            $this->assertVersion($from, 'from');
            $this->assertVersion($to, 'to');
            if (version_compare($from, $to, '>=')) {
                $this->fail('version', '更新カタログの更新順序が正しくありません。');
            }
            if (isset($seenFrom[$from])) {
                $this->fail('duplicate_from', '更新カタログに同じfromバージョンが重複しています。');
            }
            $seenFrom[$from] = true;
            $this->assertPackageUrl($update['package_url']);
            if (preg_match('/\A[a-f0-9]{64}\z/i', $update['sha256']) !== 1) {
                $this->fail('sha256', '更新カタログのSHA-256が不正です。');
            }
        }
        foreach ($catalog['updates'] as $update) {
            foreach (array_keys($seenFrom) as $intermediateFrom) {
                if (version_compare($update['from'], $intermediateFrom, '<')
                    && version_compare($intermediateFrom, $update['to'], '<')
                ) {
                    $this->fail('update_sequence', '更新カタログに中間バージョンを飛び越す更新経路があります。');
                }
            }
        }
        return $catalog;
    }

    private function assertVersion(string $version, string $field): void
    {
        if (preg_match('/\A[0-9]+(?:\.[0-9]+)*(?:-[0-9A-Za-z.-]+)?\z/', $version) !== 1) {
            $this->fail('version', '更新カタログの' . $field . 'バージョンが不正です。');
        }
    }

    private function assertPackageUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host !== 'tomoswords.org'
            || ($parts['path'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ($port !== null && (int) $port !== 443)
        ) {
            $this->fail('package_url', '更新カタログのpackage_urlが許可されていません。');
        }
    }

    private function assertCatalogUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host !== 'tomoswords.org'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ($port !== null && (int) $port !== 443)
        ) {
            $this->fail('catalog_url', '更新カタログURLが許可されていません。');
        }
    }

    private function resolveUrl(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts)) {
            $this->fail('redirect', '更新カタログのリダイレクト先を解釈できません。');
        }
        if (strpos($location, '//') === 0) {
            return (string) ($baseParts['scheme'] ?? 'https') . ':' . $location;
        }
        $prefix = (string) ($baseParts['scheme'] ?? 'https') . '://' . (string) ($baseParts['host'] ?? '');
        if (isset($baseParts['port'])) {
            $prefix .= ':' . (int) $baseParts['port'];
        }
        if ($location !== '' && $location[0] === '/') {
            return $prefix . $location;
        }
        $path = (string) ($baseParts['path'] ?? '/');
        return $prefix . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }

    private function fail(string $code, string $message): void
    {
        throw new UpdateReleaseProviderException($message, $code);
    }
}

final class UpdateReleaseProviderException extends RuntimeException
{
    private $errorCode;

    public function __construct(string $message, string $errorCode)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
