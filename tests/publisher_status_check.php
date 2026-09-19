<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PublishedMetadata.php';
require_once dirname(__DIR__) . '/core/PostInboxApi.php';

use Tomos\PostInbox;
use Tomos\PostInboxApi;
use Tomos\PostInboxAutoPublisher;
use Tomos\PostPassword;
use Tomos\PublisherStatusStore;

function publisherStatusAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-publisher-status-' . bin2hex(random_bytes(6));
$contentDir = $root . DIRECTORY_SEPARATOR . 'content';
$cacheDir = $root . DIRECTORY_SEPARATOR . 'cache';
$inboxDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'inbox';
foreach ([$contentDir, $cacheDir, $inboxDir] as $directory) {
    if (!mkdir($directory, 0775, true)) {
        throw new RuntimeException('test directory could not be created');
    }
}

$token = 'publisher-status-token-' . bin2hex(random_bytes(8));
$config = [
    'site' => ['url' => 'https://example.test/', 'timezone' => 'Asia/Tokyo'],
    'paths' => [
        'content_dir' => $contentDir,
        'cache_dir' => $cacheDir,
        'inbox_dir' => $inboxDir,
    ],
    'features' => ['html_cache' => false],
    'metadata' => ['include_drafts' => false],
    'security' => ['inbox_api_token_hash' => PostPassword::hash($token)],
];

$inbox = new PostInbox($config, $root);
$statusStore = new PublisherStatusStore($config, $root);
$autoPublisher = new PostInboxAutoPublisher($inbox, $config, $root);
$api = new PostInboxApi($inbox, $config, $statusStore, $autoPublisher);
$https = ['HTTPS' => 'on', 'SERVER_PORT' => '443'];
$authHeaders = ['X-Tomos-Token' => $token];

$requestId = 'publisher-status-test-0001';
$markdown = "---\ntitle: Publisher status\nsocial:\n  - bluesky\n---\n# Publisher status\n";
$received = $api->handle('POST', $authHeaders, json_encode([
    'request_id' => $requestId,
    'filename' => 'publisher-status.md',
    'content' => $markdown,
], JSON_UNESCAPED_UNICODE), $https);
publisherStatusAssert($received->status === 201, 'Publisher request must be accepted');
publisherStatusAssert(($received->payload['request_id'] ?? '') === $requestId, 'request_id must be echoed');
publisherStatusAssert(
    $inbox->publisherRequestId('publisher-status.md') === $requestId,
    'request_id must be associated with inbox item before auto publish: ' . $inbox->publisherRequestId('publisher-status.md')
);

$status = $api->handle('GET', [
    'X-Tomos-Token' => $token,
    'X-Tomos-Action' => 'status',
    'X-Tomos-Request-Id' => $requestId,
], '', $https);
publisherStatusAssert($status->status === 200, 'Publisher status lookup must succeed');
publisherStatusAssert(
    ($status->payload['state'] ?? '') === 'published',
    'status lookup must advance article to published: ' . json_encode($status->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
publisherStatusAssert(is_file($contentDir . DIRECTORY_SEPARATOR . 'publisher-status.md'), 'article must be published');
publisherStatusAssert(($status->payload['social']['status'] ?? '') === 'failed', 'social failure must be reported separately');
publisherStatusAssert(($status->payload['social']['code'] ?? '') === 'not_connected', 'disconnected Bluesky must return not_connected');

$updateRequestId = 'publisher-status-update-0002';
$updatedMarkdown = "---\ntitle: Publisher status\nsocial:\n  - bluesky\n---\n# Publisher status\n\nUpdated body.\n";
$updateReceived = $api->handle('POST', $authHeaders, json_encode([
    'request_id' => $updateRequestId,
    'filename' => 'publisher-status.md',
    'content' => $updatedMarkdown,
], JSON_UNESCAPED_UNICODE), $https);
publisherStatusAssert($updateReceived->status === 201, 'Publisher-managed article update must be accepted into inbox');

$updateStatus = $api->handle('GET', [
    'X-Tomos-Token' => $token,
    'X-Tomos-Action' => 'status',
    'X-Tomos-Request-Id' => $updateRequestId,
], '', $https);
publisherStatusAssert(($updateStatus->payload['state'] ?? '') === 'published', 'Publisher-managed article must auto-update');
publisherStatusAssert(
    strpos((string) file_get_contents($contentDir . DIRECTORY_SEPARATOR . 'publisher-status.md'), 'Updated body.') !== false,
    'Publisher-managed article content must be updated'
);

$manualExistingPath = $contentDir . DIRECTORY_SEPARATOR . 'manual-existing.md';
file_put_contents($manualExistingPath, "---\ntitle: Manual existing\n---\n# Manual existing\n\nManual body.\n");
$manualRequestId = 'publisher-status-manual-0003';
$manualReceived = $api->handle('POST', $authHeaders, json_encode([
    'request_id' => $manualRequestId,
    'filename' => 'manual-existing.md',
    'content' => "---\ntitle: Manual existing\n---\n# Manual existing\n\nPublisher overwrite attempt.\n",
], JSON_UNESCAPED_UNICODE), $https);
publisherStatusAssert($manualReceived->status === 201, 'Publisher conflict candidate must be received');
$manualStatus = $api->handle('GET', [
    'X-Tomos-Token' => $token,
    'X-Tomos-Action' => 'status',
    'X-Tomos-Request-Id' => $manualRequestId,
], '', $https);
publisherStatusAssert(($manualStatus->payload['state'] ?? '') === 'needs_attention', 'unmanaged existing article must not be auto-updated');
publisherStatusAssert(
    strpos((string) file_get_contents($manualExistingPath), 'Manual body.') !== false,
    'unmanaged existing article must remain unchanged'
);

$draftRequestId = 'publisher-status-draft-0004';
$draft = $api->handle('POST', $authHeaders, json_encode([
    'request_id' => $draftRequestId,
    'filename' => 'publisher-draft.md',
    'content' => "---\ntitle: Draft\ndraft: true\n---\n# Draft\n",
], JSON_UNESCAPED_UNICODE), $https);
publisherStatusAssert($draft->status === 201, 'draft Publisher request must be accepted');

$draftStatus = $api->handle('GET', [
    'X-Tomos-Token' => $token,
    'X-Tomos-Action' => 'status',
    'X-Tomos-Request-Id' => $draftRequestId,
], '', $https);
publisherStatusAssert(($draftStatus->payload['state'] ?? '') === 'draft', 'draft status must be final and explicit');
publisherStatusAssert(!is_file($contentDir . DIRECTORY_SEPARATOR . 'publisher-draft.md'), 'draft must not be published');

$invalid = $api->handle('POST', $authHeaders, json_encode([
    'request_id' => 'bad',
    'filename' => 'invalid-request.md',
    'content' => "# Invalid\n",
]), $https);
publisherStatusAssert($invalid->status === 400, 'invalid request_id must be rejected');

$legacy = $api->handle('POST', $authHeaders, json_encode([
    'filename' => 'legacy-publisher.md',
    'content' => "# Legacy\n",
]), $https);
publisherStatusAssert($legacy->status === 201, 'legacy Publisher request without request_id must remain supported');
publisherStatusAssert(!isset($legacy->payload['request_id']), 'legacy response must not invent request_id');

echo "publisher_status_check: OK\n";
