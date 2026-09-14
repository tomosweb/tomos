<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = readSource($root . '/post/index.php');
$authGate = readSource($root . '/post/auth-gate.php');
$securityCss = readSource($root . '/post/assets/tomos-post-security.css');
$writeHandoff = readSource($root . '/post/assets/write-handoff.js');
$distribution = readSource($root . '/tools/required-distribution-files.txt');
$installed = readSource($root . '/core/required-installed-files.txt');

assertContains($index, 'prefers-reduced-motion: reduce', 'Post upload must detect reduced motion');
assertContains($index, 'imageMatchStatus.scrollIntoView({ behavior: scrollBehavior', 'missing-image scroll must use the reduced-motion behavior');
assertContains($writeHandoff, 'prefers-reduced-motion: reduce', 'Write handoff must detect reduced motion');
assertContains($writeHandoff, 'scrollIntoView({ behavior: scrollBehavior', 'Write return scroll must use the reduced-motion behavior');
assertNotContains($index, 'scrollIntoView({ behavior: "smooth"', 'Post upload must not hard-code smooth scrolling');
assertNotContains($writeHandoff, 'scrollIntoView({ behavior: "smooth"', 'Write handoff must not hard-code smooth scrolling');

$confirm = strpos($writeHandoff, 'window.confirm(');
assertContains($writeHandoff, 'const approved = window.confirm(', 'Write handoff must require approval before opening Tomos Write');
assertContains($writeHandoff, 'if (!approved) return;', 'Write handoff approval rejection must stop the launch');
$open = strpos($writeHandoff, 'writeWindow = window.open(`${WRITE_URL}#${fragment.toString()}`, "_blank");');
assertTrue($confirm !== false && $open !== false && $confirm < $open, 'Write handoff must confirm before opening the final Tomos Write URL');
assertNotContains($writeHandoff, 'window.open("about:blank", "_blank")', 'Write handoff must not reserve an about:blank popup');
assertNotContains($writeHandoff, 'reservedWindow', 'Write handoff must not retain reserved popup state');
assertNotContains($writeHandoff, 'writeWindow.location.href = `${WRITE_URL}#${fragment.toString()}`;', 'Write handoff must not navigate a reserved popup after approval');

assertContains($authGate, 'autocomplete="current-password" required', 'Auth gate passphrase input must use native required validation');
assertContains($index, 'autocomplete="current-password" required', 'Post authentication fields must use native required validation');
assertContains($authGate, 'button:focus-visible,.button:focus-visible,input[type=password]:focus-visible', 'Auth gate controls must have focus-visible styling');
assertContains($authGate, 'rgba(164,74,29,0.28)', 'Auth gate focus styling must reuse the existing Tomos focus color');
assertContains($securityCss, 'button:focus-visible,.button:focus-visible,input[type=password]:focus-visible,input[type=text]:focus-visible', 'Shared security controls must have focus-visible styling');
assertContains($securityCss, 'rgba(164,74,29,.28)', 'Shared security focus styling must reuse the existing Tomos focus color');
foreach (['PostRateLimiter', 'PostPassword::verify', 'rememberCurrentBrowser()', 'passkey'] as $authBoundary) {
    assertContains($authGate, $authBoundary, 'Auth boundary marker must remain: ' . $authBoundary);
}
assertContains($index, 'PostAuthRememberToken', 'Post auth remember-token service must remain in the current v0.7.3 bootstrap');
assertContains($authGate, '$authRemember', 'Current v0.7.3 auth component must use the initialized remember-token service');

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

