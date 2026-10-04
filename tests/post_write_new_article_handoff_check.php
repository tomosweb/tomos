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
checkNewArticleHandoff(strpos($handoff, 'event.source === writeImportSource') !== false, 'Post receiver must validate the opener window');
checkNewArticleHandoff(strpos($handoff, 'write:publish-document') !== false, 'Post receiver must handle a new article document');
checkNewArticleHandoff(strpos($handoff, 'tomos:publish-ready') !== false && strpos($handoff, 'tomos:publish-ack') !== false, 'Post receiver must handshake and acknowledge an import');
checkNewArticleHandoff(strpos($handoff, 'TomosPostImportMarkdown') !== false, 'Post receiver must use the existing markdown importer');
checkNewArticleHandoff(strpos($postIndex, "PostAuthReturnTo::normalize('/post/?write_import=1&session=") !== false, 'Post must retain a validated import route for login');
checkNewArticleHandoff(strpos($authGate, "write_import") !== false, 'Login page must carry the direct import route into its form');
checkNewArticleHandoff(strpos($authReturnTo, "write_import=1&session=") !== false, 'Auth return route must allow only the new import marker and session');

echo "post_write_new_article_handoff_check: bounded receiver and login continuation passed\n";
