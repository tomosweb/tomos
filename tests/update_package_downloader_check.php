<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdatePackageDownloader.php';

use Tomos\UpdatePackageDownloader;
use Tomos\UpdatePackageDownloaderException;

$passes = 0;
$tmp = sys_get_temp_dir() . '/tomos-update-package-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700, true);

function check(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

function expectError(callable $callable, string $code, string $label): void
{
    try {
        $callable();
    } catch (UpdatePackageDownloaderException $exception) {
        check($exception->errorCode() === $code, $label . ' expected ' . $code . ', got ' . $exception->errorCode());
        return;
    }
    throw new RuntimeException($label . ' did not fail');
}

function url(): string
{
    return 'https://tomoswords.org/assets/updates/releases/0.1.0-alpha.18/tomos-update-0.1.0-alpha.18.zip';
}

function fixture(array $responses): callable
{
    return static function (string $url, string $destination, int $maxBytes) use ($responses): array {
        if (!isset($responses[$url])) {
            throw new RuntimeException('missing fixture for ' . $url);
        }
        $response = $responses[$url];
        if (isset($response['content'])) {
            $handle = fopen($destination, 'wb');
            fwrite($handle, (string) $response['content']);
            fclose($handle);
        }
        if (isset($response['write_bytes'])) {
            $handle = fopen($destination, 'wb');
            $remaining = (int) $response['write_bytes'];
            while ($remaining > 0) {
                $chunk = min($remaining, 8192);
                fwrite($handle, str_repeat('x', $chunk));
                $remaining -= $chunk;
            }
            fclose($handle);
        }
        return $response;
    };
}

function newPath(string $tmp): string
{
    return $tmp . '/' . bin2hex(random_bytes(4)) . '.zip';
}

$content = "Tomos update fixture\n";
$hash = hash('sha256', $content);
$responses = [url() => ['status' => 200, 'content' => $content, 'content_length' => strlen($content)]];
$path = newPath($tmp);
$result = (new UpdatePackageDownloader(fixture($responses)))->download(url(), strtoupper($hash), $path);
check($result['url'] === url(), 'normal download returns final URL');
check($result['size'] === strlen($content), 'normal download returns size');
check($result['sha256'] === $hash, 'normal download returns lowercase SHA-256');
check($result['path'] === $path && file_get_contents($path) === $content, 'normal download writes the destination');
unlink($path);

$invalidUrls = [
    'http://tomoswords.org/update.zip',
    'https://evil.example/update.zip',
    'https://user:pass@tomoswords.org/update.zip',
    'https://tomoswords.org/update.zip#fragment',
    'https://tomoswords.org:8443/update.zip',
];
foreach ($invalidUrls as $invalidUrl) {
    expectError(static function () use ($invalidUrl, $tmp, $hash): void {
        (new UpdatePackageDownloader(fixture([])))->download($invalidUrl, $hash, newPath($tmp));
    }, 'package_url', 'invalid package URL');
}

$redirectUrl = 'https://tomoswords.org/assets/updates/redirected.zip';
$redirectResponses = [
    url() => ['status' => 302, 'location' => '/assets/updates/redirected.zip'],
    $redirectUrl => ['status' => 200, 'content' => $content, 'content_length' => strlen($content)],
];
$path = newPath($tmp);
$redirectResult = (new UpdatePackageDownloader(fixture($redirectResponses)))->download(url(), $hash, $path);
check($redirectResult['url'] === $redirectUrl, 'same-host redirect is followed after validation');
unlink($path);

foreach ([
    'https://evil.example/update.zip' => 'redirect host',
    'http://tomoswords.org/update.zip' => 'redirect downgrade',
] as $redirectLocation => $label) {
    $responses = [url() => ['status' => 302, 'location' => $redirectLocation]];
    $path = newPath($tmp);
    expectError(static function () use ($responses, $path, $hash): void {
        (new UpdatePackageDownloader(fixture($responses)))->download(url(), $hash, $path);
    }, 'package_url', $label);
    check(!file_exists($path), $label . ' removes destination');
}

$path = newPath($tmp);
expectError(static function () use ($path, $hash): void {
    (new UpdatePackageDownloader(static function (string $url, string $destination, int $maxBytes): array {
        return ['status' => 302, 'location' => '/redirect.zip'];
    }))->download(url(), $hash, $path);
}, 'redirect_limit', 'redirect limit');
check(!file_exists($path), 'redirect limit removes destination');

foreach ([404, 500] as $status) {
    $path = newPath($tmp);
    expectError(static function () use ($status, $path, $hash): void {
        (new UpdatePackageDownloader(fixture([url() => ['status' => $status]])))->download(url(), $hash, $path);
    }, 'http', 'HTTP ' . $status);
    check(!file_exists($path), 'HTTP error removes destination');
}

$path = newPath($tmp);
expectError(static function () use ($path, $hash): void {
    (new UpdatePackageDownloader(fixture([url() => ['status' => 200, 'write_bytes' => UpdatePackageDownloader::MAX_ZIP_BYTES + 1]])))->download(url(), $hash, $path);
}, 'size', 'received size limit');
check(!file_exists($path), 'received size limit removes destination');

$path = newPath($tmp);
expectError(static function () use ($path, $hash, $content): void {
    (new UpdatePackageDownloader(fixture([url() => ['status' => 200, 'content' => $content, 'content_length' => UpdatePackageDownloader::MAX_ZIP_BYTES + 1]])))->download(url(), $hash, $path);
}, 'size', 'Content-Length size limit');
check(!file_exists($path), 'Content-Length size limit removes destination');

$path = newPath($tmp);
expectError(static function () use ($path, $hash, $content): void {
    (new UpdatePackageDownloader(fixture([url() => ['status' => 200, 'content' => $content, 'content_length' => strlen($content) + 1]])))->download(url(), $hash, $path);
}, 'content_length', 'Content-Length mismatch');
check(!file_exists($path), 'Content-Length mismatch removes destination');

$path = newPath($tmp);
expectError(static function () use ($path, $content): void {
    (new UpdatePackageDownloader(fixture([url() => ['status' => 200, 'content' => $content]])))->download(url(), str_repeat('0', 64), $path);
}, 'sha256', 'SHA-256 mismatch');
check(!file_exists($path), 'SHA-256 mismatch removes destination');

foreach (['bad', str_repeat('a', 63), str_repeat('g', 64)] as $expected) {
    expectError(static function () use ($expected, $tmp): void {
        (new UpdatePackageDownloader(fixture([])))->download(url(), $expected, newPath($tmp));
    }, 'sha256', 'invalid expected SHA-256');
}

$existing = $tmp . '/existing.zip';
file_put_contents($existing, 'keep');
expectError(static function () use ($existing, $hash): void {
    (new UpdatePackageDownloader(fixture([])))->download(url(), $hash, $existing);
}, 'destination', 'existing destination');
check(file_get_contents($existing) === 'keep', 'existing destination is not overwritten');

$path = newPath($tmp);
$responses = [url() => ['status' => 200, 'content' => $content]];
expectError(static function () use ($responses, $path, $hash): void {
    (new UpdatePackageDownloader(static function (string $url, string $destination, int $maxBytes): string {
        return 'invalid';
    }))->download(url(), $hash, $path);
}, 'transport', 'invalid fixture response');
check(!file_exists($path), 'invalid fixture response removes destination');

$path = newPath($tmp);
expectError(static function () use ($path, $hash): void {
    (new UpdatePackageDownloader(null, false, false))->download(url(), $hash, $path);
}, 'environment', 'unavailable transport');
check(!file_exists($path), 'environment error removes destination');

echo "update_package_downloader_check: {$passes} checks passed\n";

removeTree($tmp);

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        removeTree($path . '/' . $item);
    }
    @rmdir($path);
}
