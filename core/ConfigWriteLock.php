<?php

declare(strict_types=1);

namespace Tomos;

use RuntimeException;

final class ConfigWriteLock
{
    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function run(string $rootDir, callable $callback)
    {
        $storageDir = rtrim($rootDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($storageDir) && !@mkdir($storageDir, 0700, true) && !is_dir($storageDir)) {
            throw new RuntimeException('config write lock directory could not be created.');
        }

        $lockPath = $storageDir . DIRECTORY_SEPARATOR . 'config-write.lock';
        $handle = @fopen($lockPath, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException('config write lock could not be opened.');
        }
        @chmod($lockPath, 0600);

        if (!@flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('config write lock could not be acquired.');
        }

        try {
            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
