<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$gatePath = $root . '/post/auth-gate.php';
$indexPath = $root . '/post/index.php';
$appPath = $root . '/post/app.php';
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
checkAuthWall(is_file($indexPath), 'post/index.php entrypoint exists');
checkAuthWall(is_file($appPath), 'stable Post application body exists as post/app.php');
checkAuthWall(is_file($postHtaccessPath), 'post/.htaccess exists');

$gate = (string) file_get_contents($gatePath);
$index = (string) file_get_contents($indexPath);
$postHtaccess = (string) file_get_contents($postHtaccessPath);
$rootHtaccess = (string) file_get_contents($rootHtaccessPath);
$distributionList = (string) file_get_contents($distributionListPath);
$installedList = (string) file_get_contents($installedListPath);

checkAuthWall(strpos($index, "require __DIR__ . '/auth-gate.php';") !== false, 'post/index.php enters the authentication wall');
checkAuthWall(strpos($index, 'PostUpload') === false, 'entrypoint does not implement Tomos Post features');
checkAuthWall(strpos($gate, "require __DIR__ . '/app.php';") !== false, 'authenticated requests delegate to unchanged Post application body');
checkAuthWall(strpos($rootHtaccess, 'post/auth-gate.php') === false, 'auth wall does not depend on protected root .htaccess');
checkAuthWall(strpos($postHtaccess, '<Files "app.php">') !== false, 'post/app.php has a direct HTTP access boundary');
checkAuthWall(strpos($postHtaccess, 'Require all denied') !== false, 'post/app.php is denied to direct HTTP requests');

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

foreach (['post/index.php', 'post/.htaccess', 'post/app.php', 'post/auth-gate.php'] as $requiredPath) {
    checkAuthWall(preg_match('/^' . preg_quote($requiredPath, '/') . '$/m', $distributionList) === 1, $requiredPath . ' is required in distribution');
    checkAuthWall(preg_match('/^' . preg_quote($requiredPath, '/') . '$/m', $installedList) === 1, $requiredPath . ' is required in installed runtime');
}

echo "post_auth_wall_check: PASS\n";
