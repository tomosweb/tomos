<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/TomosWriteHandoff.php';
require_once $root . '/core/PostUploadInput.php';

function canonicalAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$js = (string) file_get_contents($root . '/post/assets/write-handoff.js');
$index = (string) file_get_contents($root . '/post/index.php');

canonicalAssert(Tomos\TomosWriteHandoff::canonicalUrl() === 'https://tomoswords.org/write/', 'canonical Write URL changed');
canonicalAssert(Tomos\TomosWriteHandoff::origin() === 'https://tomoswords.org', 'canonical Write origin must derive from the URL');
canonicalAssert(strpos($index, 'Tomos\\TomosWriteHandoff::canonicalUrl()') !== false, 'Post must use the canonical helper');
canonicalAssert(strpos($index, 'data-tomos-write-url="') !== false, 'Post must expose the canonical endpoint to handoff JS');
canonicalAssert(strpos($index, 'data-tomos-markdown-max-bytes="') !== false, 'Post must expose the product Markdown limit to handoff JS');
canonicalAssert(strpos($js, 'const writeUrlValue = document.body?.dataset?.tomosWriteUrl || "";') !== false, 'handoff JS must read the server-provided endpoint');
canonicalAssert(strpos($js, 'document.body?.dataset?.tomosMarkdownMaxBytes') !== false, 'handoff JS must read the shared Markdown limit');
canonicalAssert(strpos($js, 'https://tomoswords.org') === false, 'handoff JS must not hard-code the canonical origin');
canonicalAssert(strpos($index, 'tomos_handoff_markdown') !== false, 'Post form must expose direct Markdown import state');
canonicalAssert(strpos($index, '$upload->handleContent($handoffMarkdown') !== false, 'Post return must use the common Markdown import entry');
canonicalAssert(strpos($js, 'window.TomosPostImportMarkdown') !== false, 'Post must expose a direct Markdown import entry');
canonicalAssert(strpos($js, 'tomosVersion: TOMOS_VERSION') !== false && strpos($js, 'writeVersion') !== false, 'diagnostics must identify both application versions');
canonicalAssert(strpos($js, 'new DataTransfer') === false, 'handoff return must not depend on DataTransfer');
canonicalAssert(strpos($js, 'message.transactionId') !== false, 'return ACK must bind to the transaction');
canonicalAssert(strpos($js, 'RETRY_DELAYS_MS') !== false, 'Post handoff must use bounded retries');
canonicalAssert(strpos($js, 'MAX_HANDSHAKE_MS = 30000') !== false, 'ready probing must have an explicit bounded window');
canonicalAssert(strpos($js, 'event.origin !== WRITE_ORIGIN') !== false, 'Write origin validation must remain exact');
canonicalAssert(strpos($js, 'event.source !== writeWindow') !== false, 'outbound source-window validation must remain exact');
canonicalAssert(Tomos\PostUploadInput::maxBytes() === 1048576, 'Tomos Markdown product limit must remain 1 MiB');

echo "write_handoff_canonical_contract_check: canonical endpoint, direct import, transaction, retry, and security contracts passed\n";
