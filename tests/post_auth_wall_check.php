<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$gatePath = $root . '/post/auth-gate.php';
$indexPath = $root . '/post/index.php';
$postHtaccessPath = $root . '/post/.htaccess';
$rootHtaccessPath = $root . '/.htaccess';
$distributionListPath = $root . '/tools/required-distribution-files.txt';
$installedListPath = $root . '/core/required-installed-files.txt';

function checkAuthWall(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

checkAuthWall(is_file($gatePath), 'post/auth-gate.php exists');
checkAuthWall(is_file($indexPath), 'stable post/index.php exists');
checkAuthWall(is_file($postHtaccessPath), 'post/.htaccess exists');

$gate = (string) file_get_contents($gatePath);
$index = (string) file_get_contents($indexPath);
$postHtaccess = (string) file_get_contents($postHtaccessPath);
$rootHtaccess = (string) file_get_contents($rootHtaccessPath);
$distributionList = (string) file_get_contents($distributionListPath);
$installedList = (string) file_get_contents($installedListPath);

checkAuthWall(strpos($index, 'function renderPage(') !== false, 'stable Tomos Post body remains in post/index.php');
checkAuthWall(strpos($index, 'PostUpload') !== false, 'stable Tomos Post features remain in post/index.php');
checkAuthWall(strpos($gate, "require __DIR__ . '/index.php';") !== false, 'authenticated requests delegate to unchanged post/index.php');
checkAuthWall(strpos($rootHtaccess, 'post/auth-gate.php') === false, 'auth wall does not depend on protected root .htaccess');
checkAuthWall(strpos($postHtaccess, 'RewriteCond %{HTTP_REFERER}') !== false, 'nested Tomos Post referrals are detected before root auth routing');
checkAuthWall(strpos($postHtaccess, 'RewriteRule ^(.*)$ %1/post/$1 [R=302,L,NE]') !== false, 'nested Tomos Post referrals stay in their install path');
checkAuthWall(strpos($postHtaccess, 'RewriteRule ^$ auth-gate.php [L]') !== false, 'post directory entry is routed through auth wall');
checkAuthWall(strpos($postHtaccess, 'RewriteRule ^index\\.php$ auth-gate.php [L]') !== false, 'direct post/index.php is routed through auth wall');

checkAuthWall(strpos($gate, 'PostAuthRememberToken') !== false, 'remember authentication is reused');
checkAuthWall(strpos($gate, 'PostRateLimiter') !== false, 'existing rate limiter is reused');
checkAuthWall(strpos($gate, 'PostPassword::verify') !== false, 'existing passphrase verifier is reused');
checkAuthWall(strpos($gate, 'rememberCurrentBrowser()') !== false, 'existing 30-day remember behavior is reused');
checkAuthWall(strpos($gate, '/post/passkey/login/') !== false, 'existing passkey login route is reused');
checkAuthWall(strpos($gate, 'autocomplete="current-password"') !== false, 'browser password manager remains supported');
checkAuthWall(strpos($gate, "\$action === 'logout'") !== false, 'existing logout action is handled at the authentication boundary');
checkAuthWall(strpos($gate, 'forgetCurrentBrowser()') !== false, 'logout clears the existing browser authentication state');

checkAuthWall(strpos($gate, "header('Cache-Control: no-store, private')") !== false, 'auth wall is not cached');
checkAuthWall(strpos($gate, "header('X-Robots-Tag: noindex, nofollow')") !== false, 'auth wall is noindex/nofollow');
checkAuthWall(strpos($gate, "header('X-Content-Type-Options: nosniff')") !== false, 'auth wall sends nosniff');

checkAuthWall(strpos($gate, "'post_api'") !== false, 'existing Post API is delegated unchanged');
checkAuthWall(strpos($gate, "'preview_inbox'") !== false, 'existing inbox preview protection is delegated unchanged');
checkAuthWall(strpos($gate, "'preview_draft'") !== false, 'existing draft preview protection is delegated unchanged');

foreach (['PostUpload', 'PostDrafts', 'PostPublished', 'PostWithdraw', 'ThemePackageInstaller', 'SiteSettingsConfigWriter'] as $forbiddenPostFeature) {
    checkAuthWall(strpos($gate, $forbiddenPostFeature) === false, 'auth wall does not implement Tomos Post feature: ' . $forbiddenPostFeature);
}

foreach (['post/index.php', 'post/.htaccess', 'post/auth-gate.php'] as $requiredPath) {
    checkAuthWall(preg_match('/^' . preg_quote($requiredPath, '/') . '$/m', $distributionList) === 1, $requiredPath . ' is required in distribution');
    checkAuthWall(preg_match('/^' . preg_quote($requiredPath, '/') . '$/m', $installedList) === 1, $requiredPath . ' is required in installed runtime');
}

checkAuthWall(strpos($distributionList, 'post/app.php') === false, 'distribution does not split the stable Post body');
checkAuthWall(strpos($installedList, 'post/app.php') === false, 'installed runtime does not split the stable Post body');

echo "post_auth_wall_check: PASS\n";
