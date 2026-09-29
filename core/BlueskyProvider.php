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

        $record = $this->postRecord($text);

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
            $this->thumbnailDiagnostic('record_thumbnail_decision', [
                'thumb_attached' => $thumb !== null,
            ]);

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
            $this->thumbnailDiagnostic('thumbnail_skipped', ['reason' => 'blob_permission_missing']);
            return null;
        }

        $stage = 'resolve_image';
        try {
            $imageBody = '';
            $mimeType = '';
            $source = 'none';

            if ($explicitImagePath !== '' && is_file($explicitImagePath)) {
                $source = 'local';
                $mimeType = $this->mimeTypeFromFile($explicitImagePath);
                $fileSize = @filesize($explicitImagePath);
                $imageInfo = $this->thumbnailFileDimensions($explicitImagePath);
                $this->thumbnailDiagnostic('image_input', [
                    'source' => 'local',
                    'bytes' => is_int($fileSize) ? $fileSize : null,
                    'mime' => $mimeType !== '' ? $mimeType : null,
                    'width' => is_array($imageInfo) ? (int) ($imageInfo[0] ?? 0) : null,
                    'height' => is_array($imageInfo) ? (int) ($imageInfo[1] ?? 0) : null,
                    'orientation' => $mimeType === 'image/jpeg' ? $this->jpegOrientation($explicitImagePath) : 1,
                ]);
                if ($mimeType !== '') {
                    $stage = 'prepare_local';
                    [$imageBody, $mimeType] = $this->prepareLocalThumbnailForBluesky(
                        $explicitImagePath,
                        $mimeType
                    );
                    $this->thumbnailDiagnostic('image_prepared', [
                        'source' => 'local',
                        'bytes' => strlen($imageBody),
                        'mime' => $mimeType !== '' ? $mimeType : null,
                        'dimensions' => $imageBody !== '' ? $this->thumbnailDimensions($imageBody) : null,
                    ]);
                } else {
                    $this->thumbnailDiagnostic('image_rejected', ['source' => 'local', 'reason' => 'unsupported_mime']);
                }
            } elseif ($explicitImagePath !== '') {
                $this->thumbnailDiagnostic('local_image_unavailable', ['path_supplied' => true]);
            }

            if ($imageBody === '' || $mimeType === '') {
                $source = 'remote';
                $stage = 'resolve_remote_image';
                $imageUrl = $this->validHttpsImageUrl($explicitImageUrl);
                if ($imageUrl === '') {
                    $page = $this->session->getPublic($articleUrl, self::MAX_CARD_HTML_BYTES);
                    if ($page->status < 200 || $page->status >= 300 || $page->body === '') {
                        $this->thumbnailDiagnostic('image_rejected', [
                            'source' => 'article_page',
                            'reason' => 'http_response_unusable',
                            'http_status' => $page->status,
                            'response_bytes' => strlen($page->body),
                        ]);
                        return null;
                    }

                    $imageUrl = $this->ogImageUrl($page->body);
                    if ($imageUrl === '') {
                        $this->thumbnailDiagnostic('image_rejected', [
                            'source' => 'article_page',
                            'reason' => 'og_image_missing',
                        ]);
                        return null;
                    }
                }

                $stage = 'download_remote_image';
                $image = $this->session->getPublic($imageUrl, self::MAX_THUMB_BYTES);
                if ($image->status < 200 || $image->status >= 300 || $image->body === '') {
                    $this->thumbnailDiagnostic('image_rejected', [
                        'source' => 'remote',
                        'reason' => 'http_response_unusable',
                        'http_status' => $image->status,
                        'response_bytes' => strlen($image->body),
                    ]);
                    return null;
                }

                $imageBody = $image->body;
                $mimeType = $this->imageMimeType($image);
                $this->thumbnailDiagnostic('image_input', [
                    'source' => 'remote',
                    'bytes' => strlen($imageBody),
                    'mime' => $mimeType !== '' ? $mimeType : null,
                    'dimensions' => $this->thumbnailDimensions($imageBody),
                ]);
                if ($imageBody === '' || $mimeType === '') {
                    $this->thumbnailDiagnostic('image_rejected', ['source' => 'remote', 'reason' => 'unsupported_mime']);
                    return null;
                }

                $stage = 'prepare_remote';
                [$imageBody, $mimeType] = $this->prepareThumbnailForBluesky($imageBody, $mimeType);
                $this->thumbnailDiagnostic('image_prepared', [
                    'source' => 'remote',
                    'bytes' => strlen($imageBody),
                    'mime' => $mimeType !== '' ? $mimeType : null,
                    'dimensions' => $imageBody !== '' ? $this->thumbnailDimensions($imageBody) : null,
                ]);
            }

            if ($imageBody === '' || $mimeType === '' || strlen($imageBody) > self::MAX_THUMB_BYTES) {
                $this->thumbnailDiagnostic('image_rejected', [
                    'source' => $source,
                    'reason' => 'prepared_image_unusable',
                    'bytes' => strlen($imageBody),
                    'mime' => $mimeType !== '' ? $mimeType : null,
                    'max_bytes' => self::MAX_THUMB_BYTES,
                ]);
                return null;
            }

            $stage = 'upload_blob';
            $this->thumbnailDiagnostic('upload_started', [
                'bytes' => strlen($imageBody),
                'mime' => $mimeType,
            ]);
            $upload = $this->session->postBinary(
                '/xrpc/com.atproto.repo.uploadBlob',
                $imageBody,
                $mimeType
            );

            $decoded = json_decode($upload->body, true);
            $blob = is_array($decoded) && is_array($decoded['blob'] ?? null)
                ? $decoded['blob']
                : null;
            $responseError = is_array($decoded) && is_string($decoded['error'] ?? null)
                ? preg_replace('/[^A-Za-z0-9_.-]/', '', substr($decoded['error'], 0, 64))
                : null;
            $this->thumbnailDiagnostic('upload_response', [
                'http_status' => $upload->status,
                'response_bytes' => strlen($upload->body),
                'json_valid' => is_array($decoded),
                'blob_present' => is_array($blob),
                'blob_mime' => is_array($blob) ? (string) ($blob['mimeType'] ?? '') : null,
                'blob_bytes' => is_array($blob) && is_numeric($blob['size'] ?? null) ? (int) $blob['size'] : null,
                'error_code' => $responseError !== '' ? $responseError : null,
            ]);
            if ($upload->status < 200 || $upload->status >= 300) {
                return null;
            }

            return is_array($blob) ? $blob : null;
        } catch (\\Throwable $exception) {
            $this->thumbnailDiagnostic('thumbnail_exception', [
                'stage' => $stage,
                'exception' => get_class($exception),
            ]);
            return null;
        }
    }

    /** @param array<string,mixed> $context */
    private function thumbnailDiagnostic(string $event, array $context = []): void
    {
        $line = json_encode(
            ['event' => $event, 'context' => $context],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (is_string($line)) {
            error_log('Tomos Bluesky thumbnail diagnostic=' . $line);
        }
    }

    /** @return array<int,mixed>|false */
    private function thumbnailFileDimensions(string $path)
    {
        return function_exists('getimagesize') ? @getimagesize($path) : false;
    }

    /** @return array{width:int,height:int}|null */
    private function thumbnailDimensions(string $body): ?array
    {
        if (!function_exists('getimagesizefromstring')) {
            return null;
        }
        $info = @getimagesizefromstring($body);
        if (!is_array($info)) {
            return null;
        }
        return [
            'width' => (int) ($info[0] ?? 0),
            'height' => (int) ($info[1] ?? 0),
        ];
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

    /**
     * @return array<string,mixed>
     */
    private function postRecord(string $text): array
    {
        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $facets = $this->hashtagFacets($text);
        if ($facets !== []) {
            $record['facets'] = $facets;
        }

        return $record;
    }

    /**
     * @return array<int,array{index:array{byteStart:int,byteEnd:int},features:array<int,array{'$type':string,tag:string}>}>
     */
    private function hashtagFacets(string $text): array
    {
        $matches = [];
        $matched = preg_match_all(
            '/(^|[\\s(])#([^\\d\\s]\\S*)/u',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        if ($matched === false || $matched === 0) {
            return [];
        }

        $facets = [];
        foreach ($matches[0] as $index => $match) {
            $prefix = isset($matches[1][$index][0]) ? (string) $matches[1][$index][0] : '';
            $rawTag = isset($matches[2][$index][0]) ? (string) $matches[2][$index][0] : '';
            $matchOffset = (int) ($match[1] ?? -1);
            $tag = preg_replace('/\\p{P}+$/u', '', $rawTag);

            if (!is_string($tag) || $tag === '' || $matchOffset < 0) {
                continue;
            }
            if (strlen($tag) > 640 || $this->graphemeLength($tag) > 64) {
                continue;
            }

            $byteStart = $matchOffset + strlen($prefix);
            $facetText = '#' . $tag;
            $facets[] = [
                'index' => [
                    'byteStart' => $byteStart,
                    'byteEnd' => $byteStart + strlen($facetText),
                ],
                'features' => [[
                    '$type' => 'app.bsky.richtext.facet#tag',
                    'tag' => $tag,
                ]],
            ];
        }

        return $facets;
    }

    private function graphemeLength(string $text): int
    {
        if (function_exists('grapheme_strlen')) {
            $length = grapheme_strlen($text);
            if (is_int($length)) {
                return $length;
            }
        }

        $matches = [];
        $count = preg_match_all('/\\X/u', $text, $matches);
        return $count === false ? PHP_INT_MAX : $count;
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
