<?php

declare(strict_types=1);

function checkNewArticleHandoff(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$handoff = file_get_contents($root . '/post/assets/write-handoff.js');
$postIndex = file_get_contents($root . '/post/index.php');
$authReturnTo = file_get_contents($root . '/core/PostAuthReturnTo.php');
$authGate = file_get_contents($root . '/post/auth-gate.php');

checkNewArticleHandoff(is_string($handoff) && strpos($handoff, 'write_import') !== false, 'Post receiver must require the new import route');
checkNewArticleHandoff(strpos($handoff, 'event.origin !== WRITE_ORIGIN') !== false, 'Post receiver must validate the Tomos Write origin');
checkNewArticleHandoff(strpos($handoff, 'event.source !== writeImportSource') !== false, 'Post receiver must validate the bound source window');
checkNewArticleHandoff(strpos($handoff, 'let writeImportSource = null;') !== false, 'Post receiver must wait for a valid probe before binding the source');
checkNewArticleHandoff(strpos($handoff, 'if (!writeImportSource) writeImportSource = event.source;') !== false, 'Post receiver must bind the first valid probe source');
checkNewArticleHandoff(strpos($handoff, 'sendWriteImportReady(writeImportSource);') === false, 'Post receiver must not send ready before receiving a probe');
checkNewArticleHandoff(strpos($handoff, 'event.source === window') !== false, 'Post receiver must reject self-originated message events');
checkNewArticleHandoff(strpos($handoff, '!transactionId') !== false, 'Post receiver must require a transaction ID');
checkNewArticleHandoff(strpos($handoff, 'write:publish-document') !== false, 'Post receiver must handle a new article document');
checkNewArticleHandoff(strpos($handoff, 'tomos:publish-ready') !== false && strpos($handoff, 'tomos:publish-ack') !== false, 'Post receiver must handshake and acknowledge an import');
checkNewArticleHandoff(strpos($handoff, 'TomosPostImportMarkdown') !== false, 'Post receiver must use the existing markdown importer');
checkNewArticleHandoff(strpos($handoff, 'mode: "new"') !== false, 'Direct Write publish handoff must mark the import as a new article');
checkNewArticleHandoff(strpos($postIndex, 'stripTomosSourceMetadata') !== false, 'New Write handoff must strip editable-source metadata before publishing');
checkNewArticleHandoff(strpos($postIndex, 'id="tomos-write-handoff-notice" class="success"') !== false, 'Post must expose a prominent Write handoff confirmation');
checkNewArticleHandoff(strpos($postIndex, 'setHandoffNotice("Tomos WriteからMarkdownを受け取りました。内容を確認して投稿してください。")') !== false, 'Post must show the new Markdown handoff confirmation prominently');
checkNewArticleHandoff(strpos($postIndex, "PostAuthReturnTo::normalize('/post/?write_import=1&session=") !== false, 'Post must retain a validated import route for login');
checkNewArticleHandoff(strpos($authGate, "write_import") !== false, 'Login page must carry the direct import route into its form');
checkNewArticleHandoff(strpos($authReturnTo, "write_import=1&session=") !== false, 'Auth return route must allow only the new import marker and session');

echo "post_write_new_article_handoff_check: bounded receiver and login continuation passed\n";
