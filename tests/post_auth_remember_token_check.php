<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostAuthRememberToken.php';

use Tomos\PostAuthRememberToken;

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-auth-test-' . bin2hex(random_bytes(6));
$config = [
    'paths' => ['cache_dir' => $tmp . DIRECTORY_SEPARATOR . 'cache'],
    'site' => ['base_path' => '/local-tomos'],
];
$now = 1785900000;

try {
    $_SESSION = [];
    $_COOKIE = [];
    $unchecked = new PostAuthRememberToken($config, $tmp, $now, '/local-tomos/post/', false);
    $_SESSION['tomos_post_authenticated'] = true;
    assertTrue($unchecked->restoreSession(), 'session authentication must be accepted');
    assertSame([], glob($unchecked->storageDirectory() . DIRECTORY_SEPARATOR . '*.json') ?: [], 'unchecked authentication must not create a remembered token');

    assertTrue($unchecked->rememberCurrentBrowser(), 'remembered token must be created');
    $rawToken = (string) ($_COOKIE[PostAuthRememberToken::COOKIE_NAME] ?? '');
    assertTrue(preg_match('/\A[a-f0-9]{64}\z/', $rawToken) === 1, 'cookie must contain a random token');
    $files = glob($unchecked->storageDirectory() . DIRECTORY_SEPARATOR . '*.json') ?: [];
    assertSame(1, count($files), 'one server token record must exist');
    assertTrue(strpos(basename($files[0]), $rawToken) === false, 'raw token must not be used as the file name');
    $recordText = (string) file_get_contents($files[0]);
    assertTrue(strpos($recordText, $rawToken) === false, 'raw token must not be stored on the server');
    assertTrue(strpos($recordText, 'secret-passphrase') === false, 'passphrase must not be stored');
    $record = json_decode($recordText, true);
    assertSame(['token_hash', 'created_at', 'expires_at'], array_keys($record), 'token record must contain only the required fields');

    unset($_SESSION['tomos_post_authenticated']);
    $restored = new PostAuthRememberToken($config, $tmp, $now + 10, '/local-tomos/post/', true);
    assertTrue($restored->restoreSession(), 'valid cookie must restore the PHP session');
    assertTrue(!empty($_SESSION['tomos_post_authenticated']), 'restored session flag must be set');

    $secureOptions = $restored->cookieOptions($now + PostAuthRememberToken::LIFETIME_SECONDS);
    assertSame('/local-tomos/post/', $secureOptions['path'], 'cookie path must be limited to Tomos Post');
    assertSame(true, $secureOptions['secure'], 'public HTTPS cookie must be Secure');
    assertSame(true, $secureOptions['httponly'], 'cookie must be HttpOnly');
    assertSame('Strict', $secureOptions['samesite'], 'cookie must use SameSite Strict');
    assertSame($now + PostAuthRememberToken::LIFETIME_SECONDS, $secureOptions['expires'], 'cookie lifetime must be 30 days');
    assertSame(false, $unchecked->cookieOptions($now)['secure'], 'local HTTP override must remain testable without Secure');
    $httpsConfig = $config;
    $httpsConfig['site']['url'] = 'https://example.com/local-tomos';
    $configuredHttps = new PostAuthRememberToken($httpsConfig, $tmp, $now, '/local-tomos/post/');
    assertSame(true, $configuredHttps->cookieOptions($now)['secure'], 'configured public HTTPS URL must always enable Secure');

    unset($_SESSION['tomos_post_authenticated']);
    $_COOKIE[PostAuthRememberToken::COOKIE_NAME] = str_repeat('0', 64);
    assertSame(false, $restored->restoreSession(), 'tampered cookie must not authenticate');

    $_SESSION['tomos_post_authenticated'] = true;
    assertTrue($restored->rememberCurrentBrowser(), 'second token must be created');
    $secondToken = (string) $_COOKIE[PostAuthRememberToken::COOKIE_NAME];
    $secondPath = $restored->storageDirectory() . DIRECTORY_SEPARATOR . hash('sha256', $secondToken) . '.json';
    unlink($secondPath);
    unset($_SESSION['tomos_post_authenticated']);
    assertSame(false, $restored->restoreSession(), 'missing server record must not authenticate');

    $_SESSION['tomos_post_authenticated'] = true;
    assertTrue($restored->rememberCurrentBrowser(), 'expiring token must be created');
    unset($_SESSION['tomos_post_authenticated']);
    $expired = new PostAuthRememberToken($config, $tmp, $now + PostAuthRememberToken::LIFETIME_SECONDS + 20, '/local-tomos/post/', true);
    assertSame(false, $expired->restoreSession(), 'token older than 30 days must expire');

    $_SESSION['tomos_post_authenticated'] = true;
    assertTrue($restored->rememberCurrentBrowser(), 'logout token must be created');
    $logoutPath = $restored->storageDirectory() . DIRECTORY_SEPARATOR . hash('sha256', (string) $_COOKIE[PostAuthRememberToken::COOKIE_NAME]) . '.json';
    $restored->forgetCurrentBrowser();
    assertSame(false, is_file($logoutPath), 'logout must delete the server token');
    assertSame(false, isset($_COOKIE[PostAuthRememberToken::COOKIE_NAME]), 'logout must delete the browser cookie');
    assertSame(false, isset($_SESSION['tomos_post_authenticated']), 'logout must clear the PHP session');

    $_SESSION['tomos_post_authenticated'] = true;
    assertTrue($restored->rememberCurrentBrowser(), 'token before password change must be created');
    $restored->invalidateAll();
    assertSame([], glob($restored->storageDirectory() . DIRECTORY_SEPARATOR . '*.json') ?: [], 'password change must invalidate every remembered token');

    echo "post_auth_remember_token_check: OK\n";
} finally {
    removeTree($tmp);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($child) ? removeTree($child) : unlink($child);
    }
    rmdir($path);
}
