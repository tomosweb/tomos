<?php
declare(strict_types=1);
namespace Tomos;

final class PageSorter
{
    /** Standard article order: date DESC, published DESC, path ASC. */
    public static function compare(array $a, array $b): int
    {
        $dateCompare = strcmp(self::date($b), self::date($a));
        if ($dateCompare !== 0) return $dateCompare;

        $publishedA = PublishedMetadata::normalize($a['published'] ?? null);
        $publishedB = PublishedMetadata::normalize($b['published'] ?? null);
        if ($publishedA !== $publishedB) {
            if ($publishedA === null) return 1;
            if ($publishedB === null) return -1;
            return strcmp($publishedB, $publishedA);
        }

        return strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? ''));
    }

    public static function sort(array $pages): array
    {
        usort($pages, [self::class, 'compare']);
        return $pages;
    }

    private static function date(array $page): string
    {
        $date = trim((string) ($page['date'] ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1 ? substr($date, 0, 10) : '';
    }
}
