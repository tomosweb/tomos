<?php

declare(strict_types=1);

namespace Tomos;

final class ConfigWriter
{
    public static function build(array $input, array $currentConfig, string $rootDir): array
    {
        [$siteSettings, $errors] = self::validateSiteSettings($input);
        $name = $siteSettings['site_name'];
        $description = $siteSettings['site_description'];
        $url = rtrim(self::cleanText((string) ($input['site_url'] ?? ''), 300), '/');
        if ($url === '' || !self::isHttpUrl($url)) {
            $errors[] = 'サイトURLは http:// または https:// で始まるURLを入力してください。';
        }

        $basePath = self::normalizeSetupPath((string) ($input['base_path'] ?? ''), 'base_path', $errors);
        $publicBasePath = self::normalizeSetupPath((string) ($input['public_base_path'] ?? ''), 'public_base_path', $errors);
        $rssPathPrefix = $siteSettings['rss_path_prefix'];

        $language = (string) ($input['language'] ?? 'ja');
        if (!in_array($language, ['ja', 'en'], true)) {
            $errors[] = '言語は ja または en を選択してください。';
            $language = 'ja';
        }

        $timezone = $siteSettings['timezone'];

        $themeName = self::cleanText((string) ($input['theme_name'] ?? 'tomos-minimal'), 80);
        if (!preg_match('/\A[A-Za-z0-9_-]+\z/', $themeName)) {
            $errors[] = 'テーマ名が正しくありません。';
            $themeName = 'tomos-minimal';
        } else {
            $validator = new ThemeValidator($rootDir . DIRECTORY_SEPARATOR . 'themes');
            $themeResult = $validator->validate($themeName);
            if (empty($themeResult['valid'])) {
                $errors[] = '指定されたテーマは利用できません。';
                foreach ($themeResult['errors'] as $error) {
                    $errors[] = (string) $error;
                }
            }
        }

        [$ga4MeasurementId, $ga4Errors] = Ga4::validateInput((string) ($input['ga4_measurement_id'] ?? ''));
        $errors = array_merge($errors, $ga4Errors);

        $config = [
            'site' => [
                'name' => $name,
                'description' => $description,
                'url' => $url,
                'base_path' => $basePath,
                'public_base_path' => $publicBasePath,
                'language' => $language,
                'timezone' => $timezone,
            ],
            'paths' => [
                'content_dir' => $rootDir . DIRECTORY_SEPARATOR . 'content',
                'cache_dir' => $rootDir . DIRECTORY_SEPARATOR . 'cache',
                'theme_dir' => $rootDir . DIRECTORY_SEPARATOR . 'themes',
            ],
            'theme' => [
                'name' => $themeName,
            ],
            'analytics' => [
                'ga4_measurement_id' => $ga4MeasurementId,
            ],
            'features' => [
                'search' => !empty($input['feature_search']),
                'tags' => !empty($input['feature_tags']),
                'rss' => !empty($input['feature_rss']),
                'sitemap' => !empty($input['feature_sitemap']),
                'html_cache' => !empty($input['feature_html_cache']),
                'post' => !empty($input['feature_post']),
                'metadata_cache' => true,
            ],
            'feed' => [
                'path_prefix' => $rssPathPrefix,
            ],
            'metadata' => [
                'include_drafts' => false,
            ],
            'debug' => [
                'performance_log' => false,
            ],
            'security' => [
                'allow_raw_html' => false,
                'allow_external_scripts' => false,
                'allowed_file_extensions' => ['md', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
                'disable_setup_after_install' => true,
                'hide_error_detail' => true,
                'content_security_policy' => true,
                'post_password_hash' => (string) ($input['post_password_hash'] ?? ''),
                'rate_limit_salt' => (string) ($input['rate_limit_salt'] ?? ''),
            ],
            'setup_completed' => true,
        ];

        return [$config, $errors];
    }

    public static function validateSiteSettings(array $input): array
    {
        $errors = [];
        $name = self::cleanText((string) ($input['site_name'] ?? ''), 100);
        if ($name === '') {
            $errors[] = 'サイト名を入力してください。';
        }

        $description = self::cleanText((string) ($input['site_description'] ?? ''), 200);
        $timezone = self::cleanText((string) ($input['timezone'] ?? 'Asia/Tokyo'), 100);
        if ($timezone === '') {
            $timezone = 'Asia/Tokyo';
        }
        if (function_exists('timezone_identifiers_list') && !in_array($timezone, timezone_identifiers_list(), true)) {
            $errors[] = 'タイムゾーンが正しくありません。';
        }

        $rssPathPrefix = self::normalizeSetupPath((string) ($input['rss_path_prefix'] ?? ''), 'RSS対象パス', $errors);

        return [[
            'site_name' => $name,
            'site_description' => $description,
            'timezone' => $timezone,
            'rss_path_prefix' => $rssPathPrefix,
        ], $errors];
    }

    public static function write(string $configPath, array $config, string $rootDir): bool
    {
        $content = self::toPhp($config, $rootDir);
        $tmpPath = $configPath . '.tmp';

        if (@file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($tmpPath, $configPath)) {
            @unlink($tmpPath);
            return false;
        }

        return true;
    }

    public static function toPhp(array $config, string $rootDir): string
    {
        $paths = [
            'content_dir' => [
                'absolute' => $rootDir . DIRECTORY_SEPARATOR . 'content',
                'placeholder' => '__TOMOS_CONTENT_DIR__',
                'replacement' => "__DIR__ . '/content'",
            ],
            'cache_dir' => [
                'absolute' => $rootDir . DIRECTORY_SEPARATOR . 'cache',
                'placeholder' => '__TOMOS_CACHE_DIR__',
                'replacement' => "__DIR__ . '/cache'",
            ],
            'theme_dir' => [
                'absolute' => $rootDir . DIRECTORY_SEPARATOR . 'themes',
                'placeholder' => '__TOMOS_THEME_DIR__',
                'replacement' => "__DIR__ . '/themes'",
            ],
        ];

        if (isset($config['paths']) && is_array($config['paths'])) {
            foreach ($paths as $key => $path) {
                if (($config['paths'][$key] ?? null) === $path['absolute']) {
                    $config['paths'][$key] = $path['placeholder'];
                }
            }
        }

        $export = var_export($config, true);
        foreach ($paths as $path) {
            $export = str_replace("'" . $path['placeholder'] . "'", $path['replacement'], $export);
        }

        return "<?php\n\nreturn " . $export . ";\n";
    }

    private static function cleanText(string $value, int $limit): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > $limit ? mb_substr($value, 0, $limit, 'UTF-8') : $value;
        }

