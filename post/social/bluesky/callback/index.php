<?php

declare(strict_types=1);

session_start();

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__, 4) . '/core/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$rootDir = dirname(__DIR__, 4);
$configPath = $rootDir . '/config.php';
$config = is_file($configPath) ? require $configPath : [];
$config = is_array($config) ? $config : [];
$basePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
$publicPath = static function (string $path) use ($basePath): string {
    $prefix = '/' . trim($basePath, '/');
    if ($prefix === '/') {
        $prefix = '';
    }
    return preg_replace('#/+#', '/', $prefix . '/' . ltrim($path, '/')) ?: '/';
};

$authenticated = !empty($_SESSION['tomos_post_authenticated']);
if (!$authenticated && $config !== []) {
    $remember = new Tomos\PostAuthRememberToken($config, $rootDir);
    $remember->restoreSession();
    $authenticated = !empty($_SESSION['tomos_post_authenticated']);
}
if (!$authenticated) {
    header('Location: ' . $publicPath('post/security/') . '?return_to=' . rawurlencode('/post/social/bluesky/'));
    exit;
}

$error = (string) ($_GET['error'] ?? '');
if ($error !== '') {
    header('Location: ' . $publicPath('post/social/bluesky/') . '?oauth=denied');
    exit;
}

$state = (string) ($_GET['state'] ?? '');
$code = (string) ($_GET['code'] ?? '');
$issuer = (string) ($_GET['iss'] ?? '');

try {
    (new Tomos\BlueskyOAuthFlow($config, $rootDir))->complete($state, $code, $issuer);
    $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
    header('Location: ' . $publicPath('post/social/bluesky/') . '?oauth=connected');
    exit;
} catch (Throwable $exception) {
    header('Location: ' . $publicPath('post/social/bluesky/') . '?oauth=failed');
    exit;
}
