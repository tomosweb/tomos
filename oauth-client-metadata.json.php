<?php

declare(strict_types=1);

$rootDir = __DIR__;
$config = is_file($rootDir . '/config.php') ? require $rootDir . '/config.php' : [];
if (!is_array($config)) {
    $config = [];
}
require_once $rootDir . '/core/BlueskyOAuthMetadata.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo json_encode(
    Tomos\BlueskyOAuthMetadata::clientMetadata($config),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
