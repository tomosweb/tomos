<?php

declare(strict_types=1);

namespace Tomos;

final class ImageEmbedParser
{
    private string $contentDir;
    private string $publicBasePath;
    private array $allowedExtensions;
    private array $placeholders = [];

    public function __construct(string $contentDir, string $publicBasePath = '', array $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'])
    {
        $this->contentDir = rtrim($contentDir, DIRECTORY_SEPARATOR);
        $this->publicBasePath = $publicBasePath;
        $this->allowedExtensions = array_map('strtolower', $allowedExtensions);
    }

    public function replace(string $markdown, string $currentPagePath): string
    {
        $this->placeholders = [];

        $markdown = preg_replace_callback('/(^|\n)[ \t]*!\[([^\]\n]*)\]\(([^)\s]+)\)[ \t]*(?=\n|$)/u', function (array $matches) use ($currentPagePath): string {
            return $matches[1] . "\n" . $this->placeholder($this->imageHtml($matches[3], $matches[2], $currentPagePath)) . "\n";
        }, $markdown) ?? $markdown;

        $markdown = preg_replace_callback('/(^|\n)[ \t]*!\[\[([^\]\n]+)\]\][ \t]*(?=\n|$)/u', function (array $matches) use ($currentPagePath): string {
            [$target, $alt] = $this->splitObsidianTarget($matches[2]);
            return $matches[1] . "\n" . $this->placeholder($this->imageHtml($target, $alt, $currentPagePath)) . "\n";
        }, $markdown) ?? $markdown;

        $markdown = preg_replace_callback('/!\[([^\]\n]*)\]\(([^)\s]+)\)/u', function (array $matches) use ($currentPagePath): string {
            return $this->placeholder($this->imageHtml($matches[2], $matches[1], $currentPagePath));
        }, $markdown) ?? $markdown;

        return preg_replace_callback('/!\[\[([^\]\n]+)\]\]/u', function (array $matches) use ($currentPagePath): string {
            [$target, $alt] = $this->splitObsidianTarget($matches[1]);
            return $this->placeholder($this->imageHtml($target, $alt, $currentPagePath));
        }, $markdown) ?? $markdown;
    }

    public function restore(string $html): string
    {
        if ($this->placeholders === []) {
            return $html;
        }

        return strtr($html, $this->placeholders);
    }

    private function placeholder(string $html): string
    {
        $placeholder = 'TOMOSIMAGE' . count($this->placeholders) . 'TOKEN';
        $this->placeholders[$placeholder] = $html;

        return $placeholder;
    }

    private function imageHtml(string $target, string $alt, string $currentPagePath): string
    {
        $resolved = $this->resolveImage($target, $currentPagePath);
        if ($resolved === null) {
            return $this->missingHtml($target, $alt);
        }

        return '<img src="' . $this->escape($resolved) . '" alt="' . $this->escape($alt) . '">';
    }

    private function splitObsidianTarget(string $source): array
    {
        $parts = explode('|', $source, 2);
        $target = trim($parts[0]);
        $alt = isset($parts[1]) ? trim($parts[1]) : '';

        return [$target, $alt];
    }

    private function resolveImage(string $target, string $currentPagePath): ?string
    {
        $target = trim($target);
        if (!$this->isSafeImageTarget($target)) {
            return null;
        }

        if ($this->isExternalImageUrl($target)) {
            return $target;
        }

        $relativeTarget = ltrim($target, '/');
        if (strpos($target, '/') === 0) {
            $candidate = $this->contentDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeTarget);
        } else {
            $pageDirectory = dirname(str_replace('\\', '/', $currentPagePath));
            if ($pageDirectory === '.' || $pageDirectory === DIRECTORY_SEPARATOR) {
                $pageDirectory = '';
            }

            $base = $this->contentDir;
            if ($pageDirectory !== '') {
                $base .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim($pageDirectory, '/'));
            }

            $candidate = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $target);
        }

        $realPath = realpath($candidate);
        if ($realPath === false || !is_file($realPath) || !Security::isPathInside($realPath, $this->contentDir)) {
            return null;
        }

        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedExtensions, true)) {
            return null;
        }

        $relativePath = $this->relativeContentPath($realPath);
        if ($relativePath === null) {
            return null;
        }

        $version = @filemtime($realPath);
        $size = @filesize($realPath);
        $versionToken = ($version !== false ? dechex($version) : '0')
            . ($size !== false ? '-' . dechex($size) : '');

        return Security::publicUrl('/content/' . $relativePath . '?v=' . $versionToken, $this->publicBasePath);
    }

    private function isSafeImageTarget(string $target): bool
    {
        if ($target === '' || strpos($target, "\0") !== false || preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            return false;
        }

        if ($this->isExternalImageUrl($target)) {
            $safeHref = Security::safeHref($target);
            if ($safeHref === '#') {
                return false;
            }

            $path = parse_url($target, PHP_URL_PATH);
            $extension = strtolower(pathinfo(is_string($path) ? $path : '', PATHINFO_EXTENSION));
            return in_array($extension, $this->allowedExtensions, true);
        }

        if (strpos($target, '\\') !== false || strpos($target, '//') === 0 || strpos($target, ':') !== false) {
            return false;
        }

        $extension = strtolower(pathinfo(parse_url($target, PHP_URL_PATH) ?: $target, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->allowedExtensions, true)) {
            return false;
        }

        return true;
    }

    private function isExternalImageUrl(string $target): bool
    {
        $scheme = parse_url($target, PHP_URL_SCHEME);
        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private function relativeContentPath(string $realPath): ?string
    {
        $realContentDir = realpath($this->contentDir);
        if ($realContentDir === false) {
            return null;
        }

        $base = rtrim($realContentDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($realPath, $base) !== 0) {
            return null;
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($realPath, strlen($base)));
    }

    private function missingHtml(string $target, string $alt): string
    {
        $label = $alt !== '' ? $alt : $target;

        return '<span class="image-missing" data-image-target="' . $this->escape($target) . '">画像が見つかりません: ' . $this->escape($label) . '</span>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
