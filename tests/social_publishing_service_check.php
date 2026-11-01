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

$quoted = $service->publishArticle(
    'news/quoted-social-text.md',
    'https://example.test/news/quoted-social-text',
    "---\ntitle: Quoted\nsocial:\n  - bluesky\nsocial_text: \"Blueskyへの告知 #Tomos\"\n---\nBody\n"
);
socialAssert($quoted->status === SocialPublishResult::SUCCESS, 'quoted single-line social_text must publish');
socialAssert($provider->lastText === 'Blueskyへの告知 #Tomos', 'quoted single-line social_text must not send YAML quotes to the provider');

$singleQuoted = $service->publishArticle(
    'news/single-quoted-social-text.md',
    'https://example.test/news/single-quoted-social-text',
    "---\ntitle: Single quoted\nsocial:\n  - bluesky\nsocial_text: '複数タグ #Tomos #Obsidian https://example.test/news'\n---\nBody\n"
);
socialAssert($singleQuoted->status === SocialPublishResult::SUCCESS, 'single-quoted social_text must publish');
socialAssert(
    $provider->lastText === '複数タグ #Tomos #Obsidian https://example.test/news',
    'single-quoted social_text must preserve Japanese, multiple hashtags, and URLs without YAML quotes'
);

$empty = $service->publishArticle(
    'news/empty-social-text.md',
    'https://example.test/news/empty-social-text',
    "---\ntitle: Empty social text\nsocial:\n  - bluesky\nsocial_text: \"\"\n---\n本文\n"
);
socialAssert($empty->status === SocialPublishResult::SUCCESS, 'empty social_text must preserve automatic posting behavior');
socialAssert($provider->lastText === "Empty social text\n\n本文", 'empty social_text must fall back to automatic post text');

$malformed = $service->publishArticle(
    'news/malformed-social-text.md',
    'https://example.test/news/malformed-social-text',
    "---\ntitle: Malformed\nsocial_text:\nfirst line\nsecond line\nsocial:\n  - bluesky\n---\nBody\n"
);
socialAssert($malformed->status === SocialPublishResult::FAILED, 'missing social_text terminator must fail social publishing only');
socialAssert($malformed->code === 'invalid_social_text', 'missing social_text terminator must return invalid_social_text');
socialAssert($provider->calls === 5, 'malformed social_text must not call provider');

$automatic = $service->publishArticle(
    'news/b.md',
    'https://example.test/news/b',
    "---\ntitle: B\nsocial:\n  - bluesky\n---\nFirst paragraph.\n\nSecond paragraph.\n"
);
socialAssert($automatic->status === SocialPublishResult::SUCCESS, 'automatic post must succeed');
socialAssert($provider->lastText === "B\n\nFirst paragraph.", 'automatic text must use title and first description');

$imageFirst = $service->publishArticle(
    'news/image-first.md',
    'https://example.test/news/image-first',
    "---\ntitle: 銀座屋の焼き魚\nsocial:\n  - bluesky\n---\n![IMG6383](images/fish.jpg)\n天満駅前の立ち飲み「銀座屋」の日替わりメニューに焼き魚があったら頼むことが多い。\n"
);
socialAssert($imageFirst->status === SocialPublishResult::SUCCESS, 'image-first article must publish');
socialAssert($provider->lastText === "銀座屋の焼き魚\n\n天満駅前の立ち飲み「銀座屋」の日替わりメニューに焼き魚があったら頼むことが多い。", 'image alt must not leak into automatic Bluesky text');

$inlineImage = $parser->excerptFromMarkdown("本文の前 ![PHOTO](photo.jpg) 本文の後");
socialAssert($inlineImage === '本文の前 本文の後', 'inline image alt must not enter excerpt');

$endingImage = $parser->excerptFromMarkdown("本文 ![LAST](last.jpg)");
socialAssert($endingImage === '本文', 'trailing image alt must not enter excerpt');

$explicitDescription = $service->publishArticle(
    'news/explicit-description.md',
    'https://example.test/news/explicit-description',
    "---\ntitle: Explicit\ndescription: 指定した説明\nsocial:\n  - bluesky\n---\n![IMG6383](photo.jpg)\n本文\n"
);
socialAssert($explicitDescription->status === SocialPublishResult::SUCCESS, 'explicit description article must publish');
socialAssert($provider->lastText === "Explicit\n\n指定した説明", 'explicit description must remain unchanged');

// Image-only paragraphs must not hide the first prose paragraph.
foreach ([
    "![IMG6383](photo.jpg)\n\n本文\n\n次の段落",
    "![IMG6383](photo.jpg)\n\n![SECOND](second.jpg)\n\n本文",
    "![IMG6383](photo.jpg)\n# 見出し\n\n本文",
] as $index => $body) {
    $markdown = "---\ntitle: Image paragraph\nsocial:\n  - bluesky\n---\n" . $body;
    $parsedImage = $parser->parse($markdown);
    socialAssert($parsedImage['body'] === $body, 'parsing must preserve article body and image alt');
    $result = $service->publishArticle('news/image-paragraph-' . $index . '.md', 'https://example.test/image-paragraph-' . $index, $markdown);
    socialAssert($result->status === SocialPublishResult::SUCCESS, 'image paragraph article must publish');
    socialAssert($provider->lastText === "Image paragraph\n\n本文", 'image-only paragraphs must not suppress prose');
}
socialAssert($parser->excerptFromMarkdown('![ONLY](only.jpg)') === '', 'image-only article must have an empty excerpt');
socialAssert($parser->excerptFromMarkdown('前![IMG](image.jpg)後') === '前後', 'adjacent prose must be preserved');
socialAssert($parser->excerptFromMarkdown("本文\n\n![LAST](last.jpg)") === '本文', 'later image paragraph must not affect first paragraph');
socialAssert($parser->excerptFromMarkdown("# 見出し\n\n**本文**と[リンク](https://example.test)と`code`。\n\n次の段落") === '本文とリンクとcode。', 'ordinary Markdown extraction must stay unchanged');
socialAssert($parser->extractFirstHeading('# 見出し ![TITLE](title.jpg)') === '見出し TITLE', 'heading-derived title must keep its previous image alt behavior');
$headingMetadata = $parser->buildPageMetadata([], "# ![TITLE](title.jpg)\n\n本文", 'news/heading.md');
socialAssert($headingMetadata['title'] === 'TITLE', 'image-only heading title must remain unchanged');
socialAssert($headingMetadata['description'] === '本文', 'shared metadata must exclude images from automatic descriptions');

$customWithImage = $service->publishArticle(
    'news/custom-with-image.md',
    'https://example.test/news/custom-with-image',
    "---\ntitle: Custom\ndescription: 指定した説明\nsocial:\n  - bluesky\nsocial_text: 手動の ![KEEP](manual.jpg) #Tomos\n---\n![IMG6383](photo.jpg)\n\n本文\n",
    ['social_image_url' => 'https://example.test/photo.jpg', 'social_image_path' => '/tmp/photo.jpg']
);
socialAssert($customWithImage->status === SocialPublishResult::SUCCESS, 'custom text with image body must publish');
socialAssert($provider->lastText === '手動の ![KEEP](manual.jpg) #Tomos', 'explicit social_text must override description and remain unchanged');
socialAssert($provider->lastContext['page_metadata']['description'] === '指定した説明', 'explicit description must survive shared metadata generation');
socialAssert($provider->lastContext['social_image_url'] === 'https://example.test/photo.jpg', 'image URL context must remain unchanged');
socialAssert($provider->lastContext['social_image_path'] === '/tmp/photo.jpg', 'image path context must remain unchanged');

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
