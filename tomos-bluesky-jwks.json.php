<?php

declare(strict_types=1);

$rootDir = __DIR__;
require_once $rootDir . '/core/BlueskyOAuthKeyStore.php';

$record = (new Tomos\BlueskyOAuthKeyStore($rootDir))->load();
if (!is_array($record) || !is_array($record['public_jwk'] ?? null)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"keys":[]}';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo json_encode(['keys' => [$record['public_jwk']]], JSON_UNESCAPED_SLASHES);
