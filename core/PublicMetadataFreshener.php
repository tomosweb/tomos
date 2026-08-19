<?php

declare(strict_types=1);

namespace Tomos;

final class PublicMetadataFreshener
{
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

            if ($index->loadFresh() === null) {
                $index->rebuild();
            }
        } catch (\Throwable $exception) {
            // Public rendering keeps its existing fallback behavior if refresh fails.
        }
    }
}
