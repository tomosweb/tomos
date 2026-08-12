<?php
declare(strict_types=1);
namespace Tomos;

final class PublishedMetadata
{
    public static function normalize($value): ?string
    {
        if (is_array($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            return null;
        }

        return $value;
    }

    public static function addIfMissing(string $markdown, string $published): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        if (preg_match('/\A---\n(.*?)\n---(?=\n|\z)/s', $normalized, $matches) !== 1) {
            return "---\npublished: {$published}\n---\n" . $normalized;
        }

        $frontMatter = $matches[1];
        if (preg_match('/^published\s*:/mi', $frontMatter) === 1) return $normalized;

        if (preg_match('/^date\s*:.*$/mi', $frontMatter, $date, PREG_OFFSET_CAPTURE) === 1) {
            $offset = $date[0][1] + strlen($date[0][0]);
            $frontMatter = substr($frontMatter, 0, $offset) . "\npublished: {$published}" . substr($frontMatter, $offset);
        } else {
            $frontMatter .= ($frontMatter === '' ? '' : "\n") . "published: {$published}";
        }

        return "---\n" . $frontMatter . "\n---" . substr($normalized, strlen($matches[0]));
    }

    public static function addInitialMetadata(string $markdown, string $date, string $published): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        if (preg_match('/\A---\n(.*?)\n---(?=\n|\z)/s', $normalized, $matches) !== 1) {
            return "---\ndate: {$date}\npublished: {$published}\n---\n" . $normalized;
        }

        $frontMatter = $matches[1];
        if (preg_match('/^date\s*:\s*(?:["\']\s*["\']\s*)?$/mi', $frontMatter) === 1) {
            $frontMatter = preg_replace_callback(
                '/^([ \t]*)date[ \t]*:[ \t]*.*$/mi',
                static fn (array $matches): string => $matches[1] . 'date: ' . $date,
                $frontMatter,
                1
            ) ?? $frontMatter;
        } elseif (preg_match('/^date\s*:/mi', $frontMatter) !== 1) {
            if (preg_match('/^published\s*:/mi', $frontMatter) === 1) {
                $frontMatter = preg_replace(
                    '/^(\s*published\s*:)/mi',
                    'date: ' . $date . "\n$1",
                    $frontMatter,
                    1
                ) ?? $frontMatter;
            } else {
                $frontMatter .= ($frontMatter === '' ? '' : "\n") . 'date: ' . $date;
            }
        }

        $updated = "---\n" . $frontMatter . "\n---" . substr($normalized, strlen($matches[0]));
        return self::addIfMissing($updated, $published);
    }
}
