<?php

declare(strict_types=1);

namespace Tomos;

final class SiteSettingsConfigWriter
{
    public static function update(array $currentConfig, array $input): array
    {
        $inputErrors = self::stringInputErrors($input);
        if ($inputErrors !== []) {
            return [$currentConfig, $inputErrors];
        }

        [$settings, $errors] = ConfigWriter::validateSiteSettings($input);
        [$rssEnabled, $rssErrors] = self::checkboxValue($input, 'feature_rss', 'RSS');
        [$sitemapEnabled, $sitemapErrors] = self::checkboxValue($input, 'feature_sitemap', 'Sitemap');
        $errors = array_merge($errors, $rssErrors, $sitemapErrors);

        if ($errors !== []) {
            return [$currentConfig, $errors];
        }

        $newConfig = $currentConfig;
        if (!isset($newConfig['site']) || !is_array($newConfig['site'])) {
            $newConfig['site'] = [];
        }
        if (!isset($newConfig['features']) || !is_array($newConfig['features'])) {
            $newConfig['features'] = [];
        }
        if (!isset($newConfig['feed']) || !is_array($newConfig['feed'])) {
            $newConfig['feed'] = [];
        }

        $newConfig['site']['name'] = $settings['site_name'];
        $newConfig['site']['description'] = $settings['site_description'];
        $newConfig['site']['timezone'] = $settings['timezone'];
        $newConfig['features']['rss'] = $rssEnabled;
        $newConfig['features']['sitemap'] = $sitemapEnabled;
        $newConfig['feed']['path_prefix'] = $settings['rss_path_prefix'];

        return [$newConfig, []];
    }

    private static function stringInputErrors(array $input): array
    {
        $errors = [];
        foreach ([
            'site_name' => 'サイト名',
            'site_description' => 'サイト説明',
            'timezone' => 'タイムゾーン',
            'rss_path_prefix' => 'RSS対象パス',
        ] as $key => $label) {
            if (isset($input[$key]) && !is_string($input[$key])) {
                $errors[] = $label . 'の入力値が正しくありません。';
            }
        }

        return $errors;
    }

    private static function checkboxValue(array $input, string $key, string $label): array
    {
        if (!array_key_exists($key, $input)) {
            return [false, []];
        }

        if (!is_string($input[$key]) || $input[$key] !== '1') {
            return [false, [$label . 'の設定値が正しくありません。']];
        }

        return [true, []];
    }
}
