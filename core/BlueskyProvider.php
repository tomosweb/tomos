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
                $imageBody = (string) @file_get_contents($explicitImagePath);
                $mimeType = $this->mimeTypeFromPath($explicitImagePath, $imageBody);
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
            }

            if ($imageBody === '' || $mimeType === '') {
                return null;
            }

            [$imageBody, $mimeType] = $this->prepareThumbnailForBluesky($imageBody, $mimeType);
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

    private function mimeTypeFromPath(string $path, string $body): string
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
                $detected = finfo_buffer($handle, $body);
                finfo_close($handle);
                if (is_string($detected) && in_array($detected, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    return $detected;
                }
            }
        }

        return '';
    }

    /** @return array{0:string,1:string} */
    private function prepareThumbnailForBluesky(string $body, string $mimeType): array
    {
        if (strlen($body) <= self::THUMB_TARGET_BYTES) {
            return [$body, $mimeType];
        }

        if (
            !function_exists('imagecreatefromstring') ||
            !function_exists('imagecreatetruecolor') ||
            !function_exists('imagecopyresampled') ||
            !function_exists('imagejpeg')
        ) {
            return ['', ''];
        }

        $source = @imagecreatefromstring($body);
        if ($source === false) {
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

            $qualitySteps = [82, 74, 66, 58, 50];
            $dimensionSteps = [1.0, 0.85, 0.70, 0.55];

            foreach ($dimensionSteps as $dimensionScale) {
                $scale = $initialScale * $dimensionScale;
                $targetWidth = max(1, (int) round($sourceWidth * $scale));
                $targetHeight = max(1, (int) round($sourceHeight * $scale));

                $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
                if ($canvas === false) {
                    continue;
                }

                $white = imagecolorallocate($canvas, 255, 255, 255);
                if ($white !== false) {
                    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
                }

                imagealphablending($canvas, true);
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
                    imagedestroy($canvas);
                    continue;
                }

                foreach ($qualitySteps as $quality) {
                    ob_start();
                    $saved = @imagejpeg($canvas, null, $quality);
                    $candidate = ob_get_clean();
                    if (!$saved || !is_string($candidate) || $candidate === '') {
                        continue;
                    }
                    if (strlen($candidate) <= self::THUMB_TARGET_BYTES) {
                        imagedestroy($canvas);
                        return [$candidate, 'image/jpeg'];
                    }
                }

                imagedestroy($canvas);
            }
        } finally {
            imagedestroy($source);
        }

        return ['', ''];
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
