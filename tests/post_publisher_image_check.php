<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) return;
    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require_once $file;
});

require_once dirname(__DIR__) . '/core/PostInboxApi.php';
require_once dirname(__DIR__) . '/core/PostInboxAutoPublisher.php';
require_once dirname(__DIR__) . '/core/PostDrafts.php';

use Tomos\PostDrafts;
use Tomos\PostInbox;
use Tomos\PostInboxApi;
use Tomos\PostInboxAutoPublisher;
use Tomos\PostInboxPreview;
use Tomos\PostPassword;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-publisher-images-' . bin2hex(random_bytes(6));
$content = $root . DIRECTORY_SEPARATOR . 'content';
$cache = $root . DIRECTORY_SEPARATOR . 'cache';
$inboxPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'inbox';
foreach ([$content, $cache, $inboxPath] as $directory) mkdir($directory, 0775, true);

$token = 'publisher-' . bin2hex(random_bytes(16));
$config = [
    'site' => ['url' => 'https://example.test/', 'timezone' => 'Asia/Tokyo'],
    'paths' => ['content_dir' => $content, 'cache_dir' => $cache, 'inbox_dir' => $inboxPath, 'theme_dir' => dirname(__DIR__) . '/themes'],
    'theme' => ['name' => 'tomos-minimal'],
    'features' => ['html_cache' => false],
    'metadata' => ['include_drafts' => false],
    'security' => ['inbox_api_token_hash' => PostPassword::hash($token)],
];
$https = ['HTTPS' => 'on', 'SERVER_PORT' => '443'];
$headers = ['X-Tomos-Token' => $token];
$api = new PostInboxApi(new PostInbox($config, $root), $config);
$processor = new PostInboxAutoPublisher(new PostInbox($config, $root), $config, $root);

