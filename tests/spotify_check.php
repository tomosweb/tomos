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

function spotifyCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$calls = [];
$transport = static function (string $url) use (&$calls): array {
    $calls[] = $url;
    if (strpos($url, '/oembed?url=') === false) {
        return ['status' => 500, 'body' => ''];
    }
    if (strpos($url, 'bad') !== false) {
        return ['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => '{'];
    }
    $type = 'track';
    foreach (['album', 'artist', 'playlist', 'episode'] as $candidate) {
        if (strpos($url, '%2F' . $candidate . '%2F') !== false) {
            $type = $candidate;
        }
    }
    $id = 'abc123';
    return ['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode([
        'type' => 'rich',
        'provider_name' => 'Spotify',
        'provider_url' => 'https://spotify.com',
        'title' => 'Test ' . $type,
        'iframe_url' => 'https://open.spotify.com/embed/' . $type . '/' . $id . '?utm_source=oembed',
        'html' => '<iframe src="https://evil.example/should-not-be-used"></iframe>',
    ])];
};
$resolver = new ExternalUrlResolver('', $transport);
foreach (['track', 'album', 'artist', 'playlist', 'episode'] as $type) {
    $source = 'https://open.spotify.com/' . $type . '/abc123';
    $html = $resolver->resolve($source);
    spotifyCheck(is_string($html) && strpos($html, 'https://open.spotify.com/embed/' . $type . '/abc123?utm_source=oembed') !== false, $type . ' was not embedded');
    spotifyCheck(strpos($html, 'href="' . $source . '"') !== false, $type . ' sourceUrl was not preserved');
    spotifyCheck(strpos($html, 'evil.example') === false, 'untrusted Spotify html was emitted');
}

$bad = $resolver->resolve('https://open.spotify.com/track/bad');
spotifyCheck(is_string($bad) && strpos($bad, 'Spotifyで開く') !== false && strpos($bad, 'external-embed') === false, 'malformed Spotify response did not fallback');
spotifyCheck($resolver->resolve('https://open.spotify.com/search/example') === null, 'Spotify search URL was accepted');
spotifyCheck($resolver->resolve('https://open.spotify.com/track/invalid?x=1') !== null, 'Spotify query URL did not safely resolve or fallback');

$invalidHost = new ExternalUrlResolver('', static fn (string $url): array => [
    'status' => 200,
    'body' => json_encode(['type' => 'rich', 'provider_name' => 'Spotify', 'provider_url' => 'https://spotify.com', 'title' => 'Bad', 'iframe_url' => 'https://evil.example/embed/track/abc123']),
]);
$invalid = $invalidHost->resolve('https://open.spotify.com/track/abc123');
spotifyCheck(is_string($invalid) && strpos($invalid, 'Spotifyで開く') !== false && strpos($invalid, 'evil.example') === false, 'invalid Spotify iframe host was emitted');

echo "spotify_check: OK\n";
