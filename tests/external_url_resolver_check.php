<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Tomos\ExternalUrlResolver;
use Tomos\MarkdownParser;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function same(string $expected, ?string $actual, string $message): void
{
    check($actual === $expected, $message . "\nExpected: {$expected}\nActual: " . (string) $actual);
}

$youtube = '<div class="youtube-embed"><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="YouTube video player" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>';
same($youtube, (new ExternalUrlResolver())->resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30s'), 'YouTube output changed');

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-external-url-' . bin2hex(random_bytes(5));
$calls = [];
$transport = static function (string $url) use (&$calls): array {
    $calls[] = $url;
    if (strpos($url, 'read.amazon.com.au/kp/api/oembed') !== false) {
        if (strpos($url, '4409030949') !== false) {
            return ['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode(['title' => '四方対象: オブジェクト指向存在論入門', 'author_name' => 'グレアム・ハーマン'])];
        }
        return ['status' => 404, 'body' => 'not found'];
    }
    if ($url === 'https://link.amazon/B001S989j') {
        return ['status' => 302, 'location' => 'https://www.amazon.co.jp/dp/4409030949'];
    }
    if ($url === 'https://amzn.asia/d/0248i9Xw') {
        return ['status' => 301, 'location' => 'https://www.amazon.co.jp/商品名/dp/4409030949?tag=test-22'];
    }
    if ($url === 'https://link.amazon/loop') {
        return ['status' => 302, 'location' => 'https://link.amazon/loop'];
    }
    if ($url === 'https://link.amazon/evil') {
        return ['status' => 302, 'location' => 'https://evil.example/dp/4409030949'];
    }
    return ['status' => 500, 'body' => ''];
};
$publicDns = static fn (string $host): array => ['93.184.216.34'];
$resolver = new ExternalUrlResolver($tmp, $transport, $publicDns);

$amazon = $resolver->resolve('https://www.amazon.co.jp/dp/4409030949?tag=xxxxx-22');
check(is_string($amazon) && strpos($amazon, '四方対象: オブジェクト指向存在論入門') !== false, 'Amazon direct URL title was not rendered');
check(strpos($amazon, 'グレアム・ハーマン') !== false, 'Amazon author_name was not rendered');
check(strpos($amazon, 'href="https://www.amazon.co.jp/dp/4409030949?tag=xxxxx-22"') !== false, 'Amazon sourceUrl was not preserved');
check(is_string($resolver->resolve('https://www.amazon.co.jp/商品名/dp/4409030949')), 'Amazon title/dp URL was not recognized');
check(is_string($resolver->resolve('https://www.amazon.co.jp/gp/product/4409030949?tag=test-22')), 'Amazon gp/product URL was not recognized');

$short = $resolver->resolve('https://link.amazon/B001S989j');
check(is_string($short) && strpos($short, 'href="https://link.amazon/B001S989j"') !== false, 'link.amazon sourceUrl was changed');
$shared = $resolver->resolve('https://amzn.asia/d/0248i9Xw');
check(is_string($shared) && strpos($shared, 'href="https://amzn.asia/d/0248i9Xw"') !== false, 'amzn.asia sourceUrl was changed');

$before = count(array_filter($calls, static fn (string $url): bool => strpos($url, 'read.amazon.com.au') !== false));
$resolver->resolve('https://www.amazon.co.jp/dp/4409030949?tag=xxxxx-22');
$after = count(array_filter($calls, static fn (string $url): bool => strpos($url, 'read.amazon.com.au') !== false));
check($before === $after, 'Amazon oEmbed cache was not reused');

$fallback = $resolver->resolve('https://www.amazon.co.jp/dp/0123456789');
check(is_string($fallback) && strpos($fallback, 'Amazon.co.jp') !== false && strpos($fallback, '商品を見る') !== false, 'Amazon failure did not fallback');
check($resolver->resolve('https://amazon.co.jp.evil.example/dp/4409030949') === null, 'lookalike Amazon host was accepted');
check($resolver->resolve('https://www.amazon.co.jp/dp/123') === null, 'invalid ASIN was accepted');
check(strpos((string) $resolver->resolve('https://link.amazon/loop'), '商品を見る') !== false, 'redirect loop did not fallback safely');
check(strpos((string) $resolver->resolve('https://link.amazon/evil'), '商品を見る') !== false, 'non-Amazon redirect did not fallback safely');

$privateResolver = new ExternalUrlResolver('', $transport, static fn (string $host): array => ['192.168.1.10']);
check(strpos((string) $privateResolver->resolve('https://link.amazon/B001S989j'), '商品を見る') !== false, 'private redirect address did not fail safely');

same('<p><a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ">YouTube</a></p>', (new MarkdownParser())->toHtml('[YouTube](https://www.youtube.com/watch?v=dQw4w9WgXcQ)'), 'Markdown link was embedded');
check(strpos((new MarkdownParser())->toHtml('text https://www.amazon.co.jp/dp/4409030949'), 'external-card') === false, 'inline Amazon URL was embedded');
check(strpos((new MarkdownParser())->toHtml("```\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\n```"), 'youtube-embed') === false, 'code block was embedded');

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($child) ? removeTree($child) : @unlink($child);
    }
    @rmdir($path);
}
removeTree($tmp);

echo "external_url_resolver_check: OK\n";
