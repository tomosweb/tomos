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

function appleCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$calls = [];
$transport = static function (string $url) use (&$calls): array {
    $calls[] = $url;
    return [
        'status' => strpos($url, '/does-not-exist/') !== false ? 404 : 200,
        'headers' => ['content-type' => 'text/html'],
        'body' => '',
    ];
};
$resolver = new ExternalUrlResolver('', $transport);

$cases = [
    'https://music.apple.com/jp/song/イマジン/1440847811' => 'https://embed.music.apple.com/jp/song/イマジン/1440847811',
    'https://music.apple.com/us/song/imagine/1440847811' => 'https://embed.music.apple.com/us/song/imagine/1440847811',
    'https://music.apple.com/jp/album/リンゴ/1804514629?i=1804515140&at=abc&ct=campaign' => 'https://embed.music.apple.com/jp/album/リンゴ/1804514629?i=1804515140&at=abc&ct=campaign',
    'https://music.apple.com/gb/album/fearless/1440924803' => 'https://embed.music.apple.com/gb/album/fearless/1440924803',
    'https://music.apple.com/jp/playlist/top-100-japan/pl.043a2c9876114d95a4659988497567be' => 'https://embed.music.apple.com/jp/playlist/top-100-japan/pl.043a2c9876114d95a4659988497567be',
    'https://music.apple.com/jp/playlist/example/pl.ABC123' => 'https://embed.music.apple.com/jp/playlist/example/pl.ABC123',
];
foreach ($cases as $source => $embed) {
    $html = $resolver->resolve($source);
    appleCheck(is_string($html), 'supported Apple Music URL did not render');
    appleCheck(strpos($html, 'src="' . htmlspecialchars($embed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"') !== false, 'Apple embed URL changed path or query');
    appleCheck(strpos($html, 'href="' . htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"') !== false, 'Apple sourceUrl was not preserved');
}

appleCheck($resolver->resolve('https://music.apple.com/jp/song/does-not-exist/9999999999') !== null, 'invalid Apple source did not produce safe fallback');
appleCheck(strpos((string) $resolver->resolve('https://music.apple.com/jp/song/does-not-exist/9999999999'), 'external-embed') === false, 'invalid Apple source produced an embed');
foreach ([
    'https://music.apple.com/jp/artist/example/123',
    'https://music.apple.com/jp/station/apple-music-1/ra.123',
    'https://music.apple.com/jp/search?term=test',
    'https://music.apple.com/jp/',
    'https://music.apple.com/song/1440847811',
    'https://music.apple.com.evil.example/jp/song/example/123',
] as $unsupported) {
    appleCheck($resolver->resolve($unsupported) === null, 'unsupported Apple URL was accepted: ' . $unsupported);
}

echo "apple_music_check: OK\n";
