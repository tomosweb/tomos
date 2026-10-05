<?php

declare(strict_types=1);

namespace Tomos;

/**
 * Supplies the runtime defaults needed by the standalone static builder.
 *
 * The PHP Web Runtime keeps using config.php. GitHub edition sites may return
 * only their site and feature settings from tomos.config.php; paths belonging
 * to the checked-out site and the pinned Tomos source are supplied here.
 */
final class StaticSiteConfig
{
    public static function normalize(array $config, string $configDir, string $tomosRoot): array
    {
        $configDir = self::absoluteDirectory($configDir);
        $tomosRoot = self::absoluteDirectory($tomosRoot);

        $defaults = [
            'site' => [
                'name' => 'Tomos Site',
                'description' => '',
                'url' => '',
                'base_path' => '',
                'public_base_path' => '',
                'language' => 'ja',
                'timezone' => 'Asia/Tokyo',
            ],
            'paths' => [
                'content_dir' => $configDir . DIRECTORY_SEPARATOR . 'content',
                'cache_dir' => $configDir . DIRECTORY_SEPARATOR . '.tomos-cache',
                'theme_dir' => $tomosRoot . DIRECTORY_SEPARATOR . 'themes',
            ],
            'theme' => [
                'name' => 'tomos-minimal',
            ],
            'analytics' => [
                'ga4_measurement_id' => '',
            ],
            'features' => [
                'search' => true,
                'tags' => true,
                'rss' => true,
                'sitemap' => true,
                'html_cache' => false,
                'post' => false,
                'metadata_cache' => false,
            ],
            'feed' => [
                'path_prefix' => '',
            ],
            'metadata' => [
                'include_drafts' => false,
            ],
            'security' => [
                'allow_raw_html' => false,
                'allow_external_scripts' => false,
                'content_security_policy' => false,
            ],
        ];

        $normalized = array_replace_recursive($defaults, $config);
        $normalized['paths']['content_dir'] = self::resolvePath(
            (string) $normalized['paths']['content_dir'],
            $configDir
        );
        $normalized['paths']['cache_dir'] = self::resolvePath(
            (string) $normalized['paths']['cache_dir'],
            $configDir
        );
        $normalized['paths']['theme_dir'] = self::resolvePath(
            (string) $normalized['paths']['theme_dir'],
            $tomosRoot
        );

        foreach (['name', 'description', 'url', 'base_path', 'language'] as $field) {
            if (!is_string($normalized['site'][$field] ?? null)) {
                throw new \InvalidArgumentException('site.' . $field . ' must be a string.');
            }
        }
        if ($normalized['site']['name'] === '') {
            throw new \InvalidArgumentException('site.name must not be empty.');
        }
        if ($normalized['site']['url'] === '') {
            throw new \InvalidArgumentException('site.url must not be empty.');
        }
        if (!is_string($normalized['theme']['name'] ?? null) || $normalized['theme']['name'] === '') {
            throw new \InvalidArgumentException('theme.name must not be empty.');
        }

        return $normalized;
    }

    private static function absoluteDirectory(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new \InvalidArgumentException('A static build directory must not be empty.');
        }

        $real = realpath($path);
        return rtrim($real !== false ? $real : $path, DIRECTORY_SEPARATOR);
    }

    private static function resolvePath(string $path, string $baseDir): string
    {
        if ($path === '') {
            return $baseDir;
        }

        if ($path[0] === DIRECTORY_SEPARATOR || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }

        return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }
}
