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

use Tomos\AmazonCreatorsApiClient;
use Tomos\AmazonProductProvider;
use Tomos\ConfigWriter;

function productAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function productAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function productConfig(): array
{
    return [
        'amazon' => [
            'client_id' => 'fixture-client-id',
            'client_secret' => 'fixture-client-secret',
            'partner_tag' => 'fixture-tag-22',
        ],
    ];
}

function productItem(string $asin, string $title = 'Fixture title', array $images = []): array
{
    return [
        'asin' => $asin,
        'detailPageURL' => 'https://www.amazon.co.jp/dp/' . $asin . '?tag=api-generated-22',
        'itemInfo' => [
            'title' => [
                'displayValue' => $title,
            ],
        ],
        'images' => [
            'primary' => $images,
        ],
    ];
}

function imageSizes(string $medium = '', string $large = '', string $small = ''): array
{
    $images = [];
    foreach (['medium' => $medium, 'large' => $large, 'small' => $small] as $size => $url) {
        if ($url !== '') {
            $images[$size] = ['url' => $url];
        }
    }
    return $images;
}

function removeProductTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeProductTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . '/tomos-amazon-product-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);

try {
    $now = 1000;
    $tokenRequests = 0;
    $itemRequests = 0;
    $requests = [];
    $transport = static function (string $method, string $url, array $headers, string $body) use (&$now, &$tokenRequests, &$itemRequests, &$requests): array {
        $requests[] = [$method, $url, $headers, $body];
        if ($url === AmazonCreatorsApiClient::TOKEN_ENDPOINT) {
            $tokenRequests++;
            return ['status' => 200, 'body' => json_encode([
                'access_token' => 'fixture-access-token-' . $tokenRequests,
                'expires_in' => 3600,
            ])];
        }
        if ($url !== AmazonCreatorsApiClient::API_ENDPOINT) {
            throw new RuntimeException('unexpected endpoint');
        }
        $itemRequests++;
        $payload = json_decode($body, true);
        productAssert(is_array($payload), 'GetItems request body is not JSON');
        productAssertSame(['B00FIXTR01'], $payload['itemIds'] ?? null, 'ASIN was not sent as itemIds');
        productAssertSame('ASIN', $payload['itemIdType'] ?? null, 'itemIdType is wrong');
        productAssertSame(AmazonCreatorsApiClient::MARKETPLACE, $payload['marketplace'] ?? null, 'marketplace parameter is wrong');
        productAssertSame('fixture-tag-22', $payload['partnerTag'] ?? null, 'partnerTag is wrong');
        productAssertSame([
            'itemInfo.title',
            'images.primary.medium',
            'images.primary.large',
            'images.primary.small',
        ], $payload['resources'] ?? null, 'resource list is wrong');
        return ['status' => 200, 'body' => json_encode([
            'itemsResult' => [
                'items' => [productItem(
                    'B00FIXTR01',
                    'Fixture title',
                    imageSizes('', 'https://m.media-amazon.com/images/I/large.jpg', 'https://m.media-amazon.com/images/I/small.jpg')
                )],
            ],
        ])];
    };
    $clock = static function () use (&$now): int {
        return $now;
    };
    $cache = $root . '/cache';
    $client = new AmazonCreatorsApiClient(productConfig(), $cache, $transport, $clock);
    $provider = new AmazonProductProvider($cache, $client, $clock);

    $first = $provider->getProduct('B00FIXTR01');
    productAssertSame(true, $first['available'], 'normal product was unavailable');
    productAssertSame('B00FIXTR01', $first['asin'], 'ASIN was not preserved');
    productAssertSame('Fixture title', $first['title'], 'title was not extracted');
    productAssertSame('https://m.media-amazon.com/images/I/large.jpg', $first['imageUrl'], 'image fallback did not use large');
    productAssertSame(AmazonCreatorsApiClient::MARKETPLACE, $first['marketplace'], 'product marketplace is wrong');
    productAssertSame(1, $tokenRequests, 'first request did not fetch a token');
    productAssertSame(1, $itemRequests, 'first request did not call GetItems once');
    productAssert(!isset($first['detailPageURL']), 'API detailPageURL leaked into product model');

    $hit = $provider->getProduct('B00FIXTR01');
    productAssertSame($first, $hit, 'fresh product cache hit changed the product');
    productAssertSame(1, $tokenRequests, 'fresh product cache hit fetched a token');
    productAssertSame(1, $itemRequests, 'fresh product cache hit called GetItems');

    $now = 1001;
    $cachedTokenClient = new AmazonCreatorsApiClient(productConfig(), $cache, $transport, $clock);
    $cachedTokenProvider = new AmazonProductProvider($cache, $cachedTokenClient, $clock);
    $missForDifferentAsin = $cachedTokenProvider->getProduct('B00FIXTR01');
    productAssertSame(true, $missForDifferentAsin['available'], 'cache reload failed');
    productAssertSame(1, $tokenRequests, 'cached token was not reused');
    productAssertSame(1, $itemRequests, 'cached product was not reused across provider instances');

    $now = 1000 + AmazonProductProvider::PRODUCT_CACHE_TTL;
    $expired = $provider->getProduct('B00FIXTR01');
    productAssertSame(true, $expired['available'], 'expired cache was not refreshed');
    productAssertSame(2, $tokenRequests, 'expired token was not refreshed');
    productAssertSame(2, $itemRequests, 'expired product cache was not refreshed');

    file_put_contents($cache . '/amazon/products/B00FIXTR01.json', '{broken', LOCK_EX);
    $now++;
    $corrupt = $provider->getProduct('B00FIXTR01');
    productAssertSame(true, $corrupt['available'], 'corrupt cache was not recovered');
    productAssertSame(3, $itemRequests, 'corrupt cache did not trigger GetItems');

    productAssertSame([], array_values(array_filter($requests, static function (array $request): bool {
        return $request[1] !== AmazonCreatorsApiClient::TOKEN_ENDPOINT && $request[1] !== AmazonCreatorsApiClient::API_ENDPOINT;
    })), 'client contacted an arbitrary host');

    $output = '';
    ob_start();
    $secretCheck = $provider->getProduct('B00FIXTURE1');
    $output = (string) ob_get_clean();
    productAssert(strpos($output, 'fixture-client-secret') === false && strpos($output, 'fixture-access-token') === false, 'credential or token was written to output');
    productAssert(strpos(json_encode($secretCheck), 'fixture-access-token') === false, 'token was returned in the product result');

    $fallbackTransport = static function (string $method, string $url, array $headers, string $body): array {
        if ($url === AmazonCreatorsApiClient::TOKEN_ENDPOINT) {
            return ['status' => 200, 'body' => '{"access_token":"fallback-token","expires_in":3600}'];
        }
        return ['status' => 200, 'body' => json_encode([
            'itemsResult' => ['items' => [productItem(
                'B00FIXTR02',
                'Small fallback',
                imageSizes('', '', 'https://m.media-amazon.com/images/I/small.jpg')
            )]],
        ])];
    };
    $fallbackCache = $root . '/fallback-cache';
    $fallbackClock = static function (): int {
        return 2000;
    };
    $fallback = (new AmazonProductProvider(
        $fallbackCache,
        new AmazonCreatorsApiClient(productConfig(), $fallbackCache, $fallbackTransport, $fallbackClock),
        $fallbackClock
    ))->getProduct('B00FIXTR02');
    productAssertSame('https://m.media-amazon.com/images/I/small.jpg', $fallback['imageUrl'], 'small image fallback failed');

    $failureCases = [
        'missing credentials' => [
            ['amazon' => ['client_id' => '', 'client_secret' => 'fixture-secret', 'partner_tag' => 'fixture-tag-22']],
            static function (string $method, string $url, array $headers, string $body): array {
                throw new RuntimeException('must not be called');
            },
        ],
        'token timeout' => [
            productConfig(),
            static function (string $method, string $url, array $headers, string $body): array {
                throw new RuntimeException('timeout');
            },
        ],
        'malformed token' => [
            productConfig(),
            static function (string $method, string $url, array $headers, string $body): array {
                return ['status' => 200, 'body' => '{broken'];
            },
        ],
        'invalid credentials response' => [
            productConfig(),
            static function (string $method, string $url, array $headers, string $body): array {
                return ['status' => 401, 'body' => '{}'];
            },
        ],
        'GetItems 4xx' => [
            productConfig(),
            static function (string $method, string $url, array $headers, string $body): array {
                return $url === AmazonCreatorsApiClient::TOKEN_ENDPOINT
                    ? ['status' => 200, 'body' => '{"access_token":"failure-token","expires_in":3600}']
                    : ['status' => 400, 'body' => '{}'];
            },
        ],
        'GetItems 5xx' => [
            productConfig(),
            static function (string $method, string $url, array $headers, string $body): array {
                return $url === AmazonCreatorsApiClient::TOKEN_ENDPOINT
                    ? ['status' => 200, 'body' => '{"access_token":"failure-token","expires_in":3600}']
                    : ['status' => 500, 'body' => '{}'];
            },
        ],
        'throttled' => [
            productConfig(),
            static function (string $method, string $url, array $headers, string $body): array {
                return $url === AmazonCreatorsApiClient::TOKEN_ENDPOINT
                    ? ['status' => 200, 'body' => '{"access_token":"failure-token","expires_in":3600}']
                    : ['status' => 429, 'body' => '{}'];
            },
        ],
        'malformed GetItems' => [
            productConfig(),
            static function (string $method, string $url, array $headers, string $body): array {
                return $url === AmazonCreatorsApiClient::TOKEN_ENDPOINT
                    ? ['status' => 200, 'body' => '{"access_token":"failure-token","expires_in":3600}']
                    : ['status' => 200, 'body' => '{broken'];
            },
        ],
        'item missing' => [
            productConfig(),
            static function (string $method, string $url, array $headers, string $body): array {
                return $url === AmazonCreatorsApiClient::TOKEN_ENDPOINT
                    ? ['status' => 200, 'body' => '{"access_token":"failure-token","expires_in":3600}']
                    : ['status' => 200, 'body' => '{"itemsResult":{"items":[]}}'];
            },
        ],
        'title missing' => [
            productConfig(),
            static function (string $method, string $url, array $headers, string $body): array {
                return $url === AmazonCreatorsApiClient::TOKEN_ENDPOINT
                    ? ['status' => 200, 'body' => '{"access_token":"failure-token","expires_in":3600}']
                    : ['status' => 200, 'body' => json_encode(['itemsResult' => ['items' => [productItem('B00FIXTR05', '', imageSizes('https://m.media-amazon.com/images/I/m.jpg'))]]])];
            },
        ],
        'image missing' => [
            productConfig(),
            static function (string $method, string $url, array $headers, string $body): array {
                return $url === AmazonCreatorsApiClient::TOKEN_ENDPOINT
                    ? ['status' => 200, 'body' => '{"access_token":"failure-token","expires_in":3600}']
                    : ['status' => 200, 'body' => json_encode(['itemsResult' => ['items' => [productItem('B00FIXTR05', 'No image')]]])];
            },
        ],
    ];
    foreach ($failureCases as $label => [$failureConfig, $failureTransport]) {
        $failureCache = $root . '/failures/' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $label);
        $failureClock = static function (): int {
            return 5000;
        };
        $failureProvider = new AmazonProductProvider(
            $failureCache,
            new AmazonCreatorsApiClient($failureConfig, $failureCache, $failureTransport, $failureClock),
            $failureClock
        );
        $failureResult = $failureProvider->getProduct('B00FIXTR05');
        productAssertSame(false, $failureResult['available'], $label . ' did not return product unavailable');
    }

    $sample = require dirname(__DIR__) . '/config.sample.php';
    productAssertSame(['client_id' => '', 'client_secret' => '', 'partner_tag' => ''], $sample['amazon'], 'config.sample.php has unexpected Amazon values');
    $writerInput = [
        'site_name' => 'Tomos Site',
        'site_description' => '',
        'site_url' => 'https://example.com',
        'base_path' => '',
        'public_base_path' => '',
        'language' => 'ja',
        'timezone' => 'Asia/Tokyo',
        'theme_name' => 'tomos-minimal',
    ];
    [$writtenConfig, $writerErrors] = ConfigWriter::build($writerInput, [
        'amazon' => [
            'client_id' => 'preserved-client',
            'client_secret' => 'preserved-secret',
            'partner_tag' => 'preserved-tag',
        ],
    ], dirname(__DIR__));
    productAssertSame([], $writerErrors, 'ConfigWriter rejected connection-point config');
    productAssertSame('preserved-client', $writtenConfig['amazon']['client_id'], 'ConfigWriter dropped Amazon client ID');
    productAssertSame('preserved-secret', $writtenConfig['amazon']['client_secret'], 'ConfigWriter dropped Amazon client secret');
    productAssertSame('preserved-tag', $writtenConfig['amazon']['partner_tag'], 'ConfigWriter dropped Amazon partner tag');

    echo "amazon_product_provider_check: OK\n";
} finally {
    removeProductTree($root);
}
