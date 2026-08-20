<?php

declare(strict_types=1);

namespace Tomos;

final class ThemeValidator
{
    private string $themesDir;
    private array $requiredFiles = [
        'theme.json',
        'templates/layout.html',
        'templates/page.html',
        'templates/list.html',
        'assets/style.css',
    ];
    private array $recommendedFiles = [
        'assets/apple-touch-icon.png',
        'assets/ogp.png',
    ];
    private array $forbiddenPhpExtensions = ['php', 'phtml', 'phar', 'php5', 'php7'];
    private array $templateErrorPatterns = [
        '/<\?(?:php|=)?/i' => 'PHPタグは使えません。',
        '/javascript\s*:/i' => 'javascript: URLは使えません。',
        '/data\s*:\s*text\/html/i' => 'data:text/html は使えません。',
    ];
    private array $templateWarningPatterns = [
        '/<script\s+[^>]*(?:src|type)\s*=/i' => 'script要素は避けてください。',
        '/\son(?:error|load|click)\s*=/i' => 'イベントハンドラ属性は避けてください。',
    ];

    public function __construct(string $themesDir)
    {
        $this->themesDir = rtrim($themesDir, DIRECTORY_SEPARATOR);
    }

    public function validate(string $themeName): array
    {
        $errors = [];
        $warnings = [];
        $theme = null;

        if (!$this->isSafeThemeName($themeName)) {
            return $this->result(false, ['テーマ名が正しくありません。'], [], null);
        }

        $themeDir = $this->themesDir . DIRECTORY_SEPARATOR . $themeName;
        $realThemesDir = realpath($this->themesDir);
        $realThemeDir = realpath($themeDir);

        if ($realThemesDir === false || $realThemeDir === false || !is_dir($realThemeDir)) {
            return $this->result(false, ['テーマディレクトリが見つかりません。'], [], null);
        }

        $realThemesDir = rtrim($realThemesDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($realThemeDir . DIRECTORY_SEPARATOR, $realThemesDir) !== 0) {
            return $this->result(false, ['テーマディレクトリが themes/ 配下にありません。'], [], null);
        }

        foreach ($this->requiredFiles as $file) {
            if (!is_file($realThemeDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file))) {
                $errors[] = $file . ' が見つかりません。';
            }
        }

        $this->validateRecommendedAssets($realThemeDir, $warnings);

        $themeJson = $this->validateThemeJson($realThemeDir, $themeName, $errors);
        if ($themeJson !== null) {
            $theme = $themeJson;
        }

        $this->validateFiles($realThemeDir, $errors, $warnings);

