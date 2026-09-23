<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostUpload.php';

use Tomos\PostUpload;
use Tomos\SocialProvider;
use Tomos\SocialPublishResult;

final class DeferredSocialFakeProvider implements SocialProvider
{
    public int $calls = 0;
    public bool $fail = false;
    public array $lastContext = [];

    public function name(): string
    {
        return 'bluesky';
    }

    public function publish(string $text, string $articleUrl, array $context = []): SocialPublishResult
    {
        $this->calls++;
        $this->lastContext = $context;

        if ($this->fail) {
            return SocialPublishResult::failed('bluesky', 'provider_error', 'fake provider failure');
        }

        return SocialPublishResult::success('bluesky', 'fake provider success', 'at://did:test/post/1', 'cid-1');
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-deferred-social-' . bin2hex(random_bytes(6));
$contentDir = $root . DIRECTORY_SEPARATOR . 'content';
$cacheDir = $root . DIRECTORY_SEPARATOR . 'cache';
mkdir($contentDir, 0775, true);
mkdir($cacheDir, 0775, true);

$config = [
    'site' => [
        'url' => 'https://example.test/',
        'base_path' => '',
        'public_base_path' => '',
        'timezone' => 'Asia/Tokyo',
    ],
    'paths' => [
        'content_dir' => $contentDir,
        'cache_dir' => $cacheDir,
    ],
    'features' => ['html_cache' => false],
    'metadata' => ['include_drafts' => false],
];

$provider = new DeferredSocialFakeProvider();
$upload = new PostUpload($config, $root, $provider);

try {
    // Cases 1-3: save now, then resume from the saved content path.
    $saved = $upload->handleContentDeferredSocial(
        "---\ntitle: Deferred article\nsocial:\n  - bluesky\nsocial_text: Deferred post\n---\nBody\n",
        'deferred.md',
        '',
        '',
        'session-a',
        [],
        [],
        false,
        str_repeat('a', 64)
    );
    assertTrue($saved->ok, 'deferred article save must succeed');
    assertSame('deferred.md', $saved->contentPath, 'saved contentPath must identify the article');
    assertSame(0, $provider->calls, 'deferred article save must not publish in the same request');
    $savedPath = $contentDir . DIRECTORY_SEPARATOR . $saved->contentPath;
    assertTrue(is_file($savedPath), 'deferred article must remain saved before social resume');

    // A new PostUpload instance represents the fresh request after the save response.
    $resumeUpload = new PostUpload($config, $root, $provider);
    $resumed = $resumeUpload->publishSocialFromSavedContent($saved->contentPath);
    assertSame(SocialPublishResult::SUCCESS, $resumed->status, 'saved content must resume social publishing');
    assertSame(1, $provider->calls, 'resumed social publishing must call the provider once');
    assertTrue(is_file($savedPath), 'successful social publishing must not delete the saved article');

    // Case 4: resolve Front Matter image: relative to the saved article.
    mkdir($contentDir . DIRECTORY_SEPARATOR . 'images', 0775, true);
    $ogpImagePath = $contentDir . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'example.jpg';
    createJpeg($ogpImagePath);
    file_put_contents(
        $contentDir . DIRECTORY_SEPARATOR . 'ogp.md',
        "---\ntitle: OGP article\nimage: images/example.jpg\nsocial:\n  - bluesky\n---\nOGP body\n"
    );

    $ogpResult = $resumeUpload->publishSocialFromSavedContent('ogp.md');
    assertSame(SocialPublishResult::SUCCESS, $ogpResult->status, 'Front Matter OGP article must publish');
    assertSame(2, $provider->calls, 'OGP article must publish once');
    assertSame('https://example.test/content/images/example.jpg', $provider->lastContext['social_image_url'] ?? '', 'Front Matter image URL must resolve correctly');
    assertSame(realpath($ogpImagePath), $provider->lastContext['social_image_path'] ?? '', 'Front Matter image path must resolve correctly');

    // Case 5: a provider failure must not roll back the already-saved article.
    $provider->fail = true;
    $failedSave = $upload->handleContentDeferredSocial(
        "---\ntitle: Failed social article\nsocial:\n  - bluesky\n---\nKeep me\n",
        'failed-social.md',
        '',
        '',
        'session-b',
        [],
        [],
        false,
        str_repeat('b', 64)
    );
    assertTrue($failedSave->ok, 'article must save even when later social publishing will fail');
    $failedPath = $contentDir . DIRECTORY_SEPARATOR . $failedSave->contentPath;
    $savedMarkdown = (string) file_get_contents($failedPath);
    $failedResult = $resumeUpload->publishSocialFromSavedContent($failedSave->contentPath);
    assertSame(SocialPublishResult::FAILED, $failedResult->status, 'provider failure must be reported');
    assertTrue(is_file($failedPath), 'provider failure must not delete the saved article');
    assertSame($savedMarkdown, (string) file_get_contents($failedPath), 'provider failure must not roll back saved Markdown');

    // Case 6: no social metadata must skip without calling the provider.
    $callsBeforeNoSocial = $provider->calls;
    $noSocial = $upload->handleContentDeferredSocial(
        "---\ntitle: No social article\n---\nNo post\n",
        'no-social.md',
        '',
        '',
        'session-c',
        [],
        [],
        false,
        str_repeat('c', 64)
    );
    assertTrue($noSocial->ok, 'article without social metadata must save');
    assertSame($callsBeforeNoSocial, $provider->calls, 'article save without social metadata must not call provider');
    $noSocialResult = $resumeUpload->publishSocialFromSavedContent($noSocial->contentPath);
    assertSame(SocialPublishResult::SKIPPED, $noSocialResult->status, 'article without social metadata must skip');
    assertSame('not_requested', $noSocialResult->code, 'missing social metadata must return not_requested');
    assertSame($callsBeforeNoSocial, $provider->calls, 'missing social metadata must not call provider');
} finally {
    removeDeferredSocialTree($root);
}

echo "post_upload_deferred_social_check: passed\n";

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nexpected: " . var_export($expected, true) . "\nactual: " . var_export($actual, true));
    }
}

function createJpeg(string $path): void
{
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        throw new RuntimeException('GD JPEG support is required for this regression test');
    }

    $image = imagecreatetruecolor(8, 8);
    if ($image === false) {
        throw new RuntimeException('JPEG fixture could not be created');
    }
    $color = imagecolorallocate($image, 32, 96, 160);
    if ($color === false) {
        releaseDeferredSocialImage($image);
        throw new RuntimeException('JPEG fixture color could not be created');
    }
    imagefilledrectangle($image, 0, 0, 7, 7, $color);
    $saved = imagejpeg($image, $path, 90);
    releaseDeferredSocialImage($image);
    if (!$saved || !is_file($path) || filesize($path) <= 0) {
        throw new RuntimeException('JPEG fixture could not be encoded');
    }
}

function releaseDeferredSocialImage($image): void
{
    if (PHP_VERSION_ID < 80000 && is_resource($image)) {
        imagedestroy($image);
    }
}

function removeDeferredSocialTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) {
            removeDeferredSocialTree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}
