<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/post/index.php');
$gate = (string) file_get_contents($root . '/post/auth-gate.php');
$postHtaccess = (string) file_get_contents($root . '/post/.htaccess');
$rootHtaccess = (string) file_get_contents($root . '/.htaccess');
$distribution = (string) file_get_contents($root . '/tools/required-distribution-files.txt');
$installed = (string) file_get_contents($root . '/core/required-installed-files.txt');

function checkAuthWall(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$remember = strpos($index, '$authRemember->restoreSession();');
$preview = strpos($index, '$inboxPreviewPath =');
$postApi = strpos($index, '$postApi =');
$gateCall = strpos($index, "require __DIR__ . '/auth-gate.php';");

checkAuthWall($remember !== false && $preview !== false && $postApi !== false && $gateCall !== false, 'auth boundary markers exist');
checkAuthWall($remember < $preview && $preview < $postApi && $postApi < $gateCall, 'Remember and special contracts precede the common auth wall');
checkAuthWall(strpos($gate, 'session_start(') === false, 'gate does not bootstrap a session');
checkAuthWall(strpos($gate, "require __DIR__ . '/index.php';") === false, 'gate never delegates back to index');
checkAuthWall(strpos($gate, 'PostRateLimiter') !== false, 'rate limiting is preserved');
checkAuthWall(strpos($gate, 'PostPassword::verify') !== false, 'passphrase verification is preserved');
checkAuthWall(strpos($gate, 'rememberCurrentBrowser()') !== false, 'Remember is preserved');
checkAuthWall(strpos($gate, 'forgetCurrentBrowser()') !== false, 'logout is preserved');
checkAuthWall(strpos($gate, 'PostAuthReturnTo::normalize') !== false && strpos($gate, 'PostAuthReturnTo::url') !== false, 'return_to is preserved');
checkAuthWall(strpos($gate, '/post/passkey/login/') !== false, 'passkey route is preserved');
checkAuthWall(strpos($postHtaccess, 'RewriteEngine') === false && strpos($postHtaccess, 'RewriteCond') === false && strpos($postHtaccess, 'RewriteRule') === false, 'post/.htaccess has no rewrite routing');
checkAuthWall(stripos($postHtaccess, 'referer') === false, 'post/.htaccess has no Referer dependency');
checkAuthWall(strpos($rootHtaccess, 'post/auth-gate.php') === false, 'root .htaccess does not own auth wall');
checkAuthWall(preg_match('/^post\/\.htaccess$/m', $distribution) === 1, 'post/.htaccess is a distribution requirement');
checkAuthWall(preg_match('/^post\/\.htaccess$/m', $installed) === 1, 'post/.htaccess is an installed runtime requirement');
checkAuthWall(preg_match('/^post\/auth-gate\.php$/m', $distribution) === 1 && preg_match('/^post\/auth-gate\.php$/m', $installed) === 1, 'PHP auth component remains required');
checkAuthWall(!is_file($root . '/post/app.php'), 'Post body was not split into a new web endpoint');

echo "post_auth_wall_check: PASS\n";
