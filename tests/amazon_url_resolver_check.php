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

function amazonAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function amazonAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function amazonAssertNull($actual, string $message): void
{
    amazonAssertSame(null, $actual, $message);
}

$resolver = new ExternalUrlResolver();
$directCases = [
    'dp path' => ['https://www.amazon.co.jp/dp/B08N5KWB90', 'B08N5KWB90'],
    'product name path' => ['https://www.amazon.co.jp/%E5%95%86%E5%93%81%E5%90%8D/dp/b08n5kwb90', 'B08N5KWB90'],
    'gp product path' => ['https://www.amazon.co.jp/gp/product/B08N5KWB90', 'B08N5KWB90'],
    'query path' => ['https://www.amazon.co.jp/dp/B08N5KWB90?ref_=abc', 'B08N5KWB90'],
    'affiliate query path' => ['https://amazon.co.jp/dp/B08N5KWB90?tag=xxxxx-22', 'B08N5KWB90'],
];

foreach ($directCases as $label => [$sourceUrl, $asin]) {
    $result = $resolver->resolveAmazon($sourceUrl);
    amazonAssert(is_array($result), $label . ' was not recognized');
    amazonAssertSame($sourceUrl, $result['sourceUrl'], $label . ' changed sourceUrl');
    amazonAssertSame($sourceUrl, $result['resolvedUrl'], $label . ' changed direct resolvedUrl');
    amazonAssertSame($asin, $result['asin'], $label . ' extracted the wrong ASIN');
    amazonAssertSame('amazon.co.jp', $result['marketplace'], $label . ' returned the wrong marketplace');
    amazonAssertSame(false, $result['resolutionRequired'], $label . ' incorrectly required redirect resolution');
}

$calls = [];
$transport = static function (string $url) use (&$calls): array {
    $calls[] = $url;
    if ($url === 'https://link.amazon/B001S989j') {
        return ['status' => 302, 'location' => 'https://amzlinks.in/B001S989j'];
    }
    if ($url === 'https://amzlinks.in/B001S989j') {
        return ['status' => 302, 'location' => 'https://www.amazon.co.jp/dp/4409030949/ref=cm_sw?tag=altlifeblog-22'];
    }
    throw new RuntimeException('unexpected fixture URL: ' . $url);
};
$publicDns = static function (string $host): array {
    return ['93.184.216.34'];
};
$shortResolver = new ExternalUrlResolver($transport, $publicDns);
$shortSource = 'https://link.amazon/B001S989j';
$shortResult = $shortResolver->resolveAmazon($shortSource);
amazonAssert(is_array($shortResult), 'link.amazon was not resolved');
amazonAssertSame($shortSource, $shortResult['sourceUrl'], 'link.amazon sourceUrl was rewritten');
amazonAssertSame('https://www.amazon.co.jp/dp/4409030949/ref=cm_sw?tag=altlifeblog-22', $shortResult['resolvedUrl'], 'link.amazon resolvedUrl is wrong');
amazonAssertSame('4409030949', $shortResult['asin'], 'link.amazon ASIN is wrong');
amazonAssertSame(true, $shortResult['resolutionRequired'], 'link.amazon did not record redirect resolution');
amazonAssertSame([
    'https://link.amazon/B001S989j',
    'https://amzlinks.in/B001S989j',
], $calls, 'link.amazon redirect sequence is wrong');

$amznTransport = static function (string $url): array {
    if ($url === 'https://amzn.asia/d/0248i9Xw') {
        return ['status' => 301, 'location' => 'https://www.amazon.co.jp/dp/4409030949?social_share=1'];
    }
    throw new RuntimeException('unexpected amzn.asia fixture URL: ' . $url);
};
$amznResult = (new ExternalUrlResolver($amznTransport, $publicDns))->resolveAmazon('https://amzn.asia/d/0248i9Xw');
amazonAssert(is_array($amznResult), 'amzn.asia was not resolved');
amazonAssertSame('https://amzn.asia/d/0248i9Xw', $amznResult['sourceUrl'], 'amzn.asia sourceUrl was rewritten');
amazonAssertSame('4409030949', $amznResult['asin'], 'amzn.asia ASIN is wrong');

