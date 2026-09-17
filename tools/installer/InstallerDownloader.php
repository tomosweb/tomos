<?php

declare(strict_types=1);

final class InstallerDownloader
{
    public const POINTER_MAX_BYTES = 65536;
    public const MANIFEST_MAX_BYTES = 2097152;
    public const SIGNATURE_MAX_BYTES = 16384;
    public const ZIP_MAX_BYTES = 52428800;
    public const CONNECT_TIMEOUT = 10;
    public const TOTAL_TIMEOUT = 120;
    public const MAX_REDIRECTS = 3;

    public function __construct(?callable $fixtureTransport = null)
    {
        $this->fixtureTransport = $fixtureTransport;
    }

    private $fixtureTransport;

    public function download(string $url, string $destination, int $maxBytes, array $allowedHosts, string $errorCode): array
    {
        $current = $url;
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            InstallerSecurity::validateUrl($current, $allowedHosts, $errorCode);
            @unlink($destination);
            $result = $this->fixtureTransport !== null
                ? $this->fixture($current, $destination, $maxBytes)
                : $this->production($current, $destination, $maxBytes);
            $status = (int) ($result['status'] ?? 0);
            if ($status >= 300 && $status < 400 && isset($result['location'])) {
                if ($redirect >= self::MAX_REDIRECTS) {
                    @unlink($destination);
                    self::fail('asset_download', 'Redirect limit exceeded.');
                }
                $current = self::resolveUrl($current, (string) $result['location']);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                @unlink($destination);
                self::fail($errorCode, 'HTTP download failed.');
            }
            $size = filesize($destination);
            if ($size === false || $size > $maxBytes) {
                @unlink($destination);
                self::fail($errorCode, 'Downloaded response exceeds the hard limit.');
            }
            if (isset($result['content_length']) && (int) $result['content_length'] !== (int) $size) {
                @unlink($destination);
                self::fail('asset_size', 'Content-Length does not match downloaded bytes.');
            }
            return ['url' => $current, 'status' => $status, 'size' => (int) $size, 'headers' => $result['headers'] ?? []];
        }
        self::fail($errorCode, 'Download failed.');
    }

    private function fixture(string $url, string $destination, int $maxBytes): array
    {
        $result = call_user_func($this->fixtureTransport, $url, $destination, $maxBytes);
        if (!is_array($result)) {
            self::fail('asset_download', 'Fixture transport returned an invalid result.');
        }
        return $result;
    }

    private function production(string $url, string $destination, int $maxBytes): array
    {
        if (function_exists('curl_init')) {
            return $this->curl($url, $destination, $maxBytes);
        }
        if ((bool) ini_get('allow_url_fopen')) {
            return $this->stream($url, $destination, $maxBytes);
        }
        self::fail('environment', 'Neither cURL nor allow_url_fopen is available.');
    }

    private function curl(string $url, string $destination, int $maxBytes): array
    {
        $handle = @fopen($destination, 'xb');
        if (!is_resource($handle)) {
            self::fail('asset_download', 'Could not create download file.');
        }
        $headers = [];
        $contentLength = null;
        $bytes = 0;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers, &$contentLength): int {
                $trimmed = trim($line);
                if (stripos($trimmed, 'Content-Length:') === 0) {
                    $contentLength = (int) trim(substr($trimmed, 15));
                }
                if (stripos($trimmed, 'Location:') === 0) {
                    $headers['location'] = trim(substr($trimmed, 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use ($handle, &$bytes, $maxBytes): int {
                $bytes += strlen($data);
                if ($bytes > $maxBytes) {
                    return 0;
                }
                $written = fwrite($handle, $data);
                return $written === false ? 0 : $written;
            },
        ]);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        fclose($handle);
        if ($ok === false) {
            @unlink($destination);
            self::fail('asset_download', $error !== '' ? $error : 'cURL download failed.');
        }
        return ['status' => $status, 'headers' => $headers, 'location' => $headers['location'] ?? null, 'content_length' => $contentLength];
    }

    private function stream(string $url, string $destination, int $maxBytes): array
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
            self::fail('asset_download', 'HTTPS stream could not be opened.');
        }
        $output = @fopen($destination, 'xb');
        if (!is_resource($output)) {
            fclose($input);
            self::fail('asset_download', 'Could not create download file.');
        }
        $bytes = 0;
        while (!feof($input)) {
            $chunk = fread($input, 65536);
            if (!is_string($chunk)) {
                fclose($input);
                fclose($output);
                @unlink($destination);
                self::fail('asset_download', 'HTTPS stream read failed.');
            }
            $bytes += strlen($chunk);
            if ($bytes > $maxBytes || ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk))) {
                fclose($input);
                fclose($output);
                @unlink($destination);
                self::fail('asset_download', 'HTTPS stream exceeded the limit or could not be written.');
            }
        }
        fclose($input);
        fclose($output);
        $status = 0;
        $headers = [];
        $contentLength = null;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/\AHTTP\/\S+\s+(\d+)/', $line, $match)) {
                $status = (int) $match[1];
            }
            if (stripos($line, 'Location:') === 0) {
                $headers['location'] = trim(substr(trim($line), 9));
            }
            if (stripos($line, 'Content-Length:') === 0) {
                $contentLength = (int) trim(substr(trim($line), 15));
            }
        }
        return ['status' => $status, 'headers' => $headers, 'location' => $headers['location'] ?? null, 'content_length' => $contentLength];
    }

    private static function resolveUrl(string $base, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            self::fail('asset_download', 'Redirect URL could not be resolved.');
        }
        if (strpos($location, '/') === 0) {
            return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $location;
        }
        $path = isset($parts['path']) ? dirname($parts['path']) : '/';
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . rtrim($path, '/') . '/' . $location;
    }

    private static function fail(string $code, string $message): void
    {
        throw new InstallManifestException($code, $message);
    }
}
