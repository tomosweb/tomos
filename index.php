<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/core/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    redirectToSetup($_SERVER['REQUEST_URI'] ?? '/');
    exit;
}

$config = require $configPath;

try {
    $app = new Tomos\App($config);
    $app->run($_SERVER['REQUEST_URI'] ?? '/');
} catch (Throwable $exception) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');

    $hideErrorDetail = $config['security']['hide_error_detail'] ?? true;

    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>Tomos Error</title><body>';
    echo '<h1>エラーが発生しました</h1>';

    if (!$hideErrorDetail) {
        echo '<pre style="white-space: pre-wrap; background: #f5f5f5; padding: 1rem; border: 1px solid #ccc;">';
        echo htmlspecialchars(get_class($exception) . "\n", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo htmlspecialchars($exception->getMessage() . "\n\n", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo htmlspecialchars($exception->getFile() . ':' . $exception->getLine() . "\n\n", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '</pre>';
    }

    echo '</body></html>';
}

function redirectToSetup(string $requestUri): void
{
    $requestPath = parse_url($requestUri, PHP_URL_PATH);
    $requestPath = is_string($requestPath) && $requestPath !== '' ? $requestPath : '/';

    if (preg_match('#/setup/?$#', $requestPath) === 1) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>Tomos setup</title><body>';
        echo '<p>setup画面を開いてください。</p>';
        echo '</body></html>';
        exit;
    }

    header('Location: setup/', true, 302);
    exit;
}
