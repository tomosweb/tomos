<?php

declare(strict_types=1);

namespace Tomos;

require_once __DIR__ . '/PublishedMetadata.php';
require_once __DIR__ . '/FrontMatterParser.php';

final class ObsidianTagNormalizer
{
    private FrontMatterParser $frontMatterParser;

    public function __construct(?FrontMatterParser $frontMatterParser = null)
    {
        $this->frontMatterParser = $frontMatterParser ?? new FrontMatterParser();
    }

    public function normalize(string $markdown): string
    {
        $lineEnding = strpos($markdown, "\r\n") !== false ? "\r\n" : "\n";
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        $parsed = $this->frontMatterParser->parse($normalized);
        $body = (string) ($parsed['body'] ?? $normalized);
        $inlineTags = $this->extractInlineTags($body);

        if ($inlineTags === []) {
            return $markdown;
        }

        $existingTags = [];
        if (!empty($parsed['has_frontmatter']) && is_array($parsed['metadata'] ?? null)) {
            $metadata = $this->frontMatterParser->buildPageMetadata(
                $parsed['metadata'],
                $body,
                'inbox.md'
            );
            $existingTags = is_array($metadata['tags'] ?? null) ? $metadata['tags'] : [];
        }

        $merged = $this->mergeTags($existingTags, $inlineTags);
        if ($merged === $existingTags) {
            return $markdown;
        }

        if (empty($parsed['has_frontmatter'])) {
            $result = "---\ntags:\n" . $this->tagLines($merged) . "\n---\n\n" . $normalized;
            return $lineEnding === "\n" ? $result : str_replace("\n", $lineEnding, $result);
        }

        $closingPosition = strpos($normalized, "\n---", 4);
        if ($closingPosition === false) {
            return $markdown;
        }

        $frontMatter = substr($normalized, 4, $closingPosition - 4);
        $updatedFrontMatter = $this->replaceTagsField($frontMatter, $merged);
        $result = "---\n" . $updatedFrontMatter . "\n---" . substr($normalized, $closingPosition + 4);

        return $lineEnding === "\n" ? $result : str_replace("\n", $lineEnding, $result);
    }

    /** @return string[] */
    public function extractInlineTags(string $body): array
    {
        $tags = [];
        $seen = [];
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $body));
        $fence = null;

        foreach ($lines as $line) {
            if (preg_match('/^\s*((?:\x60){3,}|~~~+)/', $line, $fenceMatch) === 1) {
                $marker = substr($fenceMatch[1], 0, 3);
                if ($fence === null) {
                    $fence = $marker;
                } elseif ($fence === $marker) {
                    $fence = null;
                }
                continue;
            }
            if ($fence !== null) {
                continue;
            }

            $searchable = preg_replace('/\x60[^\x60\n]*\x60/u', ' ', $line) ?? $line;
            $searchable = preg_replace('/<https?:\/\/[^>]+>/iu', ' ', $searchable) ?? $searchable;
            $searchable = preg_replace('/https?:\/\/\S+/iu', ' ', $searchable) ?? $searchable;
            $searchable = preg_replace('/\]\([^)]*\)/u', ']', $searchable) ?? $searchable;

            if (preg_match_all(
                '/(?<![\\\p{L}\p{N}\p{M}_\/#])#([\p{L}\p{N}\p{M}_-]+(?:\/[\p{L}\p{N}\p{M}_-]+)*)/u',
                $searchable,
                $matches
            ) !== false) {
                foreach ($matches[1] as $candidate) {
                    $tag = trim((string) $candidate, '/');
                    if ($tag === '' || preg_match('/[\p{L}\p{M}_-]/u', $tag) !== 1 || isset($seen[$tag])) {
                        continue;
                    }
                    $seen[$tag] = true;
                    $tags[] = $tag;
                }
            }
        }

        return $tags;
    }

    /** @param string[] $existing @param string[] $inline @return string[] */
    private function mergeTags(array $existing, array $inline): array
    {
        $merged = [];
        $seen = [];

        foreach (array_merge($existing, $inline) as $value) {
            $tag = trim((string) $value);
            if ($tag === '' || isset($seen[$tag])) {
                continue;
            }
            $seen[$tag] = true;
            $merged[] = $tag;
        }

        return $merged;
    }

    /** @param string[] $tags */
    private function replaceTagsField(string $frontMatter, array $tags): string
    {
        $lines = explode("\n", $frontMatter);
        $start = null;
        $end = null;

        foreach ($lines as $index => $line) {
            if (preg_match('/^tags:\s*/', $line) !== 1) {
                continue;
            }
            $start = $index;
            $end = $index + 1;
            while ($end < count($lines)) {
                $next = $lines[$end];
                if (trim($next) === '' || preg_match('/^\s+-\s+/', $next) === 1) {
                    $end++;
                    continue;
                }
                break;
            }
            break;
        }

        $replacement = array_merge(['tags:'], explode("\n", $this->tagLines($tags)));
        if ($start === null || $end === null) {
            if ($frontMatter === '') {
                return implode("\n", $replacement);
            }
            return rtrim($frontMatter, "\n") . "\n" . implode("\n", $replacement);
        }

        array_splice($lines, $start, $end - $start, $replacement);
        return implode("\n", $lines);
    }

    /** @param string[] $tags */
    private function tagLines(array $tags): string
    {
        return implode("\n", array_map(
            static fn (string $tag): string => '  - ' . $tag,
            $tags
        ));
    }
}
