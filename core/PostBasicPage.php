<?php

declare(strict_types=1);

namespace Tomos;

final class PostBasicPageDownload
{
    public bool $ok;
    public string $file;
    public string $downloadName;
    public string $contentPath;
    public string $error;

    public function __construct(bool $ok, string $file = '', string $downloadName = '', string $contentPath = '', string $error = '')
    {
        $this->ok = $ok;
        $this->file = $file;
        $this->downloadName = $downloadName;
        $this->contentPath = $contentPath;
        $this->error = $error;
    }
}

final class PostBasicPage
{
    public const HOME = 'index';
    public const ABOUT = 'about';

    public static function typeFromFileName(string $fileName): string
    {
        $fileName = str_replace('\\', '/', trim($fileName));
        if (strpos($fileName, '/') !== false) {
            return '';
        }
        if ($fileName === 'index.md') {
            return self::HOME;
        }
        if ($fileName === 'about.md') {
            return self::ABOUT;
        }

        return '';
    }

    public static function isProtectedContentPath(string $contentPath): bool
    {
        $contentPath = trim(str_replace('\\', '/', $contentPath), '/');

        return in_array($contentPath, ['index.md', 'about.md'], true);
    }

    public static function canonicalContentPath(string $type): string
    {
        if ($type === self::HOME) {
            return 'index.md';
        }
        if ($type === self::ABOUT) {
            return 'about.md';
        }

        return '';
    }

    public static function internalUrl(string $type): string
    {
        if ($type === self::HOME) {
            return '/';
        }
        if ($type === self::ABOUT) {
            return '/about';
        }

        return '';
    }

    public static function label(string $type): string
    {
        return $type === self::HOME ? 'トップページ' : ($type === self::ABOUT ? 'Aboutページ' : '');
    }

    public static function download(array $config, string $rootDir, string $type): PostBasicPageDownload
    {
        $canonical = self::canonicalContentPath($type);
        if ($canonical === '') {
            return new PostBasicPageDownload(false, '', '', '', 'ダウンロード対象を確認できません。');
        }

        $contentDir = (string) (($config['paths']['content_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'content'));
        $contentBase = realpath($contentDir);
        if ($contentBase === false || !is_dir($contentBase)) {
            return new PostBasicPageDownload(false, '', '', '', '対象ファイルが見つかりません。Tomosの初期設定またはファイル構成を確認してください。');
        }

        foreach ([$canonical] as $contentPath) {
            $path = $contentBase . DIRECTORY_SEPARATOR . $contentPath;
            $realPath = realpath($path);
            if ($realPath === false || !is_file($realPath) || is_link($path) || !Security::isPathInside($realPath, $contentBase)) {
                continue;
            }

            return new PostBasicPageDownload(true, $realPath, $canonical, $contentPath);
        }

        return new PostBasicPageDownload(false, '', '', '', '対象ファイルが見つかりません。Tomosの初期設定またはファイル構成を確認してください。');
    }
}
