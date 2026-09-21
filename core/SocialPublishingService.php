<?php

declare(strict_types=1);

namespace Tomos;

final class SocialPublishingService
{
    private FrontMatterParser $frontMatterParser;
    private SocialPostStore $store;
    private ?SocialProvider $blueskyProvider;

    public function __construct(
        FrontMatterParser $frontMatterParser,
        SocialPostStore $store,
        ?SocialProvider $blueskyProvider = null
    ) {
        $this->frontMatterParser = $frontMatterParser;
        $this->store = $store;
        $this->blueskyProvider = $blueskyProvider;
    }

    public function publishArticle(string $articleId, string $articleUrl, string $markdown): SocialPublishResult
    {
        $parsed = $this->frontMatterParser->parse($markdown);
        $metadata = is_array($parsed['metadata'] ?? null) ? $parsed['metadata'] : [];
        $intent = SocialPostIntent::fromMetadata($metadata);

        if (!$intent->blueskyEnabled) {
            return SocialPublishResult::skipped('bluesky', 'not_requested', 'Bluesky投稿は指定されていません。');
        }

        if (($metadata['__social_text_error'] ?? null) === 'missing_terminator') {
            return SocialPublishResult::failed(
                'bluesky',
                'invalid_social_text',
                'social_text の終端「|」がありません。記事は公開しましたが、Blueskyには投稿していません。'
            );
        }

        if ($this->store->hasSuccessful($articleId, 'bluesky')) {
            return SocialPublishResult::skipped('bluesky', 'already_posted', 'この記事はBlueskyへ投稿済みです。');
        }

        if ($this->blueskyProvider === null) {
            return SocialPublishResult::failed('bluesky', 'not_connected', 'Blueskyが接続されていません。');
        }

        $body = (string) ($parsed['body'] ?? '');
        $pageMetadata = $this->frontMatterParser->buildPageMetadata($metadata, $body, $articleId);
        $custom = $intent->customText !== null;
        $text = $intent->customText ?? $this->fitAutomaticBlueskyText($this->automaticText(
            $metadata,
            $body,
            $articleId
        ));

        $result = $this->blueskyProvider->publish($text, $articleUrl, [
            'article_id' => $articleId,
            'metadata' => $metadata,
            'page_metadata' => $pageMetadata,
            'automatic_text' => !$custom,
        ]);

        if (!$this->store->append($articleId, $result, $text)) {
            if ($result->status === SocialPublishResult::SUCCESS) {
                return SocialPublishResult::failed(
                    'bluesky',
                    'history_write_failed',
                    'Blueskyへの投稿は完了しましたが、投稿履歴を保存できませんでした。',
                    $result->remoteUri,
                    $result->remoteCid
                );
            }
        }

        return $result;
    }

    private function automaticText(array $metadata, string $body, string $contentPath): string
    {
        $page = $this->frontMatterParser->buildPageMetadata($metadata, $body, $contentPath);
        $title = trim((string) ($page['title'] ?? ''));
        $description = trim((string) ($page['description'] ?? ''));

        if ($title !== '' && $description !== '') {
            return $title . "\n\n" . $description;
        }
        if ($title !== '') {
            return $title;
        }
        return $description;
    }

    private function fitAutomaticBlueskyText(string $text): string
    {
        if ($this->blueskyTextFits($text)) {
            return $text;
        }

        $suffix = '…';
        if (function_exists('grapheme_substr')) {
            $candidate = (string) grapheme_substr($text, 0, 299) . $suffix;
        } elseif (function_exists('mb_substr')) {
            $candidate = mb_substr($text, 0, 299, 'UTF-8') . $suffix;
        } else {
            $matches = [];
            $candidate = $text;
            if (preg_match_all('/\\X/u', $text, $matches) !== false) {
                $candidate = implode('', array_slice($matches[0], 0, 299)) . $suffix;
            }
        }

        while (strlen($candidate) > 3000 && $candidate !== '') {
            if (function_exists('grapheme_strlen') && function_exists('grapheme_substr')) {
                $length = grapheme_strlen($candidate);
                $candidate = is_int($length)
                    ? (string) grapheme_substr($candidate, 0, max(0, $length - 2)) . $suffix
                    : $candidate;
            } elseif (function_exists('mb_strlen') && function_exists('mb_substr')) {
                $length = mb_strlen($candidate, 'UTF-8');
                $candidate = mb_substr($candidate, 0, max(0, $length - 2), 'UTF-8') . $suffix;
            } else {
                $candidate = substr($candidate, 0, max(0, strlen($candidate) - 8)) . $suffix;
            }
        }

        return $candidate;
    }

    private function blueskyTextFits(string $text): bool
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
        return preg_match_all('/\\X/u', $text, $matches) !== false
            && count($matches[0]) <= 300;
    }

}
