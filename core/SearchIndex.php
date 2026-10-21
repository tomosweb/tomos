<?php

declare(strict_types=1);

namespace Tomos;

final class SearchIndex
{
    private array $pages;
    private string $publicBasePath;
    private string $query = '';

    public function __construct(array $pages, string $publicBasePath = '')
    {
        $this->publicBasePath = $publicBasePath;
        $this->pages = array_values(array_filter($pages, function ($page): bool {
            return is_array($page) && empty($page['draft']);
        }));
    }

    public function normalizeQuery(string $query): string
    {
        $query = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $query) ?? '';
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');

        return $this->truncate($query, 100);
    }

    public function search(string $query): array
    {
        $this->query = $this->normalizeQuery($query);
        $terms = $this->terms($this->query);
        if ($terms === []) {
            return [];
        }

        $results = [];
        foreach ($this->pages as $page) {
            $haystack = $this->pageSearchText($page);
            if (!$this->containsAllTerms($haystack, $terms)) {
                continue;
            }

            $page['_search_score'] = $this->score($page, $terms);
            $results[] = $page;
        }

        usort($results, function (array $a, array $b): int {
            $scoreA = (int) ($a['_search_score'] ?? 0);
            $scoreB = (int) ($b['_search_score'] ?? 0);
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            $dateCompare = strcmp($this->pageSortDate($b), $this->pageSortDate($a));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return strcmp($this->pageTitle($a), $this->pageTitle($b));
        });

        return $results;
    }

    public function pageHtml(string $query): string
    {
        $query = $this->normalizeQuery($query);
        $results = $this->search($query);

        $html = '<section class="search-page">';
        $html .= '<h1>検索</h1>';
        $html .= $this->formHtml($query);

        if ($query === '') {
            $html .= '<p class="search-summary">検索語を入力してください。</p>';
            $html .= '</section>';
            return $html;
        }

        if ($results === []) {
            $html .= '<p class="search-summary">「' . $this->escape($query) . '」に一致するページはありませんでした。</p>';
            $html .= '</section>';
            return $html;
        }

        $html .= '<p class="search-summary">「' . $this->escape($query) . '」の検索結果: ' . count($results) . '件</p>';
        $html .= '<ul class="search-results">';
        foreach ($results as $page) {
            $href = Security::publicUrl((string) ($page['url'] ?? '/'), $this->publicBasePath);
            $description = trim((string) ($page['description'] ?? ''));
            if ($description === '') {
                $description = trim((string) ($page['excerpt'] ?? ''));
            }

            $html .= '<li>';
            $html .= '<a href="' . $this->escape($href) . '">' . $this->escape($this->pageTitle($page)) . '</a>';
            if ($description !== '') {
                $html .= '<p>' . $this->escape($description) . '</p>';
            }
            $html .= '</li>';
        }
        $html .= '</ul></section>';

        return $html;
    }

    public function breadcrumbs(): string
    {
        $html = '<nav class="breadcrumbs" aria-label="パンくず">';
        $html .= '<a href="' . $this->escape(Security::publicUrl('/', $this->publicBasePath)) . '">Home</a>';
        $html .= ' <span aria-hidden="true">/</span> ';
        $html .= '<span>検索</span>';
        $html .= '</nav>';

        return $html;
    }

    private function formHtml(string $query): string
    {
        $action = Security::publicUrl('/search/', $this->publicBasePath);

        return '<form action="' . $this->escape($action) . '" method="get" class="search-form">'
            . '<label for="search-q">検索語</label>'
            . '<input id="search-q" type="search" name="q" value="' . $this->escape($query) . '">'
            . '<button type="submit">検索</button>'
            . '</form>';
    }

    private function terms(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $this->lower($query), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? array_values(array_unique($parts)) : [];
    }

    private function containsAllTerms(string $text, array $terms): bool
    {
        $text = $this->lower($text);
        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            if (strpos($text, $term) === false) {
                return false;
            }
        }

        return true;
    }

    private function score(array $page, array $terms): int
    {
        $score = 0;
        $fields = [
            'title' => 40,
            'tags' => 30,
            'description' => 20,
            'search_text' => 10,
            'excerpt' => 5,
        ];

        foreach ($fields as $field => $weight) {
            $text = $field === 'tags'
                ? implode(' ', array_map('strval', is_array($page['tags'] ?? null) ? $page['tags'] : []))
                : (string) ($page[$field] ?? '');

            foreach ($terms as $term) {
                if ($term !== '' && strpos($this->lower($text), $term) !== false) {
                    $score += $weight;
                }
            }
        }

        return $score;
    }

    private function pageSearchText(array $page): string
    {
        $parts = [
            (string) ($page['title'] ?? ''),
            (string) ($page['description'] ?? ''),
            (string) ($page['excerpt'] ?? ''),
            implode(' ', array_map('strval', is_array($page['tags'] ?? null) ? $page['tags'] : [])),
            (string) ($page['url'] ?? ''),
            (string) ($page['path'] ?? ''),
            (string) ($page['search_text'] ?? ''),
        ];

        return implode(' ', $parts);
    }

    private function pageSortDate(array $page): string
    {
        foreach (['date', 'updated'] as $key) {
            $value = trim((string) ($page[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return str_pad((string) ((int) ($page['mtime'] ?? 0)), 10, '0', STR_PAD_LEFT);
    }

    private function pageTitle(array $page): string
    {
        $title = trim((string) ($page['title'] ?? ''));
        return $title !== '' ? $title : (string) ($page['path'] ?? 'Untitled');
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function truncate(string $text, int $limit): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') : $text;
        }

        return strlen($text) > $limit ? substr($text, 0, $limit) : $text;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
