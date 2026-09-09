<?php

declare(strict_types=1);

namespace Tomos;

use Throwable;

final class ExternalUrlResolver
{
    private const AMAZON_OEMBED = 'https://read.amazon.com.au/kp/api/oembed';
    private const AMAZON_HOSTS = ['read.amazon.com.au'];
    private const APPLE_HOSTS = ['music.apple.com'];
    private const SPOTIFY_HOSTS = ['open.spotify.com'];

    private ExternalUrlCache $cache;
    private ExternalUrlHttpClient $http;
    private AmazonUrlResolver $amazon;

    public function __construct(string $cacheDir = '', ?callable $fixtureTransport = null, ?callable $dnsResolver = null)
    {
        $this->cache = new ExternalUrlCache($cacheDir);
        $this->http = new ExternalUrlHttpClient($fixtureTransport);
        $this->amazon = new AmazonUrlResolver($fixtureTransport, $dnsResolver);
    }

    public function resolve(string $line): ?string
    {
        $youtube = $this->youtubeEmbedHtml($line);
        if ($youtube !== null) {
            return $youtube;
        }
        $amazon = $this->amazonCard($line);
        if ($amazon !== null) {
            return $amazon;
        }
        $apple = $this->appleMusicCard($line);
        if ($apple !== null) {
            return $apple;
        }
        return $this->spotifyCard($line);
    }

    private function youtubeEmbedHtml(string $line): ?string
    {
        $videoId = null;
        if (preg_match('~\Ahttps://(?:www\.)?youtube\.com/watch\?v=([A-Za-z0-9_-]{11})(?:&[^#\s]*)?(?:#[^\s]*)?\z~', $line, $matches) === 1) {
            $videoId = $matches[1];
        } elseif (preg_match('~\Ahttps://youtu\.be/([A-Za-z0-9_-]{11})(?:\?[^#\s]*)?(?:#[^\s]*)?\z~', $line, $matches) === 1) {
            $videoId = $matches[1];
        } elseif (preg_match('~\Ahttps://(?:www\.)?youtube\.com/shorts/([A-Za-z0-9_-]{11})(?:\?[^#\s]*)?(?:#[^\s]*)?\z~', $line, $matches) === 1) {
            $videoId = $matches[1];
        }
        if ($videoId === null) {
            return null;
        }
        $embedUrl = 'https://www.youtube.com/embed/' . $videoId;
        return '<div class="youtube-embed"><iframe src="' . htmlspecialchars($embedUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" title="YouTube video player" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>';
    }

    private function amazonCard(string $sourceUrl): ?string
    {
        $identity = $this->amazon->resolve($sourceUrl);
        if ($identity === null) {
            return $this->amazon->isShortUrl($sourceUrl)
                ? '<div class="external-card amazon-card"><p class="external-card-provider">Amazon.co.jp</p><p><a href="' . $this->escapeUrl($sourceUrl) . '">商品を見る →</a></p></div>'
                : null;
        }
        $metadata = $this->cache->read('amazon-oembed', (string) $identity['resolvedUrl']);
        if ($metadata === null) {
            try {
                $requestUrl = self::AMAZON_OEMBED . '?url=' . rawurlencode((string) $identity['resolvedUrl']) . '&format=json';
                $response = $this->http->get($requestUrl, self::AMAZON_HOSTS);
                $status = (int) ($response['status'] ?? 0);
                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException('Amazon oEmbed request failed.');
                }
                $decoded = json_decode((string) ($response['body'] ?? ''), true, 8, JSON_THROW_ON_ERROR);
                $title = is_array($decoded) && is_string($decoded['title'] ?? null) ? trim($decoded['title']) : '';
                if ($title === '') {
                    throw new \RuntimeException('Amazon oEmbed title is missing.');
                }
                $metadata = ['title' => $title, 'author_name' => is_string($decoded['author_name'] ?? null) ? trim($decoded['author_name']) : ''];
                $this->cache->write('amazon-oembed', (string) $identity['resolvedUrl'], $metadata);
            } catch (Throwable $exception) {
                $metadata = null;
            }
        }
        $href = $this->escapeUrl($sourceUrl);
        if ($metadata === null) {
            return '<div class="external-card amazon-card"><p class="external-card-provider">Amazon.co.jp</p><p><a href="' . $href . '">商品を見る →</a></p></div>';
        }
        $html = '<div class="external-card amazon-card"><p class="external-card-title">' . $this->escape((string) $metadata['title']) . '</p>';
        if ((string) ($metadata['author_name'] ?? '') !== '') {
            $html .= '<p class="external-card-author">' . $this->escape((string) $metadata['author_name']) . '</p>';
        }
        return $html . '<p><a href="' . $href . '">Amazon.co.jpで見る →</a></p></div>';
    }

