<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = readSource($root . '/post/index.php');
$authGate = readSource($root . '/post/auth-gate.php');
$securityCss = readSource($root . '/post/assets/tomos-post-security.css');
$writeHandoff = readSource($root . '/post/assets/write-handoff.js');

assertContains($index, 'prefers-reduced-motion: reduce', 'Post upload must detect reduced motion');
assertContains($index, 'imageMatchStatus.scrollIntoView({ behavior: scrollBehavior', 'missing-image scroll must use the reduced-motion behavior');
assertContains($writeHandoff, 'prefers-reduced-motion: reduce', 'Write handoff must detect reduced motion');
assertContains($writeHandoff, 'scrollIntoView({ behavior: scrollBehavior', 'Write return scroll must use the reduced-motion behavior');
assertNotContains($index, 'scrollIntoView({ behavior: "smooth"', 'Post upload must not hard-code smooth scrolling');
assertNotContains($writeHandoff, 'scrollIntoView({ behavior: "smooth"', 'Write handoff must not hard-code smooth scrolling');

$open = strpos($writeHandoff, 'window.open(');
$confirm = strpos($writeHandoff, 'window.confirm(');
$navigate = strpos($writeHandoff, 'writeWindow.location.href =');
assertTrue($open !== false && $confirm !== false && $navigate !== false && $open < $confirm && $confirm < $navigate, 'Write handoff popup order must remain open, confirm, navigate');

assertContains($authGate, 'autocomplete="current-password" required', 'Auth gate passphrase input must use native required validation');
assertContains($index, 'autocomplete="current-password" required', 'Post authentication fields must use native required validation');
assertContains($authGate, 'button:focus-visible,.button:focus-visible,input[type=password]:focus-visible', 'Auth gate controls must have focus-visible styling');
assertContains($authGate, 'rgba(164,74,29,0.28)', 'Auth gate focus styling must reuse the existing Tomos focus color');
assertContains($securityCss, 'button:focus-visible,.button:focus-visible,input[type=password]:focus-visible,input[type=text]:focus-visible', 'Shared security controls must have focus-visible styling');
assertContains($securityCss, 'rgba(164,74,29,.28)', 'Shared security focus styling must reuse the existing Tomos focus color');
foreach (['PostRateLimiter', 'PostAuthRememberToken', 'PostPassword::verify', 'rememberCurrentBrowser()', 'passkey'] as $authBoundary) {
    assertContains($authGate, $authBoundary, 'Auth boundary marker must remain: ' . $authBoundary);
}

assertContains($authGate, '<div class="errors" role="alert">', 'Auth gate errors must be announced as blocking errors');
assertContains($authGate, '<div class="notice" role="status" aria-live="polite">', 'Auth gate warnings must be announced politely');
assertContains($index, '<div class="errors" role="alert">', 'Post errors must be announced as blocking errors');
assertContains($index, '<div class="success" role="status" aria-live="polite">', 'Post success messages must be announced politely');
assertContains($index, '<div class="notice" role="status" aria-live="polite"><strong>注意</strong>', 'Post warnings must be announced politely');
$uploadResultStart = strpos($index, 'function renderUploadResult(');
$uploadResultEnd = strpos($index, 'function renderUploadConflict(', $uploadResultStart === false ? 0 : $uploadResultStart);
assertTrue($uploadResultStart !== false && $uploadResultEnd !== false, 'Upload result boundaries must be present');
$uploadResult = substr($index, $uploadResultStart, $uploadResultEnd - $uploadResultStart);
assertNotContains($uploadResult, 'aria-live=', 'Interactive upload result must not be a live region');
assertNotContains($uploadResult, 'role="status"', 'Interactive upload result must not be a status live region');

assertContains($index, 'id="post-withdraw-confirmation"', 'Withdraw confirmation must have a stable context container');
assertContains($index, '<h3>取り下げる記事</h3>', 'Withdraw confirmation must identify the target article');
assertContains($index, 'id="post-withdraw-complete"', 'Withdraw completion must have a stable context container');
assertContains($index, '$context->title', 'Withdraw completion must retain the article title');
assertContains($index, '$context->contentPath', 'Withdraw completion must retain the article storage context');
$withdrawResultCall = strpos($index, 'renderWithdrawResult([], $withdrawResult');
$publishedList = strpos($index, 'echo \'<div class="editable-results">\';', $withdrawResultCall === false ? 0 : $withdrawResultCall);
assertTrue($withdrawResultCall !== false && $publishedList !== false && $withdrawResultCall < $publishedList, 'Withdraw completion context must appear before the published list');
foreach (['PostWithdraw', 'name="_token"', 'trash', 'PostContentResolver'] as $withdrawSafetyMarker) {
    assertContains($index, $withdrawSafetyMarker, 'Withdraw safety marker must remain: ' . $withdrawSafetyMarker);
}

assertContains($index, "if (!empty(\$_SESSION['tomos_post_authenticated']) && \$activeSection === 'upload')", 'Daily message must be restricted to authenticated Upload');
assertContains($index, 'renderTomosDailyMessage();', 'Daily message must be rendered from the normal Post page');
assertContains($index, "\$_SESSION['tomos_post_daily_message_shown']", 'Daily message must use a session shown flag');
assertContains($index, '<p class="tomos-message">', 'Daily message must use the quiet text presentation');
foreach (['小さく書いて、すぐ届ける。', '書いたものは、自分の場所に残ります。', '公開したあとも、いつでも直せます。'] as $message) {
    assertContains($index, $message, 'Temporary Tomos Message is missing: ' . $message);
}
$messageStart = strpos($index, 'function renderTomosDailyMessage(');
$messageEnd = strpos($index, 'function passkeyLoginAvailable(', $messageStart === false ? 0 : $messageStart);
assertTrue($messageStart !== false && $messageEnd !== false, 'Daily message function boundaries must be present');
$messageFunction = substr($index, $messageStart, $messageEnd - $messageStart);
assertNotContains($messageFunction, 'aria-live=', 'Daily message must not be announced as a notification');
assertNotContains($messageFunction, 'window.fetch', 'Daily message must not use external browser I/O');
assertNotContains($messageFunction, 'curl_', 'Daily message must not use external server I/O');
$messageOutput = strpos($messageFunction, 'echo \'<p class="tomos-message">\'');
$messageFlag = strpos($messageFunction, "\$_SESSION['tomos_post_daily_message_shown'] = true;");
assertTrue($messageOutput !== false && $messageFlag !== false && $messageOutput < $messageFlag, 'Daily message shown flag must be set after rendering');

echo "daily_use_ux_structure_check: PASS\n";

function readSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('source could not be read: ' . $path);
    }
    return $source;
}

function assertContains(string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) === false) {
        throw new RuntimeException($message);
    }
}

function assertNotContains(string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) !== false) {
        throw new RuntimeException($message);
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
