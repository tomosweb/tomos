<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/BlueskyOAuthHttpClient.php';

use Tomos\\BlueskyOAuthHttpClient;

$client = new BlueskyOAuthHttpClient();

try {
    // A 10 MiB source limit must pass the byte-limit guard. The loopback URL
    // is rejected by the public-target guard before any network request.
    $client->getWithLimit('https://127.0.0.1/', 10485760);
} catch (InvalidArgumentException $exception) {
    if ($exception->getMessage() !== 'OAuth URL resolves to a non-public address.') {
        throw new RuntimeException(
            '10 MiB response limit was rejected before public-target validation.',
            0,
            $exception
        );
    }
}

try {
    $client->getWithLimit('https://127.0.0.1/', 10485761);
    throw new RuntimeException('A response limit above 10 MiB must be rejected.');
} catch (InvalidArgumentException $exception) {
    if ($exception->getMessage() !== 'HTTP response byte limit is invalid.') {
        throw $exception;
    }
}

echo "bluesky_http_response_limit_check: passed\\n";
