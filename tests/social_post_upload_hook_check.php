<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostUpload.php';

use Tomos\PostUpload;
use Tomos\SocialProvider;
use Tomos\SocialPublishResult;

final class HookFakeBlueskyProvider implements SocialProvider
{
    public int $calls = 0;
    public array $lastContext = [];

    public function name(): string
    {
        return 'bluesky';
    }

    public function publish(string $text, string $articleUrl, array $context = []): SocialPublishResult
    {
        $this->calls++;
        $this->lastContext = $context;
        if ($articleUrl !== 'https://example.test/social-hook') {
            throw new RuntimeException('unexpected article URL: ' . $articleUrl);
        }
        if ($text !== 'Custom social text') {
            throw new RuntimeException('unexpected social text');
        }
        return SocialPublishResult::success('bluesky', 'Blueskyへ投稿しました。', 'at://did:test/post/1', 'cid-1');
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-social-hook-' . bin2hex(random_bytes(6));
$contentDir = $root . DIRECTORY_SEPARATOR . 'content';
$cacheDir = $root . DIRECTORY_SEPARATOR . 'cache';
foreach ([$contentDir, $cacheDir] as $directory) {
    if (!mkdir($directory, 0775, true)) {
        throw new RuntimeException('test directory could not be created');
    }
}

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

$provider = new HookFakeBlueskyProvider();
$upload = new PostUpload($config, $root, $provider);
$stagedImagePath = $root . DIRECTORY_SEPARATOR . 'ogp-source.png';
if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
    throw new RuntimeException('GD PNG support is required for this regression test');
}
$fixtureImage = imagecreatetruecolor(8, 8);
if ($fixtureImage === false) {
    throw new RuntimeException('OGP image fixture could not be created');
}
$fixtureColor = imagecolorallocate($fixtureImage, 32, 96, 160);
if ($fixtureColor === false) {
    if (PHP_VERSION_ID < 80000) {
        imagedestroy($fixtureImage);
    }
    throw new RuntimeException('OGP image fixture color could not be created');
}
imagefilledrectangle($fixtureImage, 0, 0, 7, 7, $fixtureColor);
$fixtureSaved = imagepng($fixtureImage, $stagedImagePath);
if (PHP_VERSION_ID < 80000) {
    imagedestroy($fixtureImage);
}
if (!$fixtureSaved || !is_file($stagedImagePath) || filesize($stagedImagePath) <= 0) {
    throw new RuntimeException('OGP image fixture could not be encoded');
}
$imageName = 'tms-aaaaaaaaaaaaaaaa.png';
$imagePath = $contentDir . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $imageName;
$imageFiles = [
    'name' => $imageName,
    'type' => 'image/png',
    'tmp_name' => $stagedImagePath,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($stagedImagePath),
];

$result = $upload->handleContent(
    "---\ntitle: Social hook\nimage: images/{$imageName}\nsocial:\n  - bluesky\nsocial_text: Custom social text\n---\nBody\n",
    'social-hook.md',
    '',
    '',
    'session-a',
    $imageFiles,
    [],
    true,
    str_repeat('a', 64)
);

if (!$result->ok) {
    throw new RuntimeException('article publication failed: ' . implode(', ', $result->errors));
}
if (!($result->socialResult instanceof SocialPublishResult)) {
    throw new RuntimeException('successful public article must expose social result');
}
if ($result->socialResult->status !== SocialPublishResult::SUCCESS || $provider->calls !== 1) {
    throw new RuntimeException('social provider must run once after article publication');
}
if (($provider->lastContext['social_image_url'] ?? '') !== 'https://example.test/content/images/' . $imageName) {
    throw new RuntimeException('social image URL must be passed to provider');
}
$expectedImagePath = realpath($imagePath);
if (!is_string($expectedImagePath) || ($provider->lastContext['social_image_path'] ?? '') !== $expectedImagePath) {
    throw new RuntimeException('local social image path must be passed to provider');
}
if (!is_file($contentDir . DIRECTORY_SEPARATOR . 'social-hook.md')) {
    throw new RuntimeException('article must remain published');
}

$draft = $upload->handleContent(
    "---\ntitle: Draft social hook\ndraft: true\nsocial:\n  - bluesky\n---\nDraft body\n",
    'draft-social-hook.md',
    '',
    '',
    'session-a',
    [],
    [],
    false,
    str_repeat('b', 64)
);
if (!$draft->ok || !$draft->isDraft) {
    throw new RuntimeException('draft save failed');
}
if ($draft->socialResult !== null || $provider->calls !== 1) {
    throw new RuntimeException('draft save must not trigger social publishing');
}

function removeHookTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $candidate = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($candidate)) removeHookTree($candidate); else @unlink($candidate);
    }
    @rmdir($path);
}
removeHookTree($root);

echo "social_post_upload_hook_check: passed\n";
