<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;
use Throwable;

final class UpdatePackageDownloader
{
    public const MAX_ZIP_BYTES = 52428800;
    public const CONNECT_TIMEOUT = 10;
    public const TOTAL_TIMEOUT = 120;
    public const MAX_REDIRECTS = 3;

    private $fixtureTransport;
    private $curlAvailable;
    private $streamAvailable;

    public function __construct(
        ?callable $fixtureTransport = null,
        ?bool $curlAvailable = null,
        ?bool $streamAvailable = null
    ) {
        $this->fixtureTransport = $fixtureTransport;
        $this->curlAvailable = $curlAvailable;
        $this->streamAvailable = $streamAvailable;
    }

    public function download(string $packageUrl, string $expectedSha256, string $destination): array
    {
        $this->assertPackageUrl($packageUrl);
        $expectedSha256 = strtolower($expectedSha256);
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256) !== 1) {
            $this->fail('sha256', '期待するSHA-256が不正です。');
        }
        $this->assertDestination($destination);

        $handle = @fopen($destination, 'xb');
        if (!is_resource($handle)) {
            $this->fail('destination', '更新ZIPの保存先を作成できません。');
        }
        fclose($handle);

        try {
            $url = $packageUrl;
            for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
                $this->assertPackageUrl($url);
                $this->resetDestination($destination);
                $response = $this->fixtureTransport !== null
                    ? $this->fixture($url, $destination)
                    : $this->production($url, $destination);
                $status = (int) ($response['status'] ?? 0);
                $contentLength = $response['content_length'] ?? null;
                if ($contentLength !== null) {
                    if (!is_int($contentLength) && !ctype_digit((string) $contentLength)) {
                        $this->fail('content_length', 'Content-Lengthが不正です。');
                    }
                    $contentLength = (int) $contentLength;
                    if ($contentLength < 0 || $contentLength > self::MAX_ZIP_BYTES) {
                        $this->fail('size', '更新ZIPのサイズが上限を超えています。');
                    }
                }
                if ($status >= 300 && $status < 400 && isset($response['location'])) {
                    if ($redirect >= self::MAX_REDIRECTS) {
                        $this->fail('redirect_limit', '更新ZIPのリダイレクト回数が上限を超えています。');
                    }
                    $url = $this->resolveUrl($url, (string) $response['location']);
                    continue;
                }
                if ($status < 200 || $status >= 300) {
                    $this->fail('http', '更新ZIPを取得できませんでした。HTTPエラーが返されました。');
                }
                $size = @filesize($destination);
                if ($size === false || $size > self::MAX_ZIP_BYTES) {
                    $this->fail('size', '更新ZIPのサイズが上限を超えています。');
                }
                if ($contentLength !== null && (int) $size !== $contentLength) {
                    $this->fail('content_length', 'Content-Lengthと受信したファイルサイズが一致しません。');
                }
                $actualSha256 = @hash_file('sha256', $destination);
                if (!is_string($actualSha256) || !hash_equals($expectedSha256, strtolower($actualSha256))) {
                    $this->fail('sha256', '更新ZIPのSHA-256が一致しません。');
                }
                return [
                    'url' => $url,
                    'size' => (int) $size,
                    'sha256' => strtolower($actualSha256),
                    'path' => $destination,
                ];
            }
            $this->fail('redirect_limit', '更新ZIPの取得に失敗しました。');
        } catch (Throwable $exception) {
            @unlink($destination);
            throw $exception;
        }
    }

    private function fixture(string $url, string $destination): array
    {
        $response = call_user_func($this->fixtureTransport, $url, $destination, self::MAX_ZIP_BYTES);
        if (!is_array($response)) {
            $this->fail('transport', '更新ZIPのfixture応答が不正です。');
        }
        return $response;
    }

    private function production(string $url, string $destination): array
    {
        $curlAvailable = $this->curlAvailable !== null ? $this->curlAvailable : function_exists('curl_init');
        $streamAvailable = $this->streamAvailable !== null
            ? $this->streamAvailable
            : (bool) ini_get('allow_url_fopen');
        if ($curlAvailable) {
            return $this->curl($url, $destination);
        }
        if ($streamAvailable) {
            return $this->stream($url, $destination);
        }
        $this->fail('environment', 'cURLまたはHTTPS streamを利用できません。');
    }

    private function curl(string $url, string $destination): array
    {
        $handle = @fopen($destination, 'wb');
        if (!is_resource($handle)) {
            $this->fail('destination', '更新ZIPの保存先を開けません。');
        }
        $location = null;
        $contentLength = null;
        $invalidContentLength = false;
        $bytes = 0;
        $tooLarge = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location, &$contentLength, &$invalidContentLength, &$tooLarge): int {
                $trimmed = trim($line);
                if (stripos($trimmed, 'Location:') === 0) {
                    $location = trim(substr($trimmed, 9));
                }
                if (stripos($trimmed, 'Content-Length:') === 0) {
                    $value = trim(substr($trimmed, 15));
                    if (!ctype_digit($value)) {
                        $invalidContentLength = true;
                    } else {
                        $contentLength = (int) $value;
                        if ($contentLength > self::MAX_ZIP_BYTES) {
                            $tooLarge = true;
                        }
                    }
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $data) use ($handle, &$bytes, &$tooLarge): int {
                if ($tooLarge) {
                    return 0;
                }
                $bytes += strlen($data);
                if ($bytes > self::MAX_ZIP_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $written = fwrite($handle, $data);
                return $written === false ? 0 : $written;
            },
        ]);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        fclose($handle);
        if ($invalidContentLength) {
            $this->fail('content_length', 'Content-Lengthが不正です。');
        }
        if ($tooLarge) {
            $this->fail('size', '更新ZIPのサイズが上限を超えています。');
        }
        if ($ok === false) {
            $this->fail('transport', $error !== '' ? $error : 'cURLで更新ZIPを取得できませんでした。');
        }
        return ['status' => $status, 'location' => $location, 'content_length' => $contentLength];
    }

    private function stream(string $url, string $destination): array
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
        $status = 0;
        $location = null;
        $contentLength = null;
        $invalidContentLength = false;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/\AHTTP\/\S+\s+(\d+)/', $line, $match) === 1) {
                $status = (int) $match[1];
            } elseif (stripos($line, 'Location:') === 0) {
                $location = trim(substr(trim($line), 9));
            } elseif (stripos($line, 'Content-Length:') === 0) {
                $value = trim(substr(trim($line), 15));
                if (!ctype_digit($value)) {
                    $invalidContentLength = true;
                } else {
                    $contentLength = (int) $value;
                }
            }
        }
        if ($invalidContentLength) {
            fclose($input);
            $this->fail('content_length', 'Content-Lengthが不正です。');
        }
        if ($contentLength !== null && $contentLength > self::MAX_ZIP_BYTES) {
            fclose($input);
            $this->fail('size', '更新ZIPのサイズが上限を超えています。');
        }
        $output = @fopen($destination, 'wb');
        if (!is_resource($output)) {
            fclose($input);
            $this->fail('destination', '更新ZIPの保存先を開けません。');
        }
        $bytes = 0;
        while ($status >= 200 && $status < 300 && !feof($input)) {
            $chunk = fread($input, 65536);
            if (!is_string($chunk)) {
                fclose($input);
                fclose($output);
                $this->fail('transport', 'HTTPS streamを読み取れませんでした。');
            }
            $bytes += strlen($chunk);
            if ($bytes > self::MAX_ZIP_BYTES || ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk))) {
                fclose($input);
                fclose($output);
                $this->fail('size', '更新ZIPのサイズが上限を超えたか、保存に失敗しました。');
            }
        }
        fclose($input);
        fclose($output);
        return ['status' => $status, 'location' => $location, 'content_length' => $contentLength];
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
            $this->fail('package_url', '更新ZIPのURLが許可されていません。');
        }
    }

    private function assertDestination(string $destination): void
    {
        if ($destination === '' || @lstat($destination) !== false) {
            $this->fail('destination', '更新ZIPの保存先が空か、既に存在します。');
        }
        if (strpos($destination, "\0") !== false) {
            $this->fail('destination', '更新ZIPの保存先が不正です。');
        }
    }

    private function resetDestination(string $destination): void
    {
        $handle = @fopen($destination, 'wb');
        if (!is_resource($handle)) {
            $this->fail('destination', '更新ZIPの保存先を開けません。');
        }
        fclose($handle);
    }

    private function resolveUrl(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts)) {
            $this->fail('transport', 'リダイレクト先を解釈できません。');
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
        throw new UpdatePackageDownloaderException($message, $code);
    }
}

final class UpdatePackageDownloaderException extends RuntimeException
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
