<?php

declare(strict_types=1);

namespace Tomos;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ThemePackagePolicy
{
    public const MAX_ZIP_BYTES = 10485760;
    public const MAX_EXPANDED_BYTES = 31457280;
    public const MAX_ENTRIES = 200;
    public const MAX_FILE_BYTES = 5242880;
    public const MAX_DIRECTORY_DEPTH = 4;

    private const RUNTIME_REQUIRED = [
        'theme.json',
        'templates/layout.html',
        'templates/page.html',
        'templates/list.html',
        'assets/style.css',
    ];
    private const DISTRIBUTION_RECOMMENDED = [
        'preview.png',
        'README.md',
        'LICENSE',
    ];
    private const ROOT_FILES = [
        'theme.json',
        'preview.png',
        'README.md',
        'LICENSE',
    ];
    private const TEMPLATE_FILES = [
        'templates/layout.html',
        'templates/page.html',
        'templates/list.html',
        'templates/home.html',
    ];
    private const ASSET_EXTENSIONS = [
        'css', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2',
    ];
    private const PHP_EXTENSIONS = ['php', 'phtml', 'phar', 'php5', 'php7'];

    public static function isThemeId(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]+\z/', $value) === 1;
    }

    public static function runtimeRequiredFiles(): array
    {
        return self::RUNTIME_REQUIRED;
    }

    public static function distributionRequiredFiles(): array
    {
        return self::DISTRIBUTION_RECOMMENDED;
    }

    public function validateExtracted(string $extractRoot, string $themeId): array
    {
        if (!self::isThemeId($themeId)) {
            throw new ThemePackageException('テーマIDが正しくありません。', 'theme_id');
        }

        $themeDir = rtrim($extractRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $themeId;
        if (!is_dir($themeDir) || is_link($themeDir)) {
            throw new ThemePackageException('テーマZIPの構成を確認できませんでした。', 'structure');
        }

        $this->validateAllowedTree($themeDir);
        $warnings = $this->validateRequiredFiles($themeDir);

        $themeJson = @file_get_contents($themeDir . DIRECTORY_SEPARATOR . 'theme.json');
        $decoded = is_string($themeJson) ? json_decode($themeJson, true) : null;
        if (!is_array($decoded)) {
            throw new ThemePackageException('theme.jsonが正しいJSONではありません。', 'theme_json');
        }
        if ((string) ($decoded['name'] ?? '') !== $themeId) {
            throw new ThemePackageException('テーマディレクトリ名とtheme.jsonのnameが一致していません。', 'theme_id');
        }
        $version = trim((string) ($decoded['version'] ?? ''));
        if (preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/', $version) !== 1) {
            throw new ThemePackageException('theme.jsonのversionを確認してください。公式テーマは1.0.0形式で指定します。', 'version');
        }

        $preview = $themeDir . DIRECTORY_SEPARATOR . 'preview.png';
        if (is_file($preview)) {
            $imageInfo = @getimagesize($preview);
            if (!is_array($imageInfo) || (int) ($imageInfo[2] ?? 0) !== IMAGETYPE_PNG) {
                throw new ThemePackageException('preview.pngをPNG画像として確認できませんでした。', 'preview');
            }
        }

        $validation = (new ThemeValidator($extractRoot))->validate($themeId);
        if (empty($validation['valid'])) {
            $details = array_map(static function ($error): string {
                return (string) $error;
            }, is_array($validation['errors'] ?? null) ? $validation['errors'] : []);
            $message = 'テーマの内容に問題があるため追加できません。';
            if ($details !== []) {
                $message .= ' ' . implode(' ', $details);
            }
            throw new ThemePackageException($message, 'validator');
        }

        $theme = is_array($validation['theme'] ?? null) ? $validation['theme'] : [];
        $validatorWarnings = is_array($validation['warnings'] ?? null) ? $validation['warnings'] : [];
        return [
            'theme_id' => $themeId,
            'display_name' => (string) ($theme['display_name'] ?? $themeId),
            'version' => $version,
            'warnings' => array_values(array_merge($warnings, $validatorWarnings)),
        ];
    }

    public function validatePackageRelativePath(string $relative, bool $directory): void
    {
        if ($relative === '' && $directory) {
            return;
        }
        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment[0] === '.' || $this->isTemporaryName($segment)) {
                throw new ThemePackageException('テーマZIPに不要なファイルが含まれています。', 'allowlist');
            }
        }
        if ($directory) {
            if ($relative !== 'templates' && $relative !== 'assets' && strpos($relative, 'assets/') !== 0) {
                throw new ThemePackageException('テーマZIPに許可されていないディレクトリがあります。', 'allowlist');
            }
            return;
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (in_array($extension, self::PHP_EXTENSIONS, true)) {
            throw new ThemePackageException('テーマにはPHPファイルを含められません。', 'php');
        }
        if (in_array($relative, self::ROOT_FILES, true) || in_array($relative, self::TEMPLATE_FILES, true)) {
            return;
        }
        if (strpos($relative, 'assets/') === 0 && in_array($extension, self::ASSET_EXTENSIONS, true)) {
            return;
        }
        throw new ThemePackageException('テーマZIPに許可されていないファイルが含まれています。', 'allowlist');
    }

    private function validateRequiredFiles(string $themeDir): array
    {
        $missingRuntime = [];
        foreach (self::RUNTIME_REQUIRED as $relative) {
            $path = $themeDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path) || filesize($path) === 0) {
                $missingRuntime[] = $relative;
            }
        }
        if ($missingRuntime !== []) {
            throw new ThemePackageException(
                'テーマの動作に必要なファイルが不足しています。 ' . implode(', ', $missingRuntime),
                'required_runtime'
            );
        }

        $missingRecommended = [];
        foreach (self::DISTRIBUTION_RECOMMENDED as $relative) {
            $path = $themeDir . DIRECTORY_SEPARATOR . $relative;
            if (!is_file($path) || filesize($path) === 0) {
                $missingRecommended[] = $relative;
            }
        }

        if ($missingRecommended === []) {
            return [];
        }

        return [
            implode('、', $missingRecommended) . ' が含まれていません。個人利用ではこのまま追加できます。第三者へ配布する場合は同梱を推奨します。',
        ];
    }

    private function validateAllowedTree(string $themeDir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($themeDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                throw new ThemePackageException('テーマZIPに利用できない種類のファイルが含まれています。', 'entry_type');
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($themeDir) + 1));
            $this->validatePackageRelativePath($relative, $entry->isDir());
        }
    }

    private function isTemporaryName(string $name): bool
    {
        $lower = strtolower($name);
        return $name === '__MACOSX'
            || $name === '.DS_Store'
            || strpos($name, '._') === 0
            || in_array($lower, ['.git', '.svn', '.hg'], true)
            || preg_match('/(?:~|\.sw[opx]|\.tmp|\.temp|\.bak|\.orig)$/i', $name) === 1;
    }
}
