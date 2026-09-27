<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/SocialPublishResult.php';
require_once dirname(__DIR__) . '/core/SocialProvider.php';
require_once dirname(__DIR__) . '/core/BlueskyProvider.php';

use Tomos\BlueskyProvider;

$reflection = new ReflectionClass(BlueskyProvider::class);
$provider = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('hashtagFacets');
if (PHP_VERSION_ID < 80100) {
    $method->setAccessible(true);
}

/** @param array<int,array<string,mixed>> $facets */
function assertTagFacet(array $facets, int $index, string $text, string $tag, string $source): void
{
    if (!isset($facets[$index])) {
        throw new RuntimeException("missing facet {$index} for {$source}");
    }

    $facet = $facets[$index];
    $start = (int) ($facet['index']['byteStart'] ?? -1);
    $end = (int) ($facet['index']['byteEnd'] ?? -1);
    $feature = $facet['features'][0] ?? [];

    if (substr($source, $start, $end - $start) !== $text) {
        throw new RuntimeException("facet {$index} byte slice mismatch");
    }
    if (($feature['$type'] ?? '') !== 'app.bsky.richtext.facet#tag') {
        throw new RuntimeException("facet {$index} type mismatch");
    }
    if (($feature['tag'] ?? '') !== $tag) {
        throw new RuntimeException("facet {$index} tag mismatch");
    }
}

$source = "Tomosを更新しました。 #Tomos #個人サイト";
$facets = $method->invoke($provider, $source);
if (!is_array($facets) || count($facets) !== 2) {
    throw new RuntimeException('ASCII and Japanese hashtags must both produce facets');
}
assertTagFacet($facets, 0, '#Tomos', 'Tomos', $source);
assertTagFacet($facets, 1, '#個人サイト', '個人サイト', $source);

$source = "日本語の前置き\n#更新情報。";
$facets = $method->invoke($provider, $source);
if (!is_array($facets) || count($facets) !== 1) {
    throw new RuntimeException('Japanese text before a hashtag must preserve UTF-8 byte offsets');
}
assertTagFacet($facets, 0, '#更新情報', '更新情報', $source);

$source = "(#Tomos) #2bad example#inline";
$facets = $method->invoke($provider, $source);
if (!is_array($facets) || count($facets) !== 1) {
    throw new RuntimeException('parenthesized tags should work while numeric-leading and inline hashes stay plain text');
}
assertTagFacet($facets, 0, '#Tomos', 'Tomos', $source);

echo "bluesky_hashtag_facet_check: passed\n";
