<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyProvider implements SocialProvider
{
    private const MAX_CARD_HTML_BYTES = 262144;
    private const MAX_THUMB_BYTES = 2000000;
    private const THUMB_TARGET_BYTES = 1800000;
    private const THUMB_MAX_EDGE = 1600;

    private BlueskyOAuthSessionClient $session;

    public function __construct(BlueskyOAuthSessionClient $session)
    {
        $this->session = $session;
    }

    public function name(): string
    {
        return 'bluesky';
    }

    public function publish(string $text, string $articleUrl, array $context = []): SocialPublishResult
    {
        if (!$this->session->isConnected()) {
            return SocialPublishResult::failed('bluesky', 'not_connected', 'Blueskyが接続されていません。');
        }

        if (!$this->fitsPostLimit($text)) {
            return SocialPublishResult::failed(
                'bluesky',
                'text_too_long',
                'Bluesky投稿文が文字数上限を超えています。'
            );
        }

        $account = $this->session->account();
        $did = is_array($account) ? (string) ($account['did'] ?? '') : '';
        if ($did === '') {
            return SocialPublishResult::failed('bluesky', 'authentication_failed', 'Blueskyの接続情報を確認できません。');
        }

        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if ($articleUrl !== '') {
            $pageMetadata = is_array($context['page_metadata'] ?? null)
                ? $context['page_metadata']
                : (is_array($context['metadata'] ?? null) ? $context['metadata'] : []);
            $external = [
                'uri' => $articleUrl,
                'title' => trim((string) ($pageMetadata['title'] ?? '')) ?: 'Tomos',
                'description' => trim((string) ($pageMetadata['description'] ?? '')),
            ];

            $thumb = $this->cardThumbnail(
                $articleUrl,
                $account,
                trim((string) ($context['social_image_url'] ?? '')),
                trim((string) ($context['social_image_path'] ?? ''))
            );
            if ($thumb !== null) {
                $external['thumb'] = $thumb;
            }

            $record['embed'] = [
                '$type' => 'app.bsky.embed.external',
                'external' => $external,
            ];
        }

        try {
            $response = $this->session->postJson('/xrpc/com.atproto.repo.createRecord', [
                'repo' => $did,
                'collection' => 'app.bsky.feed.post',
                'record' => $record,
            ]);
        } catch (\Throwable $exception) {
            return SocialPublishResult::failed(
                'bluesky',
                'network_error',
                'Blueskyへの投稿通信を完了できませんでした。'
            );
        }

        if ($response->status === 401 || $response->status === 403) {
            return SocialPublishResult::failed(
                'bluesky',
                'authentication_failed',
                'Blueskyの認証を確認してください。'
            );
        }
        if ($response->status < 200 || $response->status >= 300) {
            return SocialPublishResult::failed(
                'bluesky',
                'provider_error',
                'Blueskyへの投稿に失敗しました。'
            );
        }

        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            return SocialPublishResult::failed(
                'bluesky',
                'provider_error',
                'Blueskyから投稿結果を確認できませんでした。'
            );
        }
        $uri = (string) ($decoded['uri'] ?? '');
        $cid = (string) ($decoded['cid'] ?? '');
        if ($uri === '' || $cid === '') {
            return SocialPublishResult::failed(
                'bluesky',
                'provider_error',
                'Blueskyから投稿IDを確認できませんでした。'
            );
        }

        return SocialPublishResult::success(
            'bluesky',
            'Blueskyにも投稿しました。',
            $uri,
            $cid
        );
    }

    private function cardThumbnail(
        string $articleUrl,
        array $account,
        string $explicitImageUrl = '',
        string $explicitImagePath = ''
    ): ?array {
        if (!$this->hasBlobPermission($account)) {
            return null;
        }

        try {
            $imageBody = '';
            $mimeType = '';

            if ($explicitImagePath !== '' && is_file($explicitImagePath)) {
                $mimeType = $this->mimeTypeFromFile($explicitImagePath);
                if ($mimeType !== '') {
                    [$imageBody, $mimeType] = $this->prepareLocalThumbnailForBluesky(
                        $explicitImagePath,
                        $mimeType
                    );
                }
            }

            if ($imageBody === '' || $mimeType === '') {
                $imageUrl = $this->validHttpsImageUrl($explicitImageUrl);
                if ($imageUrl === '') {
                    $page = $this->session->getPublic($articleUrl, self::MAX_CARD_HTML_BYTES);
                    if ($page->status < 200 || $page->status >= 300 || $page->body === '') {
                        return null;
                    }

                    $imageUrl = $this->ogImageUrl($page->body);
                    if ($imageUrl === '') {
                        return null;
                    }
                }

                $image = $this->session->getPublic($imageUrl, self::MAX_THUMB_BYTES);
                if ($image->status < 200 || $image->status >= 300 || $image->body === '') {
                    return null;
                }

                $imageBody = $image->body;
                $mimeType = $this->imageMimeType($image);
                if ($imageBody === '' || $mimeType === '') {
                    return null;
                }

                [$imageBody, $mimeType] = $this->prepareThumbnailForBluesky($imageBody, $mimeType);
            }

            if ($imageBody === '' || $mimeType === '' || strlen($imageBody) > self::MAX_THUMB_BYTES) {
                return null;
            }

            $upload = $this->session->postBinary(
                '/xrpc/com.atproto.repo.uploadBlob',
                $imageBody,
                $mimeType
            );
            if ($upload->status < 200 || $upload->status >= 300) {
                return null;
            }

            $decoded = json_decode($upload->body, true);
            $blob = is_array($decoded) && is_array($decoded['blob'] ?? null)
                ? $decoded['blob']
                : null;

            return is_array($blob) ? $blob : null;
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function mimeTypeFromFile(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'jpg' || $extension === 'jpeg') {
            return 'image/jpeg';
        }
        if ($extension === 'png') {
            return 'image/png';
        }
        if ($extension === 'webp') {
            return 'image/webp';
        }

        if (function_exists('finfo_open')) {
            $handle = finfo_open(FILEINFO_MIME_TYPE);
            if ($handle !== false) {
                $detected = finfo_file($handle, $path);
                finfo_close($handle);
                if (is_string($detected) && in_array($detected, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    return $detected;
                }
            }
        }

        return '';
    }

    /** @return array{0:string,1:string} */
    private function prepareLocalThumbnailForBluesky(string $path, string $mimeType): array
    {
        $orientation = $mimeType === 'image/jpeg' ? $this->jpegOrientation($path) : 1;
        $size = @filesize($path);
        $needsOrientation = $orientation >= 2 && $orientation <= 8;

        if (is_int($size) && $size > 0 && $size <= self::THUMB_TARGET_BYTES && !$needsOrientation) {
            $body = @file_get_contents($path);
            return is_string($body) && $body !== '' ? [$body, $mimeType] : ['', ''];
        }

        if (
            !function_exists('imagecreatetruecolor') ||
            !function_exists('imagecopyresampled') ||
            !function_exists('imagejpeg') ||
            !function_exists('getimagesize')
        ) {
            return ['', ''];
        }

        if (!$this->hasEnoughMemoryForBlueskyFile($path, $needsOrientation)) {
            return ['', ''];
        }

        gc_collect_cycles();

        $source = $this->createImageFromFile($path, $mimeType);
        if (!$this->isGdImage($source)) {
            return ['', ''];
        }

        try {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            if ($sourceWidth <= 0 || $sourceHeight <= 0) {
                return ['', ''];
            }

            $longEdge = max($sourceWidth, $sourceHeight);
            $initialScale = $longEdge > self::THUMB_MAX_EDGE
                ? self::THUMB_MAX_EDGE / $longEdge
                : 1.0;

            $qualitySteps = [82, 76, 70, 64, 58, 52, 46];
            $dimensionSteps = [1.0, 0.88, 0.76, 0.64, 0.52];

            foreach ($dimensionSteps as $dimensionScale) {
                $scale = $initialScale * $dimensionScale;
                $targetWidth = max(1, (int) round($sourceWidth * $scale));
                $targetHeight = max(1, (int) round($sourceHeight * $scale));

                $canvas = @imagecreatetruecolor($targetWidth, $targetHeight);
                if (!$this->isGdImage($canvas)) {
                    continue;
                }

                $white = @imagecolorallocate($canvas, 255, 255, 255);
                if ($white !== false) {
                    @imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
                }

                @imagealphablending($canvas, true);
                if (!@imagecopyresampled(
                    $canvas,
                    $source,
                    0,
                    0,
                    0,
                    0,
                    $targetWidth,
                    $targetHeight,
                    $sourceWidth,
                    $sourceHeight
                )) {
                    $this->releaseGdImage($canvas);
                    continue;
                }

                if ($needsOrientation) {
                    $oriented = $this->orientSmallCanvas($canvas, $orientation);
                    if (!$this->isGdImage($oriented)) {
                        $this->releaseGdImage($canvas);
                        continue;
                    }
                    if ($oriented !== $canvas) {
                        $this->releaseGdImage($canvas);
                        $canvas = $oriented;
                    }
                }

                foreach ($qualitySteps as $quality) {
                    ob_start();
                    $saved = @imagejpeg($canvas, null, $quality);
                    $candidate = ob_get_clean();
                    if (!$saved || !is_string($candidate) || $candidate === '') {
                        continue;
                    }
                    if (strlen($candidate) <= self::THUMB_TARGET_BYTES) {
                        $this->releaseGdImage($canvas);
                        return [$candidate, 'image/jpeg'];
                    }
                }

                $this->releaseGdImage($canvas);
            }
        } finally {
            $this->releaseGdImage($source);
        }

        return ['', ''];
    }

    private function createImageFromFile(string $path, string $mimeType)
    {
        if ($mimeType === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
            return @imagecreatefromjpeg($path);
        }
        if ($mimeType === 'image/png' && function_exists('imagecreatefrompng')) {
            return @imagecreatefrompng($path);
        }
        if ($mimeType === 'image/webp' && function_exists('imagecreatefromwebp')) {
            return @imagecreatefromwebp($path);
        }

        return false;
    }

    private function jpegOrientation(string $path): int
    {
        if (!extension_loaded('exif') || !function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path);
        if (!is_array($exif)) {
            return 1;
        }

        if (isset($exif['Orientation'])) {
            return (int) $exif['Orientation'];
        }
        if (isset($exif['IFD0']) && is_array($exif['IFD0']) && isset($exif['IFD0']['Orientation'])) {
            return (int) $exif['IFD0']['Orientation'];
        }

        return 1;
    }

    private function jpegOrientationFromBytes(string $body): int
    {
        if (!function_exists('tempnam')) {
            return 1;
        }

        $path = @tempnam(sys_get_temp_dir(), 'tomos-bluesky-');
        if (!is_string($path) || $path === '') {
            return 1;
        }

        try {
            if (@file_put_contents($path, $body) === false) {
                return 1;
            }
            return $this->jpegOrientation($path);
        } finally {
            @unlink($path);
        }
    }

    private function orientSmallCanvas($image, int $orientation)
    {
        if (!$this->isGdImage($image) || $orientation < 2 || $orientation > 8) {
            return $image;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            if (!function_exists('imageflip')) {
                return false;
            }
            $flipMode = $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL;
            if (!@imageflip($image, $flipMode)) {
                return false;
            }
        }

        $degrees = 0;
        if ($orientation === 3) {
            $degrees = 180;
        } elseif (in_array($orientation, [5, 8], true)) {
            $degrees = 90;
        } elseif (in_array($orientation, [6, 7], true)) {
            $degrees = -90;
        }

        if ($degrees === 0) {
            return $image;
        }
        if (!function_exists('imagerotate')) {
            return false;
        }

        $rotated = @imagerotate($image, $degrees, 0);
        return $this->isGdImage($rotated) ? $rotated : false;
    }

    /** @return array{0:string,1:string} */
    private function prepareThumbnailForBluesky(string $body, string $mimeType): array
    {
        $isJpeg = strtolower($mimeType) === 'image/jpeg';
        $orientation = $isJpeg ? $this->jpegOrientationFromBytes($body) : 1;
        $needsOrientation = $orientation >= 2 && $orientation <= 8;

        if (!$needsOrientation && strlen($body) <= self::THUMB_TARGET_BYTES) {
            return [$body, $mimeType];
        }

        if (
            !function_exists('imagecreatefromstring') ||
            !function_exists('imagecreatetruecolor') ||
            !function_exists('imagecopyresampled') ||
            !function_exists('imagejpeg') ||
            !function_exists('getimagesizefromstring')
        ) {
            return ['', ''];
        }

        if (!$this->hasEnoughMemoryForBlueskyBytes($body, $needsOrientation)) {
            return ['', ''];
        }

        $source = @imagecreatefromstring($body);
        if (!$this->isGdImage($source)) {
            return ['', ''];
        }

        $body = '';

        try {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            if ($sourceWidth <= 0 || $sourceHeight <= 0) {
                return ['', ''];
            }

            $longEdge = max($sourceWidth, $sourceHeight);
            $initialScale = $longEdge > self::THUMB_MAX_EDGE
                ? self::THUMB_MAX_EDGE / $longEdge
                : 1.0;

            $qualitySteps = [82, 74, 66, 58, 50];
            $dimensionSteps = [1.0, 0.85, 0.70, 0.55];

            foreach ($dimensionSteps as $dimensionScale) {
                $scale = $initialScale * $dimensionScale;
                $targetWidth = max(1, (int) round($sourceWidth * $scale));
                $targetHeight = max(1, (int) round($sourceHeight * $scale));

                $canvas = @imagecreatetruecolor($targetWidth, $targetHeight);
                if (!$this->isGdImage($canvas)) {
                    continue;
                }

                $white = @imagecolorallocate($canvas, 255, 255, 255);
                if ($white !== false) {
                    @imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
                }

                @imagealphablending($canvas, true);
                if (!@imagecopyresampled(
                    $canvas,
                    $source,
                    0,
                    0,
                    0,
                    0,
                    $targetWidth,
                    $targetHeight,
                    $sourceWidth,
                    $sourceHeight
                )) {
                    $this->releaseGdImage($canvas);
                    continue;
                }

                if ($needsOrientation) {
                    $oriented = $this->orientSmallCanvas($canvas, $orientation);
                    if (!$this->isGdImage($oriented)) {
                        $this->releaseGdImage($canvas);
                        continue;
                    }
                    if ($oriented !== $canvas) {
                        $this->releaseGdImage($canvas);
                        $canvas = $oriented;
                    }
                }

                foreach ($qualitySteps as $quality) {
                    ob_start();
                    $saved = @imagejpeg($canvas, null, $quality);
                    $candidate = ob_get_clean();
                    if (!$saved || !is_string($candidate) || $candidate === '') {
                        continue;
                    }
                    if (strlen($candidate) <= self::THUMB_TARGET_BYTES) {
                        $this->releaseGdImage($canvas);
                        return [$candidate, 'image/jpeg'];
                    }
                }

                $this->releaseGdImage($canvas);
            }
        } finally {
            $this->releaseGdImage($source);
        }

        return ['', ''];
    }

    private function hasEnoughMemoryForBlueskyFile(string $path, bool $needsOrientation): bool
    {
        $info = @getimagesize($path);
        if (!is_array($info)) {
            return false;
        }

        return $this->hasEnoughMemoryForBlueskyDimensions(
            (int) ($info[0] ?? 0),
            (int) ($info[1] ?? 0),
            $needsOrientation
        );
    }

    private function hasEnoughMemoryForBlueskyBytes(string $body, bool $needsOrientation): bool
    {
        $info = @getimagesizefromstring($body);
        if (!is_array($info)) {
            return false;
        }

        return $this->hasEnoughMemoryForBlueskyDimensions(
            (int) ($info[0] ?? 0),
            (int) ($info[1] ?? 0),
            $needsOrientation
        );
    }

    private function hasEnoughMemoryForBlueskyDimensions(int $width, int $height, bool $needsOrientation): bool
    {
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $longEdge = max($width, $height);
        $scale = $longEdge > self::THUMB_MAX_EDGE
            ? self::THUMB_MAX_EDGE / $longEdge
            : 1.0;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $sourceBytes = $width * $height * 4;
        $targetBytes = $targetWidth * $targetHeight * 4;
        $peakBytes = $sourceBytes + $targetBytes;
        if ($needsOrientation) {
            $peakBytes += $targetBytes;
        }

        $estimatedBytes = (int) ceil($peakBytes * 1.8);
        $memoryLimit = $this->memoryLimitBytes((string) ini_get('memory_limit'));
        if ($memoryLimit <= 0) {
            return true;
        }

        $reserveBytes = 16 * 1024 * 1024;
        return memory_get_usage(true) + $estimatedBytes + $reserveBytes < $memoryLimit;
    }

    private function memoryLimitBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        if ($unit === 'g') {
            $number *= 1024;
            $unit = 'm';
        }
        if ($unit === 'm') {
            $number *= 1024;
            $unit = 'k';
        }
        if ($unit === 'k') {
            $number *= 1024;
        }

        return $number > 0 ? (int) $number : -1;
    }

    private function isGdImage($value): bool
    {
        if (is_resource($value)) {
            return true;
        }

        return class_exists('GdImage') && $value instanceof \GdImage;
    }

    private function releaseGdImage($image): void
    {
        if (!$this->isGdImage($image)) {
            return;
        }

        if (PHP_VERSION_ID < 80000 && is_resource($image)) {
            @imagedestroy($image);
        }
    }

    private function hasBlobPermission(array $account): bool
    {
        $scope = trim((string) ($account['scope'] ?? ''));
        if ($scope === '') {
            return false;
        }

        $granted = preg_split('/\\s+/', $scope) ?: [];
        return in_array('blob:*/*', $granted, true);
    }

    private function validHttpsImageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return '';
        }

        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            ? $url
            : '';
    }

    private function ogImageUrl(string $html): string
    {
        if (preg_match(
            "/<meta\\s+[^>]*property=[\"']og:image[\"'][^>]*content=[\"']([^\"']+)[\"'][^>]*>/i",
            $html,
            $matches
        ) !== 1 && preg_match(
            "/<meta\\s+[^>]*content=[\"']([^\"']+)[\"'][^>]*property=[\"']og:image[\"'][^>]*>/i",
            $html,
            $matches
        ) !== 1) {
            return '';
        }

        $url = html_entity_decode(trim((string) ($matches[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            ? $url
            : '';
    }

    private function imageMimeType(BlueskyOAuthHttpResponse $response): string
    {
        $contentType = strtolower(trim((string) explode(';', $response->header('content-type'), 2)[0]));
        if (in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return $contentType;
        }

        if (function_exists('finfo_open')) {
            $handle = finfo_open(FILEINFO_MIME_TYPE);
            if ($handle !== false) {
                $detected = finfo_buffer($handle, $response->body);
                finfo_close($handle);
                if (is_string($detected) && in_array($detected, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    return $detected;
                }
            }
        }

        return '';
    }

    private function fitsPostLimit(string $text): bool
    {
        if (strlen($text) > 3000) {
            return false;
        }

        if (function_exists('grapheme_strlen')) {
            $length = grapheme_strlen($text);
            return is_int($length) && $length <= 300;
        }

        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8') <= 300;
        }

        $matches = [];
        return preg_match_all('/\X/u', $text, $matches) !== false
            && count($matches[0]) <= 300;
    }
}
