<?php

declare(strict_types=1);

namespace Tomos;

final class AmazonProductProvider
{
    public const PRODUCT_CACHE_TTL = 86400;

    private string $cacheDir;
    private AmazonCreatorsApiClient $client;
    private $clock;

    public function __construct(
        string $cacheDir,
        AmazonCreatorsApiClient $client,
        ?callable $clock = null
    ) {
        $this->cacheDir = rtrim($cacheDir, DIRECTORY_SEPARATOR);
        $this->client = $client;
        $this->clock = $clock ?? static function (): int {
            return time();
        };
    }

    /**
     * Return card-ready product data only. No click URL is derived here.
     */
    public function getProduct(string $asin): array
    {
        $asin = strtoupper($asin);
        $unavailable = [
            'available' => false,
            'asin' => $asin,
            'title' => '',
            'imageUrl' => '',
            'marketplace' => AmazonCreatorsApiClient::MARKETPLACE,
        ];
        if (preg_match('/\A[A-Z0-9]{10}\z/', $asin) !== 1) {
            return $unavailable;
        }

        try {
            $now = $this->now();
            $cached = $this->readCache($asin, $now);
            if ($cached !== null) {
                return $cached;
            }

            $item = $this->client->getItem($asin);
            $product = $this->normalizeItem($asin, $item, $now);
            if (!$product['available']) {
                return $unavailable;
            }
            $this->writeCache($product);
            return $product;
        } catch (\Throwable $exception) {
            return $unavailable;
        }
    }

    private function normalizeItem(string $asin, ?array $item, int $now): array
    {
        if ($item === null || strtoupper((string) ($item['asin'] ?? '')) !== $asin) {
            return ['available' => false];
        }
        $title = $item['itemInfo']['title']['displayValue'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            return ['available' => false];
        }

        $imageUrl = '';
        foreach (['medium', 'large', 'small'] as $size) {
            $candidate = $item['images']['primary'][$size]['url'] ?? null;
            if (is_string($candidate) && $this->isAllowedImageUrl($candidate)) {
                $imageUrl = $candidate;
                break;
            }
        }
        if ($imageUrl === '') {
            return ['available' => false];
        }

        return [
            'available' => true,
            'asin' => $asin,
            'title' => $title,
            'imageUrl' => $imageUrl,
            'marketplace' => AmazonCreatorsApiClient::MARKETPLACE,
            'fetchedAt' => $now,
            'expiresAt' => $now + self::PRODUCT_CACHE_TTL,
        ];
    }

    private function isAllowedImageUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'm.media-amazon.com'
            && !isset($parts['user'], $parts['pass'], $parts['fragment']);
    }

    private function readCache(string $asin, int $now): ?array
    {
        $raw = @file_get_contents($this->cachePath($asin));
        if ($raw === false) {
            return null;
        }
        $cached = json_decode($raw, true);
        if (!is_array($cached)
            || ($cached['available'] ?? false) !== true
            || strtoupper((string) ($cached['asin'] ?? '')) !== $asin
            || !is_string($cached['title'] ?? null)
            || $cached['title'] === ''
            || !is_string($cached['imageUrl'] ?? null)
            || !$this->isAllowedImageUrl($cached['imageUrl'])
            || ($cached['marketplace'] ?? '') !== AmazonCreatorsApiClient::MARKETPLACE
            || !is_numeric($cached['expiresAt'] ?? null)
            || (int) $cached['expiresAt'] <= $now
        ) {
            return null;
        }
        return $cached;
    }

    private function writeCache(array $product): void
    {
        $directory = dirname($this->cachePath((string) $product['asin']));
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }
        $json = json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $path = $this->cachePath((string) $product['asin']);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            return;
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    private function cachePath(string $asin): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . 'amazon' . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . $asin . '.json';
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}
