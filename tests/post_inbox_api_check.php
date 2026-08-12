<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostInboxApi.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-inbox-api-' . bin2hex(random_bytes(6));
$content = $root . DIRECTORY_SEPARATOR . 'content';
$cache = $root . DIRECTORY_SEPARATOR . 'cache';
$inboxPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'inbox';
foreach ([$content, $cache, $inboxPath] as $directory) {
    if (!mkdir($directory, 0775, true)) {
        throw new RuntimeException('test directory could not be created');
    }
}

$token = 'test-' . bin2hex(random_bytes(16));
$config = [
    'site' => ['url' => 'https://example.test/', 'timezone' => 'Asia/Tokyo'],
    'paths' => ['content_dir' => $content, 'cache_dir' => $cache, 'inbox_dir' => $inboxPath],
    'security' => ['inbox_api_token_hash' => Tomos\PostPassword::hash($token)],
];
$api = new Tomos\PostInboxApi(new Tomos\PostInbox($config, $root), $config);
$https = ['HTTPS' => 'on', 'SERVER_PORT' => '443'];
$headers = ['X-Tomos-Token' => $token];

$valid = $api->handle('POST', $headers, json_encode([
    'filename' => '日本語.md',
    'content' => "---\ntitle: API test\n---\n# API test\n",
], JSON_UNESCAPED_UNICODE), $https);
if ($valid->status !== 201 || empty($valid->payload['ok']) || !is_file($inboxPath . DIRECTORY_SEPARATOR . '日本語.md')) {
    throw new RuntimeException('valid Markdown was not accepted');
}
if (file_get_contents($inboxPath . DIRECTORY_SEPARATOR . '日本語.md') !== "---\ntitle: API test\n---\n# API test\n") {
    throw new RuntimeException('Front Matter or body was changed');
}

$duplicate = $api->handle('POST', $headers, json_encode(['filename' => '日本語.md', 'content' => '# duplicate'], JSON_UNESCAPED_UNICODE), $https);
if ($duplicate->status !== 409) {
    throw new RuntimeException('duplicate file was not rejected');
}

if ($api->handle('POST', ['X-Tomos-Token' => 'wrong'], '{}', $https)->status !== 401) {
    throw new RuntimeException('invalid token was not rejected');
}
if ($api->handle('POST', [], '{}', $https)->status !== 401) {
    throw new RuntimeException('missing token was not rejected');
}
if ($api->handle('POST', $headers, json_encode(['filename' => '../escape.md', 'content' => '# unsafe']), $https)->status !== 400) {
    throw new RuntimeException('path traversal was not rejected');
}
if ($api->handle('POST', $headers, json_encode(['filename' => 'unsafe.php', 'content' => '# unsafe']), $https)->status !== 400) {
    throw new RuntimeException('unsupported extension was not rejected');
}
if ($api->handle('POST', $headers, '{"filename":"invalid.md","content":"' . "\xFF" . '"}', $https)->status !== 400) {
    throw new RuntimeException('invalid UTF-8 was not rejected');
}
if ($api->handle('POST', $headers, '{}', ['SERVER_PORT' => '80'])->status !== 400) {
    throw new RuntimeException('non-HTTPS request was not rejected');
}
if ($api->handle('GET', $headers, '', $https)->status !== 200) {
    throw new RuntimeException('connection test did not succeed');
}

echo "post_inbox_api_check: OK\n";
