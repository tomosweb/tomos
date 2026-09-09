<?php

declare(strict_types=1);

namespace Tomos;

final class ExternalUrlResolver
{
    private AmazonUrlResolver $amazonUrlResolver;

    public function __construct(?callable $amazonFixtureTransport = null, ?callable $amazonDnsResolver = null)
    {
        $this->amazonUrlResolver = new AmazonUrlResolver($amazonFixtureTransport, $amazonDnsResolver);
    }

    public function resolve(string $line): ?string
    {
        return $this->youtubeEmbedHtml($line);
    }

    public function resolveAmazon(string $sourceUrl): ?array
    {
        return $this->amazonUrlResolver->resolve($sourceUrl);
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
}