        return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
    }

    private static function isHttpUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($scheme)
            && in_array(strtolower($scheme), ['http', 'https'], true)
            && is_string($host)
            && $host !== '';
    }

    private static function normalizeSetupPath(string $value, string $label, array &$errors): string
    {
        $value = trim($value);
        if ($value === '' || $value === '/') {
            return '';
        }

        if ($value[0] !== '/' || strpos($value, '//') === 0 || strpos($value, "\0") !== false || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $errors[] = $label . ' は空、または / で始まるパスを指定してください。';
            return '';
        }

        if (strpos($value, '\\') !== false || strpos($value, ':') !== false || preg_match('#(^|/)\.\.?(/|$)#', $value) === 1) {
            $errors[] = $label . ' に危険なパス指定が含まれています。';
            return '';
        }

        $value = preg_replace('#/+#', '/', $value) ?? '';
        if (self::looksLikeServerPath($value)) {
            $errors[] = $label . ' はURL上のパスを指定してください。サーバー内の実パスは入力しません。';
            return '';
        }

        return rtrim($value, '/');
    }

    private static function looksLikeServerPath(string $value): bool
    {
        return preg_match('#^/(?:home|var|usr|etc|opt|srv|private|Users)(?:/|$)#', $value) === 1;
    }

}
