<?php

declare(strict_types=1);

namespace Tomos;

final class SetupGuard
{
    public static function isDisabled(array $config, bool $configExists): bool
    {
        if (!$configExists) {
            return false;
        }

        if (($config['security']['disable_setup_after_install'] ?? false) === true) {
            return true;
        }

        if (!array_key_exists('setup_completed', $config)) {
            return true;
        }

        return $config['setup_completed'] !== false;
    }

    public static function environmentChecks(string $rootDir): array
    {
        $configPath = $rootDir . DIRECTORY_SEPARATOR . 'config.php';
        $cacheDir = $rootDir . DIRECTORY_SEPARATOR . 'cache';
        $cacheIndexDir = $rootDir . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'index';
        $cacheHtmlDir = $rootDir . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'html';
        $themesRoot = $rootDir . DIRECTORY_SEPARATOR . 'themes';
        $themeDir = $rootDir . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'tomos-minimal';
        $themeRepository = new ThemeRepository($themesRoot);
        $themes = $themeRepository->all();
        $validThemeCount = count(array_filter($themes, static function (array $theme): bool {
            return !empty($theme['valid']);
        }));
        $invalidThemeCount = count($themes) - $validThemeCount;
        $themeNote = '利用可能テーマ: ' . $validThemeCount . ' / 利用不可テーマ: ' . $invalidThemeCount;
        if ($invalidThemeCount > 0) {
            $invalidNames = array_keys(array_filter($themes, static function (array $theme): bool {
                return empty($theme['valid']);
            }));
            $themeNote .= ' / 利用不可: ' . implode(', ', $invalidNames);
        }

        return [
            self::check('PHPバージョン', version_compare(PHP_VERSION, '7.4.0', '>='), 'PHP ' . PHP_VERSION),
            self::info('config.php', is_file($configPath) ? '既存設定があります。再セットアップする場合は setup_completed と disable_setup_after_install を確認してください。' : '初回セットアップです。保存すると config.php を生成します。'),
            self::check('config.php または設置ディレクトリの書き込み', is_writable($configPath) || (!is_file($configPath) && is_writable($rootDir))),
            self::check('content/ ディレクトリ', is_dir($rootDir . DIRECTORY_SEPARATOR . 'content')),
            self::check('cache/ ディレクトリ', is_dir($cacheDir)),
            self::check('cache/index/ 書き込み', (is_dir($cacheIndexDir) && is_writable($cacheIndexDir)) || (!is_dir($cacheIndexDir) && is_writable($cacheDir)), '生成に失敗する場合は cache/index/ を作成して書き込み権限を確認してください。'),
            self::check('cache/html/ ディレクトリ', is_dir($cacheHtmlDir), 'HTMLキャッシュ用ディレクトリです。存在しない場合は作成してください。'),
            self::check('cache/html/ 書き込み', (is_dir($cacheHtmlDir) && is_writable($cacheHtmlDir)) || (!is_dir($cacheHtmlDir) && is_writable($cacheDir)), 'HTMLキャッシュを使う場合は cache/html/ の書き込み権限を確認してください。'),
            self::warning('cache/html/.htaccess', is_file($cacheHtmlDir . DIRECTORY_SEPARATOR . '.htaccess'), 'cache/html/ は内部キャッシュ用です。直接アクセスを防ぐため .htaccess をアップロードしてください。'),
            self::check('themes/ ディレクトリ', is_dir($themesRoot)),
            self::check('テーマ検証', $validThemeCount > 0, $themeNote),
            self::check('tomos-minimal テーマ', is_dir($themeDir)),
            self::check('tomos-minimal theme.json', is_file($themeDir . DIRECTORY_SEPARATOR . 'theme.json')),
            self::check('tomos-minimal favicon', is_file($themeDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'favicon.png') || is_file($themeDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'favicon.svg'), '標準テーマの favicon が見つかるか確認します。'),
            self::check('tomos-minimal OGP画像', is_file($themeDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'ogp.png'), '標準テーマの OGP 画像が見つかるか確認します。'),
            self::warning('.htaccess', is_file($rootDir . DIRECTORY_SEPARATOR . '.htaccess'), '.htaccess が見つかりません。このままではトップページ以外のURLが Not Found になる可能性があります。FTPソフトで不可視ファイルを表示し、.htaccess をアップロードしてください。'),
        ];
    }

    private static function check(string $label, bool $ok, string $note = ''): array
    {
        return [
            'label' => $label,
            'status' => $ok ? 'OK' : 'NG',
            'note' => $note,
        ];
    }

    private static function warning(string $label, bool $ok, string $note): array
    {
        return [
            'label' => $label,
            'status' => $ok ? 'OK' : '注意',
            'note' => $ok ? '' : $note,
        ];
    }

    private static function info(string $label, string $note): array
    {
        return [
            'label' => $label,
            'status' => 'OK',
            'note' => $note,
        ];
    }
}
