<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'Tomos\\') !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Tomos\ExternalUrlResolver;
use Tomos\FrontMatterParser;
use Tomos\MarkdownParser;

function articleCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$fixture = (string) file_get_contents(__DIR__ . '/fixtures/external-links-acceptance.md');
$frontMatter = new FrontMatterParser();
$parsed = $frontMatter->parse($fixture);
$metadata = $frontMatter->buildPageMetadata($parsed['metadata'], $parsed['body'], 'test/リンクテスト.md');
articleCheck($metadata['description'] === 'これはリンクテストのページです。', 'auto description includes later Markdown blocks');
articleCheck(strpos($metadata['description'], 'youtube.com') === false, 'auto description includes YouTube URL');
articleCheck(strpos($metadata['description'], 'amazon.co.jp') === false, 'auto description includes Amazon URL');

$transport = static function (string $url): array {
    if (strpos($url, 'read.amazon.com.au/kp/api/oembed') !== false) {
        return ['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode(['title' => 'ゲリラ形而上学', 'author_name' => 'グレアム・ハーマン'])];
    }
    if ($url === 'https://link.amazon/B0gc18lgC') {
        return ['status' => 404, 'body' => ''];
    }
    if (strpos($url, '/oembed?url=') !== false) {
        $source = rawurldecode((string) (parse_url($url, PHP_URL_QUERY) ?? ''));
        $source = preg_replace('/\Aurl=/', '', $source) ?? $source;
        $sourcePath = (string) (parse_url($source, PHP_URL_PATH) ?? '');
        $sourcePath = preg_replace('~\A/intl-[A-Za-z]{2}~', '', $sourcePath) ?? $sourcePath;
        if (preg_match('~\A/(track|album|playlist)/([A-Za-z0-9]+)~', $sourcePath, $match) !== 1) {
            return ['status' => 200, 'body' => '{'];
        }
        return ['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode([
            'type' => 'rich',
            'provider_name' => 'Spotify',
            'provider_url' => 'https://spotify.com',
            'title' => 'Spotify ' . $match[1],
            'iframe_url' => 'https://open.spotify.com/embed/' . $match[1] . '/' . $match[2],
        ])];
    }
    return ['status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => ''];
};
$resolver = new ExternalUrlResolver('', $transport, static fn (string $host): array => ['93.184.216.34']);
$parser = new MarkdownParser(false, '', '', $resolver);
$html = $parser->toHtml((string) $parsed['body']);

articleCheck(substr_count($html, 'class="youtube-embed"') === 1, 'article fixture YouTube count mismatch');
articleCheck(substr_count($html, 'class="external-card amazon-card"') === 3, 'article fixture Amazon card count mismatch');
articleCheck(substr_count($html, 'href="https://www.amazon.co.jp/') === 2, 'article fixture regular Amazon sourceUrl count mismatch');
articleCheck(substr_count($html, 'href="https://link.amazon/B0gc18lgC"') === 1, 'article fixture short Amazon sourceUrl missing');
articleCheck(substr_count($html, 'apple-music-embed') === 3, 'article fixture Apple Music count mismatch');
articleCheck(substr_count($html, 'height="150"') === 1, 'article fixture Apple song height mismatch');
articleCheck(substr_count($html, 'height="450"') === 2, 'article fixture Apple album/playlist height mismatch');
articleCheck(substr_count($html, 'spotify-embed') === 3, 'article fixture Spotify count mismatch');
articleCheck(substr_count($html, '<hr>') === 1, 'article fixture horizontal rule changed');
articleCheck(strpos($html, '<ul><li>') !== false, 'article fixture list changed');
foreach ([
    'href="https://link.amazon/B0gc18lgC"',
    'href="https://music.apple.com/jp/album/',
    'href="https://music.apple.com/jp/playlist/',
    'href="https://open.spotify.com/intl-ja/track/',
    'href="https://open.spotify.com/intl-ja/album/',
    'href="https://open.spotify.com/playlist/',
] as $marker) {
    articleCheck(strpos($html, $marker) !== false, 'sourceUrl marker missing: ' . $marker);
}

echo "external_links_article_acceptance_check: OK\n";
