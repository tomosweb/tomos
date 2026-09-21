<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/InboxApiCors.php';

use Tomos\InboxApiCors;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

$config = ['security' => []];

assertSameValue(
    true,
    InboxApiCors::isAllowed('https://tomos-workspace.al-8720554p.workers.dev', $config),
    'official Workspace origin should be allowed by default'
);
assertSameValue(
    true,
    InboxApiCors::isAllowed('http://localhost:5173', $config),
    'local Vite origin should be allowed by default'
);
assertSameValue(
    false,
    InboxApiCors::isAllowed('https://evil.example', $config),
    'unknown origin must be rejected'
);

$headers = InboxApiCors::responseHeaders('https://tomos-workspace.al-8720554p.workers.dev', $config);
assertSameValue(
    'https://tomos-workspace.al-8720554p.workers.dev',
    $headers['Access-Control-Allow-Origin'] ?? null,
    'allowed origin header mismatch'
);
if (strpos((string) ($headers['Access-Control-Allow-Headers'] ?? ''), 'X-Tomos-Token') === false) {
    throw new RuntimeException('publisher token header must be permitted');
}
if (strpos((string) ($headers['Access-Control-Allow-Headers'] ?? ''), 'X-Tomos-Upload-Id') === false) {
    throw new RuntimeException('image upload headers must be permitted');
}

$custom = [
    'security' => [
        'inbox_api_allowed_origins' => ['https://workspace.example', 'invalid-origin'],
    ],
];
assertSameValue(
    true,
    InboxApiCors::isAllowed('https://workspace.example/', $custom),
    'configured origin should normalize trailing slash'
);
assertSameValue(
    false,
    InboxApiCors::isAllowed('https://tomos-workspace.al-8720554p.workers.dev', $custom),
    'configured allowlist should override defaults'
);
assertSameValue(
    [],
    InboxApiCors::responseHeaders('https://evil.example', $custom),
    'rejected origin must not receive CORS headers'
);

echo "inbox_api_cors_check: OK\n";
