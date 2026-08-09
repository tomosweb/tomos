<?php

declare(strict_types=1);

namespace Tomos;

final class TagIndex
{
    private array $pages;
    private string $publicBasePath;
    private array $tags = [];

    public function __construct(array $pages, string $publicBasePath = '')
    {
        $this->publicBasePath = $publicBasePath;
        $this->pages = array_values(array_filter($pages, function ($page): bool {
            return is_array($page) && empty($page['draft']);
        }));

        $this->build();
    }

    public function pageTagsHtml(array $tags): string
    {
        $tags = $this->cleanTags($tags);
        if ($tags === []) {
            return '';
        }

        $html = '<footer class="page-tags" aria-label="タグ">';
        foreach ($tags as $tag) {
            $html .= '<a href="' . $this->escape($this->tagUrl($tag)) . '" class="tag-link">' . $this->escape($tag) . '</a>';
        }
        $html .= '</footer>';

        return $html;
    }

    public function indexHtml(): string
    {
        if ($this->tags === []) {
            return '<section class="tag-index"><p>タグはまだありません。</p></section>';
        }

        $html = '<section class="tag-index"><ul>';
        foreach ($this->tags as $tag => $pages) {
            $html .= '<li><a href="' . $this->escape($this->tagUrl($tag)) . '" class="tag-link">' . $this->escape($tag) . '</a> <span class="tag-count">' . count($pages) . '</span></li>';
        }
        $html .= '</ul></section>';

        return $html;
    }

    public function tagPageHtml(string $tag): string
    {
        $pages = $this->pagesForTag($tag);
        if ($pages === []) {
            return '<section class="tag-page"><p>このタグのページはありません。</p></section>';
        }

        usort($pages, [$this, 'comparePages']);

        $html = '<section class="tag-page"><ul class="tag-page-list">';
        foreach ($pages as $page) {
            $title = $this->pageTitle($page);
            $description = trim((string) ($page['description'] ?? ''));
            $href = Security::publicUrl((string) ($page['url'] ?? '/'), $this->publicBasePath);
            $html .= '<li><a href="' . $this->escape($href) . '">' . $this->escape($title) . '</a>';
            if ($description !== '') {
                $html .= '<p>' . $this->escape($description) . '</p>';
            }
            $html .= '</li>';
        }
        $html .= '</ul></section>';

        return $html;
    }

    public function resolveTag(string $slug): ?string
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return null;
        }

        $decoded = rawurldecode($slug);
        foreach (array_keys($this->tags) as $tag) {
            if ($tag === $decoded || $this->slug($tag) === $slug) {
                return $tag;
            }
        }

        return null;
    }

    public function breadcrumbs(?string $tag = null): string
    {
        $html = '<nav class="breadcrumbs" aria-label="パンくず">';
        $html .= '<a href="' . $this->escape(Security::publicUrl('/', $this->publicBasePath)) . '">Home</a>';
        $html .= ' <span aria-hidden="true">/</span> ';

        if ($tag === null) {
            $html .= '<span>タグ一覧</span>';
        } else {
            $html .= '<a href="' . $this->escape(Security::publicUrl('/tags/', $this->publicBasePath)) . '">タグ一覧</a>';
            $html .= ' <span aria-hidden="true">/</span> ';
            $html .= '<span>' . $this->escape($tag) . '</span>';
        }

        $html .= '</nav>';

        return $html;
    }

    public function tagUrl(string $tag): string
    {
        return rtrim(Security::publicUrl('/tags/', $this->publicBasePath), '/') . '/' . $this->slug($tag);
    }

    private function build(): void
    {
        foreach ($this->pages as $page) {
            foreach ($this->cleanTags($page['tags'] ?? []) as $tag) {
                $this->tags[$tag][] = $page;
            }
        }

        ksort($this->tags, SORT_NATURAL);
    }

    private function pagesForTag(string $tag): array
    {
        return $this->tags[$tag] ?? [];
    }

    private function cleanTags(array $tags): array
    {
        $clean = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') {
                continue;
            }
            $clean[$tag] = $tag;
        }

        return array_values($clean);
    }

    private function slug(string $tag): string
    {
        return str_replace('.', '%2E', rawurlencode(trim($tag)));
    }

    private function comparePages(array $a, array $b): int
    {
        return PageSorter::compare($a, $b);
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

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
