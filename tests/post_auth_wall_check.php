<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$gatePath = $root . '/post/auth-gate.php';
$indexPath = $root . '/post/index.php';
$htaccessPath = $root . '/.htaccess';
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

$gate = (string) file_get_contents($gatePath);
$htaccess = (string) file_get_contents($htaccessPath);
$distributionList = (string) file_get_contents($distributionListPath);
$installedList = (string) file_get_contents($installedListPath);

checkAuthWall(strpos($htaccess, 'RewriteRule ^post/?$ post/auth-gate.php [L]') !== false, 'canonical /post/ entry is gated');
checkAuthWall(strpos($htaccess, 'RewriteRule ^post/index\\.php$ post/auth-gate.php [L]') !== false, 'direct post/index.php entry is gated');

checkAuthWall(strpos($gate, "require __DIR__ . '/index.php';") !== false, 'authenticated requests delegate to stable post/index.php');
checkAuthWall(strpos($gate, 'PostAuthRememberToken') !== false, 'remember authentication is reused');
checkAuthWall(strpos($gate, 'PostRateLimiter') !== false, 'existing rate limiter is reused');
checkAuthWall(strpos($gate, 'PostPassword::verify') !== false, 'existing passphrase verifier is reused');
checkAuthWall(strpos($gate, 'rememberCurrentBrowser()') !== false, 'existing 30-day remember behavior is reused');
checkAuthWall(strpos($gate, '/post/passkey/login/') !== false, 'existing passkey login route is reused');
checkAuthWall(strpos($gate, 'autocomplete="current-password"') !== false, 'browser password manager remains supported');
checkAuthWall(strpos($gate, "$action === 'logout'") !== false, 'existing logout action is handled at the authentication boundary');
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

checkAuthWall(preg_match('/^post\/auth-gate\.php$/m', $distributionList) === 1, 'auth wall is required in distribution');
checkAuthWall(preg_match('/^post\/auth-gate\.php$/m', $installedList) === 1, 'auth wall is required in installed runtime');

echo "post_auth_wall_check: PASS\n";