try {
    $one = pngBytes(0x3366cc);
    $oneName = managedName($one);
    $oneMarkdown = markdown('one.md', $oneName, 'One', 'draft: false');
    uploadPublisherImage($api, $headers, $https, 'one.md', $oneMarkdown, [$oneName => $one]);
    $result = $processor->process('image-session-one', str_repeat('a', 64));
    $onePath = $content . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $oneName;
    assertTrue(is_file($content . DIRECTORY_SEPARATOR . 'one.md'), 'one-image Markdown must be published');
    assertTrue(is_file($onePath), 'one-image managed file must be published');
    assertTrue(!is_file($inboxPath . DIRECTORY_SEPARATOR . 'one.md'), 'published image Markdown must leave staging');
    assertTrue(count($result['warnings']) === 0, 'one-image publication must not warn');
    assertEmptyStage($inboxPath, 'one-image staging');

    $many = [];
    foreach ([0x110000, 0x220000, 0x004400, 0x000055, 0x550022] as $color) {
        $bytes = pngBytes($color);
        $many[managedName($bytes)] = $bytes;
    }
    $manyMarkdown = "---\ntitle: Many\ndraft: false\n---\n# Many\n\n";
    foreach (array_keys($many) as $name) $manyMarkdown .= '![' . $name . '](images/' . $name . ")\n";
    uploadPublisherImage($api, $headers, $https, 'many.md', $manyMarkdown, $many);
    $processor->process('image-session-many', str_repeat('b', 64));
    foreach (array_keys($many) as $name) assertTrue(is_file($content . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $name), 'five-image publication must preserve every image');
    assertEmptyStage($inboxPath, 'five-image staging');

    $incomplete = pngBytes(0xabcdef);
    $incompleteName = managedName($incomplete);
    $incompleteMarkdown = markdown('incomplete.md', $incompleteName, 'Incomplete', 'draft: false');
    $start = startPublisherImage($api, $headers, $https, 'incomplete.md', $incompleteMarkdown, [$incompleteName => $incomplete]);
    $finalize = $api->handle('POST', $headers, json_encode(['action' => 'finalize', 'upload_id' => $start]), $https);
    assertTrue($finalize->status === 400, 'finalize without every chunk must fail');
    assertTrue(!is_file($inboxPath . DIRECTORY_SEPARATOR . 'incomplete.md'), 'incomplete Markdown must not become a draft');
    assertEmptyStage($inboxPath, 'incomplete finalize cleanup');

    $retryOld = pngBytes(0x123456);
    $retryOldName = managedName($retryOld);
    $retryMarkdown = markdown('retry.md', $retryOldName, 'Retry', 'draft: false');
    uploadPublisherImage($api, $headers, $https, 'retry.md', $retryMarkdown, [$retryOldName => $retryOld]);
    $missingContent = $root . DIRECTORY_SEPARATOR . 'content-missing';
    rename($content, $missingContent);
    $failed = $processor->process('image-session-fail', str_repeat('c', 64));
    rename($missingContent, $content);
    $retryPath = $inboxPath . DIRECTORY_SEPARATOR . 'retry.md';
    $retryDraft = (string) file_get_contents($retryPath);
    assertTrue(strpos($retryDraft, 'draft: true') !== false, 'failed auto publication must become draft true');
    assertTrue(strpos(implode('\n', $failed['messages']), '下書きとして保存しました') !== false, 'failed publication message must explain draft recovery');
    $draftItems = (new PostDrafts($config, $root))->list();
    assertTrue(count(array_filter($draftItems, static fn ($item): bool => $item->path === 'retry.md')) === 1, 'failed Publisher item must appear in the draft list');
    $preview = (new PostInboxPreview($config, $root))->render($retryDraft, 'retry.md', 'retry.md');
    assertTrue(strpos($preview, '/post/inbox/image/') !== false, 'Publisher draft preview must expose staged image through the private endpoint');

    $retryNew = pngBytes(0x654321);
    $retryNewName = managedName($retryNew);
    $retryNewMarkdown = markdown('retry.md', $retryNewName, 'Retry corrected', 'draft: false');
    uploadPublisherImage($api, $headers, $https, 'retry.md', $retryNewMarkdown, [$retryNewName => $retryNew]);
    $processor->process('image-session-retry', str_repeat('d', 64));
    assertTrue(is_file($content . DIRECTORY_SEPARATOR . 'retry.md'), 'corrected same-name retry must publish');
    assertTrue(strpos((string) file_get_contents($content . DIRECTORY_SEPARATOR . 'retry.md'), 'Retry corrected') !== false, 'corrected Markdown must replace failed draft');
    assertTrue(!is_file($retryPath), 'corrected retry must remove old draft');
    assertTrue(is_file($content . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $retryNewName), 'corrected retry image must publish');
    assertEmptyStage($inboxPath, 'corrected retry staging');

    $hold = pngBytes(0xabcdef);
    $holdName = managedName($hold);
    $holdMarkdown = markdown('hold.md', $holdName, 'Hold', 'draft: false');
    uploadPublisherImage($api, $headers, $https, 'hold.md', $holdMarkdown, [$holdName => $hold]);
    rename($content, $missingContent);
    $processor->process('image-session-hold', str_repeat('e', 64));
    rename($missingContent, $content);
    $oldDraft = (string) file_get_contents($inboxPath . DIRECTORY_SEPARATOR . 'hold.md');
    $newHold = pngBytes(0x765432);
    $newHoldName = managedName($newHold);
    $newHoldMarkdown = markdown('hold.md', $newHoldName, 'Hold changed', 'draft: false');
    $holdUpload = startPublisherImage($api, $headers, $https, 'hold.md', $newHoldMarkdown, [$newHoldName => $newHold]);
    $outOfOrder = $api->handle('POST', array_merge($headers, [
        'X-Tomos-Action' => 'image', 'X-Tomos-Upload-Id' => $holdUpload, 'X-Tomos-Image-Name' => $newHoldName,
        'X-Tomos-Chunk-Index' => '1', 'X-Tomos-Chunk-Count' => '2', 'X-Tomos-Total-Size' => (string) strlen($newHold),
    ]), 'out-of-order', $https);
    assertTrue($outOfOrder->status === 400, 'out-of-order image chunks must be rejected');
    $bad = $api->handle('POST', array_merge($headers, [
        'X-Tomos-Action' => 'image',
        'X-Tomos-Upload-Id' => $holdUpload,
        'X-Tomos-Image-Name' => $newHoldName,
        'X-Tomos-Chunk-Index' => '0',
        'X-Tomos-Chunk-Count' => '1',
        'X-Tomos-Total-Size' => (string) strlen($newHold),
    ]), 'not-an-image', $https);
    assertTrue($bad->status === 400, 'hash-invalid retry chunk must fail');
    assertTrue((string) file_get_contents($inboxPath . DIRECTORY_SEPARATOR . 'hold.md') === $oldDraft, 'failed retry must preserve old draft Markdown');
    assertTrue((new PostInbox($config, $root))->stagedImageFiles('hold.md') !== [], 'failed retry must preserve old staged image');
    (new PostInbox($config, $root))->cancelImageReceive($holdUpload);
    $delete = (new PostDrafts($config, $root))->delete('inbox', 'hold.md');
    assertTrue(!empty($delete['ok']) && !is_file($inboxPath . DIRECTORY_SEPARATOR . 'hold.md'), 'draft delete must remove Publisher draft');
    assertTrue((new PostInbox($config, $root))->stagedImageFiles('hold.md')['name'] === [], 'draft delete must remove unpublished staged image');

    $six = [];
    foreach ([0x010101, 0x020202, 0x030303, 0x040404, 0x050505, 0x060606] as $color) $six[managedName(pngBytes($color))] = true;
    $tooMany = $api->handle('POST', $headers, json_encode(['action' => 'start', 'filename' => 'six.md', 'content' => $oneMarkdown, 'images' => $six]), $https);
    assertTrue($tooMany->status === 400, 'sixth image must be rejected');

    $fakeName = 'tms-0000000000000000.png';
    $fakeMarkdown = markdown('fake.md', $fakeName, 'Fake', 'draft: false');
    $fakeStart = startPublisherImage($api, $headers, $https, 'fake.md', $fakeMarkdown, [$fakeName => $one]);
    $fakeChunk = $api->handle('POST', array_merge($headers, [
        'X-Tomos-Action' => 'image', 'X-Tomos-Upload-Id' => $fakeStart, 'X-Tomos-Image-Name' => $fakeName,
        'X-Tomos-Chunk-Index' => '0', 'X-Tomos-Chunk-Count' => '1', 'X-Tomos-Total-Size' => (string) strlen($one),
    ]), $one, $https);
    assertTrue($fakeChunk->status === 400, 'fake managed image hash must be rejected');
    (new PostInbox($config, $root))->cancelImageReceive($fakeStart);

    $traversal = $api->handle('POST', $headers, json_encode(['action' => 'start', 'filename' => '../evil.md', 'content' => $oneMarkdown, 'images' => [$oneName]]), $https);
    assertTrue($traversal->status === 400, 'Publisher image upload must reject traversal filenames');

    echo "post_publisher_image_check: OK\n";
} finally {
    removeTree($root);
}