    private function appleMusicCard(string $sourceUrl): ?string
    {
        $parts = $this->parseHttpsHostUrl($sourceUrl, self::APPLE_HOSTS);
        if ($parts === null) {
            return null;
        }
        $rawPath = '';
        $rawQuery = '';
        if (preg_match('~\Ahttps://music\.apple\.com(?P<path>/[^?#]*)(?:\?(?P<query>[^#]*))?\z~', $sourceUrl, $rawUrlMatch) === 1) {
            $rawPath = $rawUrlMatch['path'];
            $rawQuery = $rawUrlMatch['query'] ?? '';
        }
        $match = [];
        if (preg_match('~\A/([a-z]{2})/(song|album|playlist)/([^/]+)/([^/]+)\z~', $rawPath, $match) !== 1) {
            return null;
        }
        $type = $match[2];
        $id = $match[4];
        if (($type === 'song' || $type === 'album') && preg_match('/\A\d+\z/', $id) !== 1) {
            return null;
        }
        if ($type === 'playlist' && preg_match('/\Apl\.[A-Za-z0-9]+\z/', $id) !== 1) {
            return null;
        }
        $valid = $this->cache->read('apple-source', $sourceUrl);
        if ($valid === null) {
            try {
                $response = $this->http->head($sourceUrl, self::APPLE_HOSTS);
                $status = (int) ($response['status'] ?? 0);
                if ($status < 200 || $status >= 400) {
                    throw new \RuntimeException('Apple Music source is unavailable.');
                }
                $valid = ['status' => $status];
                $this->cache->write('apple-source', $sourceUrl, $valid);
            } catch (Throwable $exception) {
                return $this->providerFallback('Apple Music', 'Apple Musicで開く →', $sourceUrl);
            }
        }
        $path = $rawPath;
        $query = $rawQuery !== '' ? '?' . $rawQuery : '';
        $embedUrl = 'https://embed.music.apple.com/' . ltrim($path, '/') . $query;
        return '<div class="external-embed apple-music-embed"><iframe src="' . $this->escape($embedUrl) . '" title="Apple Music" loading="lazy" allow="autoplay *; encrypted-media *;"></iframe><p class="external-link"><a href="' . $this->escapeUrl($sourceUrl) . '">Apple Musicで開く →</a></p></div>';
    }

    private function spotifyCard(string $sourceUrl): ?string
    {
        $parts = $this->parseHttpsHostUrl($sourceUrl, self::SPOTIFY_HOSTS);
        if ($parts === null) {
            return null;
        }
        $match = [];
        if (preg_match('~\A/(?:intl-[A-Za-z]{2}/)?(track|album|artist|playlist|episode)/([A-Za-z0-9]+)\z~', (string) ($parts['path'] ?? ''), $match) !== 1) {
            return null;
        }
        $metadata = $this->cache->read('spotify-oembed', $sourceUrl);
        if ($metadata === null) {
            try {
                $endpoint = 'https://open.spotify.com/oembed?url=' . rawurlencode($sourceUrl);
                $response = $this->http->get($endpoint, self::SPOTIFY_HOSTS);
                if ((int) ($response['status'] ?? 0) !== 200) {
                    throw new \RuntimeException('Spotify oEmbed request failed.');
                }
                $decoded = json_decode((string) ($response['body'] ?? ''), true, 8, JSON_THROW_ON_ERROR);
                $iframeUrl = is_array($decoded) && is_string($decoded['iframe_url'] ?? null) ? $decoded['iframe_url'] : '';
                $title = is_array($decoded) && is_string($decoded['title'] ?? null) ? trim($decoded['title']) : '';
                if (!is_array($decoded) || ($decoded['type'] ?? '') !== 'rich' || ($decoded['provider_name'] ?? '') !== 'Spotify' || ($decoded['provider_url'] ?? '') !== 'https://spotify.com' || $title === '' || !$this->validSpotifyIframe($iframeUrl, $match[1], $match[2])) {
                    throw new \RuntimeException('Spotify oEmbed response is not an allowed embed.');
                }
                $metadata = ['iframe_url' => $iframeUrl, 'title' => $title];
                $this->cache->write('spotify-oembed', $sourceUrl, $metadata);
            } catch (Throwable $exception) {
                return $this->providerFallback('Spotify', 'Spotifyで開く →', $sourceUrl);
            }
        }
        return '<div class="external-embed spotify-embed"><iframe src="' . $this->escape((string) $metadata['iframe_url']) . '" title="' . $this->escape((string) ($metadata['title'] ?? 'Spotify')) . '" loading="lazy" allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture" allowfullscreen></iframe><p class="external-link"><a href="' . $this->escapeUrl($sourceUrl) . '">Spotifyで開く →</a></p></div>';
    }

    private function validSpotifyIframe(string $url, string $type, string $id): bool
    {
        $parts = $this->parseHttpsHostUrl($url, self::SPOTIFY_HOSTS);
        if ($parts === null) {
            return false;
        }
        return preg_match('~\A/embed/' . preg_quote($type, '~') . '/' . preg_quote($id, '~') . '\z~', (string) ($parts['path'] ?? '')) === 1;
    }

    private function providerFallback(string $provider, string $label, string $sourceUrl): string
    {
        return '<div class="external-card provider-card"><p class="external-card-provider">' . $this->escape($provider) . '</p><p><a href="' . $this->escapeUrl($sourceUrl) . '">' . $this->escape($label) . '</a></p></div>';
    }

    private function parseHttpsHostUrl(string $url, array $allowedHosts): ?array
    {
        try {
            if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
                return null;
            }
            $parts = parse_url($url);
            if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
                return null;
            }
            if (!in_array(strtolower((string) $parts['host']), $allowedHosts, true)) {
                return null;
            }
            return $parts;
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function escapeUrl(string $url): string
    {
        return $this->escape(Security::safeHref($url));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
