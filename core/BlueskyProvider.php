<?php

declare(strict_types=1);

namespace Tomos;

final class BlueskyProvider implements SocialProvider
{
    private const MAX_CARD_HTML_BYTES = 262144;
    private const MAX_THUMB_BYTES = 2000000;

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

            $thumb = $this->cardThumbnail($articleUrl, $account);
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

    private function cardThumbnail(string $articleUrl, array $account): ?array
    {
        if (!$this->hasBlobPermission($account)) {
            return null;
        }

        try {
            $page = $this->session->getPublic($articleUrl, self::MAX_CARD_HTML_BYTES);
            if ($page->status < 200 || $page->status >= 300 || $page->body === '') {
                return null;
            }

            $imageUrl = $this->ogImageUrl($page->body);
            if ($imageUrl === '') {
                return null;
            }

            $image = $this->session->getPublic($imageUrl, self::MAX_THUMB_BYTES);
            if ($image->status < 200 || $image->status >= 300 || $image->body === '') {
                return null;
            }

            $mimeType = $this->imageMimeType($image);
            if ($mimeType === '') {
                return null;
            }

            $upload = $this->session->postBinary(
                '/xrpc/com.atproto.repo.uploadBlob',
                $image->body,
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
            // The article remains publishable even when the social card image cannot be attached.
            return null;
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
