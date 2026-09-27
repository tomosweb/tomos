<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['root::']);
$rootDir = isset($options['root']) && is_string($options['root']) && $options['root'] !== ''
    ? rtrim($options['root'], DIRECTORY_SEPARATOR)
    : dirname(__DIR__);

require_once $rootDir . '/core/BlueskyOAuthHtaccessDiagnostics.php';
require_once $rootDir . '/core/BlueskyOAuthHtaccessMigration.php';

try {
    $plan = Tomos\BlueskyOAuthHtaccessMigration::inspectMigration($rootDir);
    if (($plan['status'] ?? '') === 'managed' && !empty($plan['diagnostic']['static_backing_enabled'])) {
        echo "Bluesky OAuth static backing migration is already active.\n";
        exit;
    }
    if (empty($plan['can_migrate'])) {
        fwrite(STDERR, 'Bluesky OAuth static backing migration is not safe: '
            . implode(', ', (array) ($plan['issues'] ?? [])) . PHP_EOL);
        exit(1);
    }
    $result = Tomos\BlueskyOAuthHtaccessMigration::migrate($rootDir);
    if (($result['status'] ?? '') !== 'migrated' || empty($result['diagnostic']['safe_for_managed_update'])) {
        fwrite(STDERR, 'Bluesky OAuth static backing migration failed: '
            . implode(', ', (array) ($result['issues'] ?? [])) . PHP_EOL);
        exit(1);
    }
    echo "Bluesky OAuth static backing migration completed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Bluesky OAuth static backing migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
