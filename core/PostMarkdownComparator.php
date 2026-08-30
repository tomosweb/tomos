<?php

declare(strict_types=1);

namespace Tomos;

final class PostMarkdownComparator
{
    public static function equivalent(string $left, string $right, string $contentPath = 'post.md'): bool
    {
        $parser = new FrontMatterParser();
        $leftParsed = $parser->parse($left);
        $rightParsed = $parser->parse($right);
        $leftFrontMatter = $parser->buildPageMetadata($leftParsed['metadata'], $leftParsed['body'], $contentPath);
        $rightFrontMatter = $parser->buildPageMetadata($rightParsed['metadata'], $rightParsed['body'], $contentPath);
        $leftBody = (string) $leftParsed['body'];
        $rightBody = (string) $rightParsed['body'];

        unset($leftFrontMatter['published'], $rightFrontMatter['published']);
        $rightRawMetadata = is_array($rightParsed['metadata'] ?? null) ? $rightParsed['metadata'] : [];
        if (($rightRawMetadata['date'] ?? null) === '' || !array_key_exists('date', $rightRawMetadata)) {
            unset($leftFrontMatter['date']);
            unset($rightFrontMatter['date']);
        }

        return self::canonical($leftFrontMatter) === self::canonical($rightFrontMatter)
            && self::normalizeBody($leftBody) === self::normalizeBody($rightBody);
    }

    private static function normalizeBody(string $body): string
    {
        return rtrim(str_replace(["\r\n", "\r"], "\n", $body), "\n");
    }

    private static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = (string) $key . '=' . self::canonical($item);
            }
            return implode("\n", $parts);
        }

        return trim((string) $value);
    }
}