        return $this->result($errors === [], $errors, $warnings, $theme);
    }

    public function requiredFiles(): array
    {
        return $this->requiredFiles;
    }

    public function recommendedFiles(): array
    {
        return array_merge(['assets/favicon.svg または assets/favicon.png'], $this->recommendedFiles);
    }

    private function validateThemeJson(string $themeDir, string $themeName, array &$errors): ?array
    {
        $path = $themeDir . DIRECTORY_SEPARATOR . 'theme.json';
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            $errors[] = 'theme.json を読み込めません。';
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $errors[] = 'theme.json が正しいJSONではありません。';
            return null;
        }

        $name = trim((string) ($decoded['name'] ?? ''));
        if ($name !== $themeName) {
            $errors[] = 'theme.json の name がディレクトリ名と一致しません。';
        }

        if (trim((string) ($decoded['display_name'] ?? '')) === '') {
            $errors[] = 'theme.json の display_name が空です。';
        }

        if (trim((string) ($decoded['version'] ?? '')) === '') {
            $errors[] = 'theme.json の version が空です。';
        }

        if (isset($decoded['supports']) && !is_array($decoded['supports'])) {
            $errors[] = 'theme.json の supports はオブジェクトで指定してください。';
        }

        $requiresTomos = trim((string) ($decoded['requires_tomos'] ?? ''));
        if ($requiresTomos !== '') {
            if (!$this->isTomosVersion($requiresTomos)) {
                $errors[] = 'theme.json の requires_tomos は Tomos のバージョン番号を1つ指定してください。';
            } else {
                $currentTomos = $this->currentTomosVersion();
                if ($currentTomos === '' || !$this->isTomosVersion($currentTomos)) {
                    $errors[] = 'このテーマの互換性を確認するためのTomos本体バージョンを取得できません。';
                } elseif (version_compare($currentTomos, $requiresTomos, '<')) {
                    $errors[] = 'このテーマには Tomos ' . $requiresTomos . ' 以上が必要です。現在のTomosは ' . $currentTomos . ' です。';
                }
            }
        }

        return [
            'name' => $name,
            'display_name' => (string) ($decoded['display_name'] ?? ''),
            'version' => (string) ($decoded['version'] ?? ''),
            'description' => (string) ($decoded['description'] ?? ''),
            'author' => (string) ($decoded['author'] ?? ''),
            'requires_tomos' => $requiresTomos,
            'supports' => is_array($decoded['supports'] ?? null) ? $decoded['supports'] : [],
        ];
    }

    private function validateFiles(string $themeDir, array &$errors, array &$warnings): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($themeDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $relativePath = $this->relativePath($themeDir, $file->getPathname());
            $extension = strtolower($file->getExtension());
            if (in_array($extension, $this->forbiddenPhpExtensions, true)) {
                $errors[] = $relativePath . ' はテーマ内で禁止されているPHP系ファイルです。';
                continue;
            }

            if ($extension === 'html') {
                $this->validateTemplateContent($relativePath, $file->getPathname(), $errors, $warnings);
                continue;
            }

            if ($extension === 'svg') {
                $this->validateSvgContent($relativePath, $file->getPathname(), $errors);
            }
        }
    }

    private function validateRecommendedAssets(string $themeDir, array &$warnings): void
    {
        $hasFavicon = is_file($themeDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'favicon.svg')
            || is_file($themeDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'favicon.png');
        if (!$hasFavicon) {
            $warnings[] = 'assets/favicon.svg または assets/favicon.png がありません。';
        }

        foreach ($this->recommendedFiles as $file) {
            if (!is_file($themeDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file))) {
                $warnings[] = $file . ' がありません。';
            }
        }
    }

    private function validateTemplateContent(string $relativePath, string $path, array &$errors, array &$warnings): void
    {
        $content = file_get_contents($path);
        if ($content === false) {
            $errors[] = $relativePath . ' を読み込めません。';
            return;
        }

        foreach ($this->templateErrorPatterns as $pattern => $message) {
            if (preg_match($pattern, $content) === 1) {
                $errors[] = $relativePath . ': ' . $message;
            }
        }

        foreach ($this->templateWarningPatterns as $pattern => $message) {
            if (preg_match($pattern, $content) === 1) {
                $warnings[] = $relativePath . ': ' . $message;
            }
        }
    }

    private function validateSvgContent(string $relativePath, string $path, array &$errors): void
    {
        $content = file_get_contents($path);
        if ($content === false) {
            $errors[] = $relativePath . ' を読み込めません。';
            return;
        }

        if (preg_match('/<script\b/i', $content) === 1 || preg_match('/\s(?:href|xlink:href)\s*=\s*["\']https?:\/\//i', $content) === 1) {
            $errors[] = $relativePath . ': SVG内にscriptまたは外部参照があります。';
        }
    }

    private function currentTomosVersion(): string
    {
        $directory = $this->themesDir;
        for ($depth = 0; $depth <= 6; $depth++) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'VERSION';
            if (is_file($candidate) && is_readable($candidate)) {
                $value = file_get_contents($candidate);
                return $value === false ? '' : trim($value);
            }

            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        return '';
    }

    private function isTomosVersion(string $version): bool
    {
        return preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?\z/', $version) === 1;
    }

    private function isSafeThemeName(string $themeName): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]+\z/', $themeName) === 1;
    }

    private function relativePath(string $baseDir, string $path): string
    {
        $relative = substr($path, strlen(rtrim($baseDir, DIRECTORY_SEPARATOR)) + 1);
        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    private function result(bool $valid, array $errors, array $warnings, ?array $theme): array
    {
        return [
            'valid' => $valid,
            'errors' => $errors,
            'warnings' => $warnings,
            'theme' => $theme,
        ];
    }
}
