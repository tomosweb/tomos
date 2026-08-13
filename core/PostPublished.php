<?php

declare(strict_types=1);

namespace Tomos;

if (!class_exists(__NAMESPACE__ . '\\PostBasicPage')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'PostBasicPage.php';
}

final class PostPublished
{
    private string $contentDir;
    private string $cacheDir;
    private FrontMatterParser $frontMatterParser;

    public function __construct(array $config, string $rootDir)
    {
        $this->contentDir = (string) (($config['paths']['content_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'content'));
        $this->cacheDir = (string) (($config['paths']['cache_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'cache'));
        $this->frontMatterParser = new FrontMatterParser();
    }

    /**
     * @return array{ok:bool,error:string,items:array<int,array<string,mixed>>,years:string[],query:string,year:string,page:int,total:int,total_pages:int}
     */
    public function list(string $query = '', string $year = '', int $page = 1, int $perPage = 50): array
    {
        $query = trim($query);
        $year = preg_match('/\A\d{4}\z/', trim($year)) === 1 ? trim($year) : '';
        $perPage = max(1, $perPage);

        try {
            $pages = $this->loadPages();
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'error' => '公開済み投稿の一覧情報を準備できませんでした。Tomosのファイル構成を確認してください。',
                'items' => [],
                'years' => [],
                'query' => $query,
                'year' => $year,
                'page' => 1,
                'total' => 0,
                'total_pages' => 0,
            ];
        }

        $years = $this->yearsFromPages($pages);
        $matches = [];
        foreach ($pages as $pageEntry) {
            if (!is_array($pageEntry) || !empty($pageEntry['draft'])) {
                continue;
            }

            $date = (string) ($pageEntry['date'] ?? '');
            if ($year !== '' && $this->dateYear($date) !== $year) {
                continue;
            }

            $path = (string) ($pageEntry['path'] ?? '');
            $haystacks = [
                (string) ($pageEntry['title'] ?? ''),
                basename($path),
                $path,
                (string) ($pageEntry['search_text'] ?? ''),
            ];
            if ($query !== '' && !$this->containsAny($haystacks, $query)) {
                continue;
            }

            $matches[] = $this->displayEntry($pageEntry);
        }

        $total = count($matches);
        $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;
        $page = $totalPages > 0 ? max(1, min($page, $totalPages)) : 1;

        return [
            'ok' => true,
            'error' => '',
            'items' => array_slice($matches, ($page - 1) * $perPage, $perPage),
            'years' => $years,
            'query' => $query,
            'year' => $year,
            'page' => $page,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @return string[]
     */
    public function years(): array
    {
        try {
            return $this->yearsFromPages($this->loadPages());
        } catch (\Throwable $exception) {
            return [];
        }
    }

    public function isPublishedPath(string $contentPath): bool
    {
        $contentPath = trim(str_replace('\\', '/', $contentPath));
        if ($contentPath === '') {
            return false;
        }

        try {
            foreach ($this->loadPages() as $page) {
                if (is_array($page) && empty($page['draft']) && (string) ($page['path'] ?? '') === $contentPath) {
                    return true;
                }
            }
        } catch (\Throwable $exception) {
            return false;
        }

        return false;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadPages(): array
    {
        $index = new MetadataIndex($this->contentDir, $this->cacheDir, $this->frontMatterParser, false);
        $pages = $index->loadFresh();
        if ($pages === null) {
            $pages = $index->rebuild();
        }

        $pages = array_values(array_filter($pages, static fn ($page): bool => is_array($page)));
        return PageSorter::sort($pages);
    }

    /** @param array<int,array<string,mixed>> $pages @return string[] */
    private function yearsFromPages(array $pages): array
    {
        $years = [];
        foreach ($pages as $page) {
            if (!is_array($page) || !empty($page['draft'])) {
                continue;
            }
            $year = $this->dateYear((string) ($page['date'] ?? ''));
            if ($year !== '') {
                $years[$year] = true;
            }
        }

        $years = array_map('strval', array_keys($years));
        rsort($years, SORT_STRING);
        return $years;
    }

    private function dateYear(string $date): string
    {
        if (preg_match('/\A(\d{4})-\d{2}-\d{2}\z/', trim($date), $matches) !== 1) {
            return '';
        }

        $timestamp = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            return '';
        }

        return (string) $matches[1];
    }

    /** @param array<string,mixed> $page */
    private function displayEntry(array $page): array
    {
        $path = (string) ($page['path'] ?? '');
        return [
            'path' => $path,
            'filename' => basename($path),
            'title' => (string) ($page['title'] ?? ''),
            'date' => (string) ($page['date'] ?? ''),
            'updated' => (string) ($page['updated'] ?? ''),
            'tags' => is_array($page['tags'] ?? null) ? array_values(array_map('strval', $page['tags'])) : [],
            'url' => (string) ($page['url'] ?? ''),
            'protected' => PostBasicPage::isProtectedContentPath($path),
        ];
    }

    private function containsAny(array $haystacks, string $query): bool
    {
        foreach ($haystacks as $haystack) {
            if (function_exists('mb_stripos')) {
                if (mb_stripos((string) $haystack, $query, 0, 'UTF-8') !== false) {
                    return true;
                }
            } elseif (stripos((string) $haystack, $query) !== false) {
                return true;
            }
        }

        return false;
    }
}
