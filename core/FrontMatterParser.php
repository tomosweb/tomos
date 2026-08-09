<?php

declare(strict_types=1);

namespace Tomos;

final class FrontMatterParser
{
    public function parse(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        if (strpos($markdown, "---\n") !== 0) {
            return [
                'metadata' => [],
                'body' => $markdown,
                'has_frontmatter' => false,
            ];
        }

        $closingPosition = strpos($markdown, "\n---", 4);
        if ($closingPosition === false) {
            return [
                'metadata' => [],
                'body' => $markdown,
                'has_frontmatter' => false,
            ];
        }

        $afterClosing = substr($markdown, $closingPosition + 4, 1);
        if ($afterClosing !== '' && $afterClosing !== "\n") {
            return [
                'metadata' => [],
                'body' => $markdown,
                'has_frontmatter' => false,
            ];
        }

        $frontMatter = substr($markdown, 4, $closingPosition - 4);
        $metadata = $this->parseMetadata($frontMatter);
        $metadata['__frontmatter_keys'] = array_keys($metadata);
        $body = substr($markdown, $closingPosition + 4);
        if (strpos($body, "\n") === 0) {
            $body = substr($body, 1);
        }

        return [
            'metadata' => $metadata,
            'body' => $body,
            'has_frontmatter' => true,
        ];
    }

    public function buildPageMetadata(array $metadata, string $body, string $contentPath): array
    {
        $frontMatterKeys = is_array($metadata['__frontmatter_keys'] ?? null) ? $metadata['__frontmatter_keys'] : [];
        $descriptionExplicit = in_array('description', $frontMatterKeys, true);
        $metadata = $this->normalizeMetadata($metadata);
        $metadata['description_explicit'] = $descriptionExplicit;

        if ($metadata['title'] === '') {
            $metadata['title'] = $this->extractFirstHeading($body) ?? $this->titleFromPath($contentPath);
        }

        if ($metadata['description'] === '' && !$descriptionExplicit) {
            $metadata['description'] = $this->excerptFromMarkdown($body);
        }

        return $metadata;
    }

    public function extractFirstHeading(string $markdown): ?string
    {
        if (preg_match('/^#\s+(.+)$/m', $markdown, $matches) !== 1) {
            return null;
        }

        return trim($this->stripInlineMarkdown($matches[1]));
    }

    public function excerptFromMarkdown(string $markdown, int $limit = 140): string
    {
        $markdown = preg_replace('/```.*?```/s', ' ', $markdown) ?? $markdown;
        $lines = explode("\n", $markdown);
        $parts = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^#{1,6}\s+/', $line) === 1) {
                continue;
            }
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $line) === 1) {
                continue;
            }

            $parts[] = $this->stripInlineMarkdown($line);
        }

        $text = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
        if ($text === '') {
            return '';
        }

        return $this->truncate($text, $limit);
    }

    private function parseMetadata(string $frontMatter): array
    {
        $metadata = [];
        $currentKey = null;
        $lines = explode("\n", $frontMatter);

        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $matches) === 1) {
                $currentKey = $matches[1];
                $metadata[$currentKey] = trim($matches[2]);
                continue;
            }

            if ($currentKey !== null && preg_match('/^\s*-\s*(.+)$/', $line, $matches) === 1) {
                if (!is_array($metadata[$currentKey])) {
                    $metadata[$currentKey] = [];
                }
                $metadata[$currentKey][] = trim($matches[1]);
                continue;
            }

            if (trim($line) !== '') {
                $currentKey = null;
            }
        }

        return $metadata;
    }

    private function normalizeMetadata(array $metadata): array
    {
        return [
            'title' => $this->cleanScalar($metadata['title'] ?? ''),
            'date' => $this->cleanNullableScalar($metadata['date'] ?? null),
            'published' => PublishedMetadata::normalize($metadata['published'] ?? null),
            'updated' => $this->cleanNullableScalar($metadata['updated'] ?? null),
            'tags' => $this->normalizeTags($metadata['tags'] ?? []),
            'description' => $this->cleanScalar($metadata['description'] ?? ''),
            'draft' => $this->toBoolean($metadata['draft'] ?? false),
        ];
    }

    private function normalizeTags($value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $value = $this->cleanScalar($value);
            if ($value === '') {
                $items = [];
            } elseif (preg_match('/^\[(.*)\]$/', $value, $matches) === 1) {
                $items = explode(',', $matches[1]);
            } else {
                $items = explode(',', $value);
            }
        }

        $tags = [];
        $seen = [];
        foreach ($items as $item) {
            $tag = $this->cleanScalar($item);
            if ($tag === '' || isset($seen[$tag])) {
                continue;
            }

            $seen[$tag] = true;
            $tags[] = $tag;
        }

        return $tags;
    }

    private function toBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower($this->cleanScalar($value));
        return in_array($normalized, ['true', '1', 'yes'], true);
    }

    private function cleanNullableScalar($value): ?string
    {
        $cleaned = $this->cleanScalar($value);
        return $cleaned === '' ? null : $cleaned;
    }

    private function cleanScalar($value): string
    {
        if (is_array($value)) {
            return '';
        }

        $value = trim((string) $value);
        return trim($value, "\"'");
    }

    private function titleFromPath(string $contentPath): string
    {
        $filename = basename($contentPath, '.md');
        if ($filename !== 'index') {
            return $filename;
        }

        $dirname = basename(dirname($contentPath));
        return $dirname === '.' ? 'index' : $dirname;
    }

    private function stripInlineMarkdown(string $text): string
    {
        $text = preg_replace('/`([^`]+)`/', '$1', $text) ?? $text;
        $text = preg_replace('/!\[([^\]]*)\]\([^)]+\)/', '$1', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text) ?? $text;
        $text = preg_replace('/^\s*>\s*/u', '', $text) ?? $text;
        $text = preg_replace('/^\s*[-*+]\s+/u', '', $text) ?? $text;
        $text = preg_replace('/^\s*\d+\.\s+/u', '', $text) ?? $text;
        $text = preg_replace('/[*_~#]+/u', '', $text) ?? $text;
        $text = strip_tags($text);

        return trim($text);
    }

    private function truncate(string $text, int $limit): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $limit
                ? mb_substr($text, 0, $limit, 'UTF-8')
                : $text;
        }

        return strlen($text) > $limit ? substr($text, 0, $limit) : $text;
    }
}
