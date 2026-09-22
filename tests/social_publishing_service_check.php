<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/PublishedMetadata.php';
require_once __DIR__ . '/../core/LanguageTag.php';
require_once __DIR__ . '/../core/FrontMatterParser.php';
require_once __DIR__ . '/../core/SocialPublishResult.php';
require_once __DIR__ . '/../core/SocialPostIntent.php';
require_once __DIR__ . '/../core/SocialProvider.php';
require_once __DIR__ . '/../core/SocialPostStore.php';
require_once __DIR__ . '/../core/SocialPublishingService.php';

use Tomos\FrontMatterParser;
use Tomos\SocialPostStore;
use Tomos\SocialProvider;
use Tomos\SocialPublishResult;
use Tomos\SocialPublishingService;

function socialAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

final class FakeBlueskyProvider implements SocialProvider
{
    public int $calls = 0;
    public string $lastText = '';
    public array $lastContext = [];

    public function name(): string
    {
        return 'bluesky';
    }

    public function publish(string $text, string $articleUrl, array $context = []): SocialPublishResult
    {
        $this->calls++;
        $this->lastText = $text;
        $this->lastContext = $context;
        return SocialPublishResult::success(
            'bluesky',
            'Blueskyへ投稿しました。',
            'at://did:plc:test/app.bsky.feed.post/123',
            'cid-test'
        );
    }
}

$root = sys_get_temp_dir() . '/tomos-social-service-' . bin2hex(random_bytes(6));
mkdir($root . '/storage', 0777, true);

$parser = new FrontMatterParser();
$store = new SocialPostStore([], $root);

$service = new SocialPublishingService($parser, $store, null);
$notRequested = $service->publishArticle('news/a.md', 'https://example.test/news/a', "---\ntitle: A\n---\nBody\n");
socialAssert($notRequested->status === SocialPublishResult::SKIPPED, 'missing social metadata must skip');
socialAssert($notRequested->code === 'not_requested', 'missing social metadata must return not_requested');

$notConnected = $service->publishArticle(
    'news/a.md',
    'https://example.test/news/a',
    "---\ntitle: A\nsocial:\n  - bluesky\n---\nBody\n"
);
socialAssert($notConnected->status === SocialPublishResult::FAILED, 'requested Bluesky without provider must fail independently');
socialAssert($notConnected->code === 'not_connected', 'missing provider must return not_connected');

$provider = new FakeBlueskyProvider();
$service = new SocialPublishingService($parser, $store, $provider);
$published = $service->publishArticle(
    'news/a.md',
    'https://example.test/news/a',
    "---\ntitle: A\nsocial:\n  - bluesky\nsocial_text: |\n  first line\n  second line\n---\nBody\n",
    ['social_image_url' => 'https://example.test/content/news/images/tms-aaaaaaaaaaaaaaaa.jpg']
);
socialAssert($published->status === SocialPublishResult::SUCCESS, 'provider success must be returned');
socialAssert($provider->calls === 1, 'provider must be called exactly once');
socialAssert($provider->lastText === "first line\nsecond line", 'custom literal text must be preserved');
socialAssert(
    ($provider->lastContext['social_image_url'] ?? '') === 'https://example.test/content/news/images/tms-aaaaaaaaaaaaaaaa.jpg',
    'explicit article social image URL must be forwarded to provider'
);
socialAssert($store->hasSuccessful('news/a.md', 'bluesky'), 'successful post must be persisted');

$duplicate = $service->publishArticle(
    'news/a.md',
    'https://example.test/news/a',
    "---\ntitle: A updated\nsocial:\n  - bluesky\n---\nChanged body\n"
);
socialAssert($duplicate->status === SocialPublishResult::SKIPPED, 'published article update must not repost');
socialAssert($duplicate->code === 'already_posted', 'duplicate must return already_posted');
socialAssert($provider->calls === 1, 'duplicate must not call provider');

$simplified = $service->publishArticle(
    'news/simple-multiline.md',
    'https://example.test/news/simple-multiline',
    "---\ntitle: Simple multiline\nsocial_text:\nfirst line\nsecond line|\nsocial:\n  - bluesky\n---\nBody\n"
);
socialAssert($simplified->status === SocialPublishResult::SUCCESS, 'simplified multiline social_text must publish');
socialAssert($provider->lastText === "first line\nsecond line", 'simplified multiline social_text must preserve line breaks');

$malformed = $service->publishArticle(
    'news/malformed-social-text.md',
    'https://example.test/news/malformed-social-text',
    "---\ntitle: Malformed\nsocial_text:\nfirst line\nsecond line\nsocial:\n  - bluesky\n---\nBody\n"
);
socialAssert($malformed->status === SocialPublishResult::FAILED, 'missing social_text terminator must fail social publishing only');
socialAssert($malformed->code === 'invalid_social_text', 'missing social_text terminator must return invalid_social_text');
socialAssert($provider->calls === 2, 'malformed social_text must not call provider');

$automatic = $service->publishArticle(
    'news/b.md',
    'https://example.test/news/b',
    "---\ntitle: B\nsocial:\n  - bluesky\n---\nFirst paragraph.\n\nSecond paragraph.\n"
);
socialAssert($automatic->status === SocialPublishResult::SUCCESS, 'automatic post must succeed');
socialAssert($provider->lastText === "B\n\nFirst paragraph.", 'automatic text must use title and first description');

$longTitle = str_repeat('あ', 400);
$autoLong = $service->publishArticle(
    'news/c.md',
    'https://example.test/news/c',
    "---\ntitle: " . $longTitle . "\nsocial:\n  - bluesky\n---\n\n"
);
socialAssert($autoLong->status === SocialPublishResult::SUCCESS, 'long automatic post must be shortened');
$autoLength = function_exists('grapheme_strlen')
    ? grapheme_strlen($provider->lastText)
    : (function_exists('mb_strlen') ? mb_strlen($provider->lastText, 'UTF-8') : preg_match_all('/\\X/u', $provider->lastText, $m));
socialAssert(is_int($autoLength) && $autoLength <= 300, 'automatic post must fit the Bluesky grapheme limit');
socialAssert(substr($provider->lastText, -strlen('…')) === '…', 'shortened automatic post must end with ellipsis');

$manualLong = str_repeat('い', 320);
$manual = $service->publishArticle(
    'news/d.md',
    'https://example.test/news/d',
    "---\ntitle: Manual\nsocial:\n  - bluesky\nsocial_text: " . $manualLong . "\n---\nBody\n"
);
socialAssert($manual->status === SocialPublishResult::SUCCESS, 'fake provider accepts manual long text for preservation check');
socialAssert($provider->lastText === $manualLong, 'manual social_text must never be shortened by SocialPublishingService');

function removeTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $candidate = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($candidate)) removeTree($candidate); else @unlink($candidate);
    }
    @rmdir($path);
}
removeTree($root);

echo "social_publishing_service_check: passed\n";