$rejectCases = [
    'evil suffix host' => 'https://amazon.co.jp.evil.example/dp/B08N5KWB90',
    'evil prefix host' => 'https://evilamazon.co.jp/dp/B08N5KWB90',
    'amazon only in query' => 'https://evil.example/?url=https://amazon.co.jp/dp/B08N5KWB90',
    'invalid ASIN' => 'https://www.amazon.co.jp/dp/short',
    'http scheme' => 'http://www.amazon.co.jp/dp/B08N5KWB90',
    'localhost' => 'https://localhost/dp/B08N5KWB90',
];
foreach ($rejectCases as $label => $sourceUrl) {
    amazonAssertNull($resolver->resolveAmazon($sourceUrl), $label . ' was incorrectly accepted');
}

$nonAmazonRedirect = new ExternalUrlResolver(static function (string $url): array {
    return ['status' => 302, 'location' => 'https://evil.example/dp/B08N5KWB90'];
}, $publicDns);
amazonAssertNull($nonAmazonRedirect->resolveAmazon('https://link.amazon/example'), 'non-Amazon redirect was accepted');

$loopCalls = 0;
$loopResolver = new ExternalUrlResolver(static function (string $url) use (&$loopCalls): array {
    $loopCalls++;
    return ['status' => 302, 'location' => 'https://link.amazon/example'];
}, $publicDns);
amazonAssertNull($loopResolver->resolveAmazon('https://link.amazon/example'), 'redirect loop was accepted');
amazonAssertSame(4, $loopCalls, 'redirect limit was not enforced');

$privateResolver = new ExternalUrlResolver(static function (string $url): array {
    return ['status' => 302, 'location' => 'https://www.amazon.co.jp/dp/B08N5KWB90'];
}, static function (string $host): array {
    return $host === 'www.amazon.co.jp' ? ['127.0.0.1'] : ['93.184.216.34'];
});
amazonAssertNull($privateResolver->resolveAmazon('https://link.amazon/example'), 'private redirect target was accepted');

$schemeResolver = new ExternalUrlResolver(static function (string $url): array {
    return ['status' => 302, 'location' => 'file:///etc/passwd'];
}, $publicDns);
amazonAssertNull($schemeResolver->resolveAmazon('https://link.amazon/example'), 'unsupported redirect scheme was accepted');

$errorResolver = new ExternalUrlResolver(static function (string $url): array {
    throw new RuntimeException('network timeout');
}, $publicDns);
amazonAssertNull($errorResolver->resolveAmazon('https://link.amazon/example'), 'network error escaped as an exception');

$parser = new MarkdownParser();
$markdownCases = [
    'independent line' => 'https://www.amazon.co.jp/dp/B08N5KWB90',
    'markdown link' => '[商品](https://www.amazon.co.jp/dp/B08N5KWB90)',
    'inline URL' => '商品はこちら https://www.amazon.co.jp/dp/B08N5KWB90',
    'code block' => "```\nhttps://www.amazon.co.jp/dp/B08N5KWB90\n```",
];
foreach ($markdownCases as $label => $markdown) {
    $html = $parser->toHtml($markdown);
    amazonAssert(strpos($html, 'amazon-card') === false, $label . ' unexpectedly generated a product card');
}
amazonAssert(strpos($parser->toHtml($markdownCases['independent line']), '<a href="https://www.amazon.co.jp/dp/B08N5KWB90">') !== false, 'independent Amazon URL changed visible Markdown behavior');
amazonAssert(strpos($parser->toHtml($markdownCases['code block']), '<pre><code>https://www.amazon.co.jp/dp/B08N5KWB90</code></pre>') !== false, 'code block Amazon URL was processed');

echo "amazon_url_resolver_check: OK\n";
