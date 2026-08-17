<?php

declare(strict_types=1);

namespace Tomos {
    function rename($from, $to): bool
    {
        if (
            !empty($GLOBALS['tomos_config_fail_rename'])
            && isset($GLOBALS['tomos_config_target'])
            && $to === $GLOBALS['tomos_config_target']
            && strpos(basename((string) $from), basename((string) $to) . '.tmp-') === 0
        ) {
            $GLOBALS['tomos_config_failed_rename_calls'] = (int) ($GLOBALS['tomos_config_failed_rename_calls'] ?? 0) + 1;
            return false;
        }

        return \rename($from, $to);
    }
}

namespace {
    require_once dirname(__DIR__) . '/core/ConfigWriteLock.php';
    require_once dirname(__DIR__) . '/core/ConfigWriter.php';

    use Tomos\ConfigWriter;

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-config-rename-failure-' . bin2hex(random_bytes(6));
    mkdir($root . '/storage', 0700, true);
    $configPath = $root . '/config.php';

    $oldConfig = [
        'site' => ['name' => 'Before rename failure'],
        'paths' => [
            'content_dir' => $root . '/content',
            'cache_dir' => $root . '/cache',
            'theme_dir' => $root . '/themes',
        ],
    ];
    $newConfig = $oldConfig;
    $newConfig['site']['name'] = 'Must not commit';

    try {
        if (!ConfigWriter::write($configPath, $oldConfig, $root)) {
            throw new RuntimeException('baseline config write failed');
        }
        $beforeBytes = file_get_contents($configPath);
        if (!is_string($beforeBytes) || $beforeBytes === '') {
            throw new RuntimeException('baseline config could not be read');
        }

        $GLOBALS['tomos_config_target'] = $configPath;
        $GLOBALS['tomos_config_fail_rename'] = true;
        $GLOBALS['tomos_config_failed_rename_calls'] = 0;

        if (ConfigWriter::write($configPath, $newConfig, $root)) {
            throw new RuntimeException('config write unexpectedly succeeded after injected rename failure');
        }

        $afterBytes = file_get_contents($configPath);
        if (!is_string($afterBytes) || !hash_equals(hash('sha256', $beforeBytes), hash('sha256', $afterBytes))) {
            throw new RuntimeException('existing config changed after rename failure');
        }
        $afterConfig = require $configPath;
        if (!is_array($afterConfig) || ($afterConfig['site']['name'] ?? null) !== 'Before rename failure') {
            throw new RuntimeException('existing config content was not preserved');
        }
        if (($GLOBALS['tomos_config_failed_rename_calls'] ?? 0) !== 1) {
            throw new RuntimeException('expected exactly one injected config rename failure');
        }
        if ((glob($configPath . '.tmp-*') ?: []) !== []) {
            throw new RuntimeException('failed config temporary file was not removed');
        }

        echo "config_rename_failure_check: OK\n";
    } finally {
        $GLOBALS['tomos_config_fail_rename'] = false;
        removeTree($root);
    }

    function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
            removeTree($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
    }
}
