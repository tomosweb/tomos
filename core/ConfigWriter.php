<?php

declare(strict_types=1);

namespace Tomos;

final class ConfigWriter
{
    /** @var array<string,string> */
    private static array $expectedSourceFingerprints = [];

    public static function build(array $input, array $currentConfig, string $rootDir): array
    {
        [$siteSettings, $errors] = self::validateSiteSettings($input);
        $name = $siteSettings['site_name'];
        $description = $siteSettings['site_description'];
        $urlResult = SetupUrlResolver::normalizeSiteUrl(self::cleanText((string) ($input['site_url'] ?? ''), 300));
        if ($urlResult === null) {
            $errors[] = 'サイトURLは http:// または https:// で始まるURLを入力してください。';
            $url = '';
            $basePath = '';
        } else {
            $url = $urlResult['site_url'];
            $basePath = $urlResult['base_path'];
        }

        $providedBasePath = trim((string) ($input['base_path'] ?? ''));
        $normalizedProvidedBasePath = SetupUrlResolver::normalizeBasePath($providedBasePath);
        if ($providedBasePath !== '' && ($normalizedProvidedBasePath === null || $normalizedProvidedBasePath !== $basePath)) {
            $errors[] = 'base_path はサイトURLの設置パスと一致している必要があります。';
        }

        $publicBasePath = self::normalizeSetupPath((string) ($input['public_base_path'] ?? ''), 'public_base_path', $errors);
        $rssPathPrefix = $siteSettings['rss_path_prefix'];

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
                'language' => $siteSettings['language'],
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
                'inbox_api_token_hash' => (string) (($currentConfig['security']['inbox_api_token_hash'] ?? '') ?: ''),
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
        $languageInput = $input['language'] ?? 'ja';
        if (isset($input['language_custom']) && is_string($input['language_custom']) && trim($input['language_custom']) !== '') {
            $languageInput = $input['language_custom'];
        }
        $language = LanguageTag::normalizeOrNull($languageInput);
        if ($language === null) {
            $errors[] = '言語コードは BCP 47 形式（例: ja、en、zh-Hans）で指定してください。';
            $language = 'ja';
        }

        return [[
            'site_name' => $name,
            'site_description' => $description,
            'timezone' => $timezone,
            'rss_path_prefix' => $rssPathPrefix,
            'language' => $language,
        ], $errors];
    }

    public static function expectUnchangedSource(array $currentConfig, array $newConfig): array
    {
        self::$expectedSourceFingerprints[self::fingerprint($newConfig)] = self::fingerprint($currentConfig);
        return $newConfig;
    }

    public static function write(string $configPath, array $config, string $rootDir): bool
    {
        $newFingerprint = self::fingerprint($config);
        $expectedFingerprint = self::$expectedSourceFingerprints[$newFingerprint] ?? null;
        unset(self::$expectedSourceFingerprints[$newFingerprint]);

        try {
            return ConfigWriteLock::run($rootDir, static function () use ($configPath, $config, $rootDir, $expectedFingerprint): bool {
                if (is_string($expectedFingerprint)) {
                    $latest = self::loadCurrent($configPath);
                    if ($latest === null || !hash_equals($expectedFingerprint, self::fingerprint($latest))) {
                        return false;
                    }
                }

                return self::writeUnlocked($configPath, $config, $rootDir);
            });
        } catch (\Throwable $exception) {
            return false;
        }
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

    private static function writeUnlocked(string $configPath, array $config, string $rootDir): bool
    {
        $content = self::toPhp($config, $rootDir);
        try {
            $tmpPath = $configPath . '.tmp-' . bin2hex(random_bytes(8));
        } catch (\Throwable $exception) {
            return false;
        }

        if (@file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            @unlink($tmpPath);
            return false;
        }

        $mode = @fileperms($configPath);
        if (is_int($mode)) {
            @chmod($tmpPath, $mode & 0777);
        }

        if (!@rename($tmpPath, $configPath)) {
            @unlink($tmpPath);
            return false;
        }

        return true;
    }

    private static function loadCurrent(string $configPath): ?array
    {
        if (!is_file($configPath)) {
            return null;
        }

        try {
            $loaded = require $configPath;
        } catch (\Throwable $exception) {
            return null;
        }

        return is_array($loaded) ? $loaded : null;
    }

    private static function fingerprint(array $config): string
    {
        return hash('sha256', serialize($config));
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
