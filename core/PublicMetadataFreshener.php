<?php

declare(strict_types=1);

namespace Tomos;

final class PublicMetadataFreshener
{
    private const CACHE_SCHEMA = '3';

    public static function ensure(array $config): void
    {
        $features = is_array($config['features'] ?? null) ? $config['features'] : [];
        $metadataCacheEnabled = !array_key_exists('metadata_cache', $features) || !empty($features['metadata_cache']);
        if (!$metadataCacheEnabled) {
            return;
        }

        $contentDir = (string) ($config['paths']['content_dir'] ?? '');
        $cacheDir = (string) ($config['paths']['cache_dir'] ?? '');
        if ($contentDir === '' || $cacheDir === '') {
            return;
        }

        try {
            $index = new MetadataIndex(
                $contentDir,
                $cacheDir,
                new FrontMatterParser(),
                (bool) ($config['metadata']['include_drafts'] ?? false)
            );

            $schemaFile = self::schemaFile($cacheDir);
            $schema = is_file($schemaFile) && is_readable($schemaFile)
                ? trim((string) @file_get_contents($schemaFile))
                : '';

            if ($schema !== self::CACHE_SCHEMA || $index->loadFresh() === null) {
                $index->rebuild();
                self::writeSchema($schemaFile);
            }
        } catch (\Throwable $exception) {
            // Public rendering keeps its existing fallback behavior if refresh fails.
        }
    }

    private static function schemaFile(string $cacheDir): string
    {
        return rtrim($cacheDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'index'
            . DIRECTORY_SEPARATOR . 'metadata-schema.txt';
    }

    private static function writeSchema(string $schemaFile): void
    {
        $directory = dirname($schemaFile);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Metadata schema directory could not be created.');
        }

        $temporary = $schemaFile . '.tmp-' . bin2hex(random_bytes(8));
        if (@file_put_contents($temporary, self::CACHE_SCHEMA . "\n", LOCK_EX) === false) {
            @unlink($temporary);
            throw new \RuntimeException('Metadata schema marker could not be written.');
        }

        if (!@rename($temporary, $schemaFile)) {
            @unlink($temporary);
            throw new \RuntimeException('Metadata schema marker could not be installed.');
        }
    }
}