assertContains($index, "!empty(\$_SESSION['tomos_post_authenticated'])", 'Daily message must require Post authentication');
assertContains($index, "\$activeSection === 'upload'", 'Daily message must be restricted to Upload');
assertContains($index, 'TomosMessageRecurrence::isEligible(', 'Daily message must use the browser recurrence timestamp');
assertContains($index, 'setTomosDailyMessageCookie(', 'Daily message must record a browser recurrence timestamp before rendering');
assertNotContains($index, 'tomos_post_daily_message_shown', 'Daily message must not use a session shown flag');
assertContains($index, 'renderTomosDailyMessage();', 'Daily message must be rendered from the normal Post page');
assertContains($index, '$showTomosDailyMessage', 'Daily message display must be decided before the page head is rendered');
assertContains($index, 'https://fonts.googleapis.com/css2?family=Klee+One&display=swap', 'Daily message response must load Klee One');
assertNotContains($index, 'Tomos Writeなどで作成したMarkdownファイルをTomosに投稿し、必要に応じて投稿済みページをWeb上から外します。', 'Daily Post description must be removed');
assertContains($index, '<div class="tomos-message">', 'Daily message must use the letter card presentation');
assertContains($index, '<p class="tomos-message-text">', 'Daily message text must remain the only message copy');
assertContains($index, '<img class="tomos-message-mark" src="assets/tomos-message-mark.png" alt="" aria-hidden="true">', 'Daily message must use the original local PNG as a decorative image');
assertNotContains($index, '<svg class="tomos-message-mark"', 'Daily message must not redraw the official mark as inline SVG');
assertContains($index, 'font-family:"Klee One"', 'Daily message must use Klee One with a local fallback');
assertContains($index, 'font-size:clamp(1rem,1.35vw,1.125rem)', 'Daily message font size must remain restrained and responsive');
assertContains($index, 'background:#fffaf2', 'Daily message must use a subtle warm cream surface');
assertContains($index, 'box-shadow:0 2px 8px rgba(47,47,47,.05)', 'Daily message shadow must remain subtle');
assertContains($index, 'height:2.5rem;width:auto', 'Daily message mark must preserve the original aspect ratio at 40px desktop height');
assertContains($index, 'height:2rem;width:auto', 'Daily message mark must preserve the original aspect ratio at 32px mobile height');
assertNotContains($index, 'tomos-message-airplane', 'Paper airplane decoration must be removed');
assertNotContains($index, 'stroke-dasharray', 'Daily message mark must not use a dotted trajectory');
$messageCall = strpos($index, 'renderTomosDailyMessage();');
$navCall = strpos($index, 'renderSectionNav($activeSection, $publicBasePath);');
assertTrue($messageCall !== false && $navCall !== false && $messageCall < $navCall, 'Daily message must appear before Post navigation');
$messageStart = strpos($index, 'function renderTomosDailyMessage(');
$messageEnd = strpos($index, 'function passkeyLoginAvailable(', $messageStart === false ? 0 : $messageStart);
assertTrue($messageStart !== false && $messageEnd !== false, 'Daily message function boundaries must be present');
$messageFunction = substr($index, $messageStart, $messageEnd - $messageStart);
$corpusStart = strpos($messageFunction, "\$messages = [");
$corpusEnd = strpos($messageFunction, '    ];', $corpusStart === false ? 0 : $corpusStart);
assertTrue($corpusStart !== false && $corpusEnd !== false, 'Daily message corpus boundaries must be present');
$corpusSource = substr($messageFunction, $corpusStart, $corpusEnd - $corpusStart);
preg_match_all("/^        '([^']*)',$/mu", $corpusSource, $corpusMatches);
$corpus = $corpusMatches[1] ?? [];
assertTrue(count($corpus) === 125, 'Daily message corpus must contain exactly the 125 Human Review approved messages');
assertTrue(hash('sha256', implode("\n", $corpus)) === '13d3d7ce961d6ed2ea4b258d70b58fc9c852baa61b68a2e519f68769399a01cd', 'Daily message corpus must exactly match the Human Review approved text and order');
foreach ([
    'うまく書けない日も、投稿してもいい。',
    '書いた日の自分も、未来の自分も読み返せます。',
    '自分さえ読めば良い。の気分で投稿するか。',
    '今日の小さな気づきを、そのまま私のSmall Webへ。',
    '今日の投稿が、明日の私の居場所になります。',
] as $message) {
    assertContains($messageFunction, $message, 'Human Review approved Tomos Message is missing: ' . $message);
}
assertNotContains($messageFunction, 'aria-live=', 'Daily message must not be announced as a notification');
assertNotContains($messageFunction, 'window.fetch', 'Daily message must not use external browser I/O');
assertNotContains($messageFunction, 'curl_', 'Daily message must not use external server I/O');
assertNotContains($messageFunction, '<svg', 'Daily message mark must not be redrawn as inline SVG');
assertNotContains($messageFunction, 'f0dfc6', 'Daily message mark must not use the rejected ivory candle treatment');
assertNotContains($messageFunction, 'stroke=', 'Daily message mark must not contain SVG stroke instructions');
$assetInfo = @getimagesize($root . '/post/assets/tomos-message-mark.png');
assertTrue(is_array($assetInfo) && ($assetInfo[0] ?? 0) === 651 && ($assetInfo[1] ?? 0) === 1291, 'Daily message PNG dimensions must retain the wider flame surround crop');
$assetBytes = readSource($root . '/post/assets/tomos-message-mark.png');
assertTrue(($assetInfo['mime'] ?? '') === 'image/png' && strlen($assetBytes) >= 26 && ord($assetBytes[25]) === 6, 'Daily message PNG must be RGBA');
assertContains($distribution, "post/assets/tomos-message-mark.png", 'Daily message PNG must be included in distribution packaging');
assertContains($installed, "post/assets/tomos-message-mark.png", 'Daily message PNG must be included in installed runtime files');
$messageOutput = strpos($messageFunction, 'echo \'<div class="tomos-message">\'');
assertTrue($messageOutput !== false, 'Daily message card must still render from the frozen presentation function');

$recurrence = readSource($root . '/core/TomosMessageRecurrence.php');
assertContains($recurrence, "public const INTERVAL_SECONDS = 21600;", 'Daily message recurrence must use the approved six-hour interval');
assertContains($recurrence, "public const COOKIE_NAME = 'tomos_post_daily_message_at';", 'Daily message recurrence must use a dedicated first-party cookie');
assertContains($recurrence, "'httponly' => true", 'Daily message recurrence cookie must be HttpOnly');
assertContains($recurrence, "'samesite' => 'Lax'", 'Daily message recurrence cookie must be SameSite Lax');
assertNotContains($recurrence, 'localStorage', 'Daily message recurrence must not require localStorage');
assertNotContains($recurrence, 'curl_', 'Daily message recurrence must not use external communication');

$csp = readSource($root . '/core/ContentSecurityPolicy.php');
assertContains($csp, "style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com;", 'CSP must allow only the Google Fonts hosts needed by the message');
assertNotContains($csp, 'style-src *', 'CSP style sources must not use a wildcard');
assertNotContains($csp, 'font-src *', 'CSP font sources must not use a wildcard');

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
