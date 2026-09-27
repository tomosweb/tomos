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
$configPath = $rootDir . '/config.php';
$config = is_file($configPath) ? require $configPath : [];
$config = is_array($config) ? $config : [];

require_once $rootDir . '/core/BlueskyOAuthMetadata.php';
require_once $rootDir . '/core/BlueskyOAuthKeyStore.php';
require_once $rootDir . '/core/BlueskyOAuthStaticBacking.php';

try {
    Tomos\BlueskyOAuthStaticBacking::generate($rootDir, $config);
    echo "Bluesky OAuth static backing generated.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Bluesky OAuth static backing generation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
