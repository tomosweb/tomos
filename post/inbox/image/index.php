<?php

declare(strict_types=1);

session_start();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

if (empty($_SESSION['tomos_post_authenticated'])) {
    http_response_code(404);
    exit;
}

$rootDir = dirname(__DIR__, 3);
$configPath = $rootDir . '/config.php';
$config = is_file($configPath) ? require $configPath : [];
if (!is_array($config)) $config = [];

spl_autoload_register(static function (string $class) use ($rootDir): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) return;
    $file = $rootDir . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require_once $file;
});

$fileName = (string) ($_GET['file'] ?? '');
$imageName = strtolower((string) ($_GET['image'] ?? ''));
$files = (new Tomos\PostInbox($config, $rootDir))->stagedImageFiles($fileName);
$image = null;
foreach ((array) ($files['name'] ?? []) as $index => $name) {
    if (strtolower((string) $name) === $imageName) {
        $image = [
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'type' => $files['type'][$index] ?? '',
        ];
        break;
    }
}
$path = is_array($image) ? (string) ($image['tmp_name'] ?? '') : '';
if ($path === '' || !is_file($path) || is_link($path)) {
    http_response_code(404);
    exit;
}

$mime = (string) ($image['type'] ?? 'application/octet-stream');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
readfile($path);
