<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$options = getopt('', ['config::', 'output::', 'tomos-root::']);
$rootDir = dirname(__DIR__);
$configPath = isset($options['config']) && is_string($options['config']) && $options['config'] !== ''
    ? $options['config']
    : $rootDir . '/config.php';
$outputDir = isset($options['output']) && is_string($options['output']) && $options['output'] !== ''
    ? $options['output']
    : $rootDir . '/build/static-site';
$tomosRoot = isset($options['tomos-root']) && is_string($options['tomos-root']) && $options['tomos-root'] !== ''
    ? $options['tomos-root']
    : $rootDir;
putenv('TOMOS_ROOT=' . $tomosRoot);

if (!is_file($configPath) || !is_readable($configPath)) {
    fwrite(STDERR, "Config file not found: {$configPath}\n");
    exit(1);
}

$config = require $configPath;
if (!is_array($config)) {
    fwrite(STDERR, "Config file must return an array.\n");
    exit(1);
}

try {
    $config = Tomos\StaticSiteConfig::normalize($config, dirname($configPath), $tomosRoot);
    $builder = new Tomos\StaticSiteBuilder($config, $tomosRoot);
    $result = $builder->build($outputDir);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Static build failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        "Static build complete: pages=%d virtual_folders=%d tags=%d files=%d output=%s\n",
        (int) $result['pages'],
        (int) $result['virtual_folders'],
        (int) $result['tags'],
        (int) $result['files'],
        $outputDir
    )
);