function markdown(string $fileName, string $imageName, string $title, string $draft): string
{
    return "---\ntitle: {$title}\n{$draft}\n---\n# {$title}\n\n![image](images/{$imageName})\n";
}

function pngBytes(int $color): string
{
    $image = imagecreatetruecolor(2, 2);
    imagefill($image, 0, 0, $color & 0xffffff);
    ob_start(); imagepng($image); $bytes = (string) ob_get_clean();
    return $bytes;
}

function managedName(string $bytes): string { return 'tms-' . substr(hash('sha256', $bytes), 0, 16) . '.png'; }

function startPublisherImage(PostInboxApi $api, array $headers, array $https, string $fileName, string $markdown, array $images): string
{
    $response = $api->handle('POST', $headers, json_encode(['action' => 'start', 'filename' => $fileName, 'content' => $markdown, 'images' => array_keys($images)]), $https);
    assertTrue($response->status === 201 && is_string($response->payload['upload_id'] ?? null), 'image receive must start');
    return (string) $response->payload['upload_id'];
}

function uploadPublisherImage(PostInboxApi $api, array $headers, array $https, string $fileName, string $markdown, array $images): void
{
    $uploadId = startPublisherImage($api, $headers, $https, $fileName, $markdown, $images);
    foreach ($images as $name => $bytes) {
        $chunks = str_split($bytes, 524288);
        foreach ($chunks as $index => $chunk) {
            $response = $api->handle('POST', array_merge($headers, [
                'X-Tomos-Action' => 'image', 'X-Tomos-Upload-Id' => $uploadId, 'X-Tomos-Image-Name' => $name,
                'X-Tomos-Chunk-Index' => (string) $index, 'X-Tomos-Chunk-Count' => (string) count($chunks), 'X-Tomos-Total-Size' => (string) strlen($bytes),
            ]), $chunk, $https);
            assertTrue($response->status === 200, 'image chunk must be accepted');
        }
    }
    $finalize = $api->handle('POST', $headers, json_encode(['action' => 'finalize', 'upload_id' => $uploadId]), $https);
    assertTrue($finalize->status === 201, 'complete image upload must finalize');
}

function assertEmptyStage(string $inboxPath, string $label): void
{
    $items = glob($inboxPath . DIRECTORY_SEPARATOR . '.publisher-assets' . DIRECTORY_SEPARATOR . 'items' . DIRECTORY_SEPARATOR . '*') ?: [];
    if ($items !== []) throw new RuntimeException($label . ' must be empty');
}

function assertTrue(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

function removeTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($child) && !is_link($child) ? removeTree($child) : @unlink($child);
    }
    @rmdir($path);
}
