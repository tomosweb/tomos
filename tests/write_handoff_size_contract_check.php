<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/PostUploadInput.php';

function sizeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$limit = Tomos\PostUploadInput::maxBytes();
$input = (string) file_get_contents($root . '/core/PostUploadInput.php');
$inbox = (string) file_get_contents($root . '/core/PostInbox.php');
$js = (string) file_get_contents($root . '/post/assets/write-handoff.js');
$inline = (string) file_get_contents($root . '/post/index.php');
$endpoint = (string) file_get_contents($root . '/post/write-handoff.php');

sizeAssert($limit === 1048576, 'the existing Markdown product limit must remain exactly 1 MiB');
sizeAssert(1048575 <= $limit, 'Markdown below 1 MiB must be accepted by the contract');
sizeAssert(1048576 <= $limit, 'Markdown exactly 1 MiB must be accepted by the contract');
sizeAssert(1048577 > $limit, 'Markdown over 1 MiB must be rejected by the contract');
sizeAssert(strpos($js, 'document.body?.dataset?.tomosMarkdownMaxBytes') !== false, 'handoff transport must use the shared limit');
sizeAssert(strpos($inline, 'const MAX_MARKDOWN_BYTES = Number(document.body.dataset.tomosMarkdownMaxBytes || 0);') !== false, 'direct Post import must use the shared limit');
sizeAssert(strpos($endpoint, 'Tomos\\PostUploadInput::maxBytes()') !== false, 'server handoff preparation must use the product limit');
sizeAssert(strpos($js, '2 * 1024 * 1024') === false, 'Tomos handoff JS must not retain a 2 MiB success boundary');
sizeAssert(strpos($inline, '2 * 1024 * 1024') === false, 'direct Post import must not retain a 2 MiB success boundary');
sizeAssert(strpos($endpoint, '2 * 1024 * 1024') === false, 'handoff preparation must not retain a 2 MiB success boundary');
sizeAssert(strpos($input, '初期版では') === false, 'Markdown size error must use current product wording');
sizeAssert(strpos($inbox, '初期版では') === false, 'inbox Markdown size error must use current product wording');

$tmp = tempnam(sys_get_temp_dir(), 'tomos-handoff-size-');
if ($tmp === false) {
    throw new RuntimeException('temporary file could not be created');
}
try {
    foreach ([1048575, 1048576, 1048577] as $bytes) {
        file_put_contents($tmp, str_repeat('a', $bytes));
        $result = Tomos\PostUploadInput::read([
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => $tmp,
            'size' => $bytes,
            'name' => 'size-contract.md',
        ], static fn (string $path): bool => true);
        sizeAssert($result->ok === ($bytes <= $limit), 'upload acceptance must match the 1 MiB boundary');
    }
} finally {
    if (is_file($tmp)) {
        unlink($tmp);
    }
}

echo "write_handoff_size_contract_check: 1 MiB boundary, exact-limit acceptance, and over-limit rejection passed\n";
