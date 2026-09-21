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


$origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
$corsHeaders = Tomos\InboxApiCors::responseHeaders($origin, $config);
foreach ($corsHeaders as $name => $value) {
    header($name . ': ' . $value);
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    if ($origin === '' || !Tomos\InboxApiCors::isAllowed($origin, $config)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'このOriginからの接続は許可されていません。'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code(204);
    exit;
}

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
if (isset($_SERVER['HTTP_X_TOMOS_ACTION'])) {
    $headers['X-Tomos-Action'] = (string) $_SERVER['HTTP_X_TOMOS_ACTION'];
}
if (isset($_SERVER['HTTP_X_TOMOS_REQUEST_ID'])) {
    $headers['X-Tomos-Request-Id'] = (string) $_SERVER['HTTP_X_TOMOS_REQUEST_ID'];
}

$inbox = new Tomos\PostInbox($config, $rootDir);
$api = new Tomos\PostInboxApi(
    $inbox,
    $config,
    new Tomos\PublisherStatusStore($config, $rootDir),
    new Tomos\PostInboxAutoPublisher($inbox, $config, $rootDir)
);
$response = $api->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    array_map(static fn ($value): string => is_string($value) ? $value : '', $headers),
    (string) file_get_contents('php://input'),
    $_SERVER
);

http_response_code($response->status);
echo json_encode($response->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
