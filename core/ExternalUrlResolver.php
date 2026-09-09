<?php

declare(strict_types=1);

namespace Tomos;

use Throwable;

final class ExternalUrlResolver
{
    private const AMAZON_OEMBED = 'https://read.amazon.com.au/kp/api/oembed';
    private const AMAZON_HOSTS = ['read.amazon.com.au'];

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
        return null;
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
            return null;
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
