<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$rootDir = dirname(__DIR__, 3);
$configPath = $rootDir . '/config.php';
$config = is_file($configPath) ? require $configPath : [];
if (!is_array($config)) {
    $config = [];
}

spl_autoload_register(static function (string $class) use ($rootDir): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = $rootDir . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!is_array($headers)) {
    $headers = [];
}
if (isset($_SERVER['HTTP_X_TOMOS_TOKEN'])) {
    $headers['X-Tomos-Token'] = (string) $_SERVER['HTTP_X_TOMOS_TOKEN'];
}
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers['Authorization'] = (string) $_SERVER['HTTP_AUTHORIZATION'];
}

$api = new Tomos\PostInboxApi(new Tomos\PostInbox($config, $rootDir), $config);
$response = $api->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    array_map(static fn ($value): string => is_string($value) ? $value : '', $headers),
    (string) file_get_contents('php://input'),
    $_SERVER
);

http_response_code($response->status);
echo json_encode($response->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
