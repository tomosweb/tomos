<?php

declare(strict_types=1);

namespace Tomos;

/**
 * Shared publishing semantics for Core edition and future GitHub edition.
 *
 * Runtime concerns such as HTTP responses, request routing, caches, sessions,
 * authentication and deployment intentionally remain outside this class.
 */
final class PublishingEngine
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<int, array<string, mixed>> $pages
     * @param array<string, mixed> $linkAliases
     * @return array{html:string,toc:string,related_items:array<int,array{title:string,url:string}>}
     */
    public function renderMarkdownContent(
        array $page,
        MarkdownParser $markdownParser,
        array $pages,
        string $publicBasePath,
        array $linkAliases
    ): array {
        $sourcePath = (string) ($page['path'] ?? '');
        $wikiLinkParser = new WikiLinkParser($pages, $publicBasePath, $linkAliases);
        $imageEmbedParser = new ImageEmbedParser((string) ($this->config['paths']['content_dir'] ?? ''), $publicBasePath);

        $contentRaw = $this->contentWithoutDuplicateTitleHeading(
            (string) ($page['content_raw'] ?? ''),
            (string) ($page['title'] ?? '')
        );
        $contentRaw = $imageEmbedParser->replace($contentRaw, $sourcePath !== '' ? $sourcePath : 'index.md');
        $contentRaw = $wikiLinkParser->replace($contentRaw);

        $rendered = $markdownParser->toHtmlWithToc($contentRaw);
        $contentHtml = $wikiLinkParser->restore($rendered['html']);
        $contentHtml = $imageEmbedParser->restore($contentHtml);

        return [
            'html' => $contentHtml,
            'toc' => $rendered['toc'],
            'related_items' => $this->relatedItemsFromHtml(
                $contentHtml,
                (string) ($page['url'] ?? ''),
                $pages,
                $publicBasePath
            ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @param array<string, mixed> $page
     */
    public function renderPage(
        TemplateRenderer $renderer,
        NavigationBuilder $navigation,
        array $pages,
        array $page
    ): string {
        $currentUrl = (string) ($page['internal_url'] ?? '');
        $date = trim((string) ($page['date'] ?? ''));
        $updated = trim((string) ($page['updated'] ?? ''));
        $page['show_updated'] = $updated !== '' && $updated !== $date;
        $page['meta_html'] = $this->pageMetaHtml($date, $updated);

        $folder = ($page['page_type'] ?? '') === 'virtual_folder_index'
            ? trim((string) ($page['folder_path'] ?? ''), '/')
            : $this->folderFromIndexPath((string) ($page['path'] ?? ''));
        $folderPageNumber = (int) ($page['folder_page_number'] ?? 1);
        $requiredVariables = $renderer->requiredVariablesForPage($page);
        $page['folder_pages_html'] = $folder !== null && isset($requiredVariables['page.folder_pages_html'])
            ? $navigation->folderPageList($pages, $folder, $folderPageNumber, 30)
            : '';

        $needsTree = isset($requiredVariables['nav.tree']) || isset($requiredVariables['nav.mobile_tree']);
        $tree = $needsTree
            ? $navigation->tree($pages, $currentUrl, false, !empty($this->config['features']['rss']))
            : '';

        $needsTagContext = isset($requiredVariables['tag.list']) || isset($requiredVariables['tag.items']);
        $tagIndex = $needsTagContext ? new TagIndex($pages, $this->publicBasePath()) : null;

        return $renderer->renderPage($page + [
            'toc' => '',
            'tags_html' => '',
            'related_items' => [],
            'tag' => [
                'list' => isset($requiredVariables['tag.list']) && $tagIndex !== null
                    ? $tagIndex->indexHtml()
                    : '',
                'items' => isset($requiredVariables['tag.items']) && $tagIndex !== null
                    ? $tagIndex->items()
                    : [],
            ],
            'nav' => [
                'tree' => isset($requiredVariables['nav.tree']) ? $tree : '',
                'mobile_tree' => isset($requiredVariables['nav.mobile_tree']) ? $tree : '',
                'sections' => isset($requiredVariables['nav.sections'])
                    ? $navigation->sectionLinks($pages, $currentUrl)
                    : '',
                'primary_links' => isset($requiredVariables['nav.primary_links'])
                    ? $navigation->primaryLinks($pages, $currentUrl, !empty($this->config['features']['rss']))
                    : '',
                'primary_items' => isset($requiredVariables['nav.primary_items'])
                    ? $navigation->primaryItems($pages, $currentUrl, !empty($this->config['features']['rss']))
                    : [],
                'breadcrumbs' => $page['breadcrumbs'] ?? '',
            ],
            'list' => [
                'pages' => $folder === null && isset($requiredVariables['list.pages'])
                    ? $navigation->pageList($pages)
                    : '',
                'latest_pages' => isset($requiredVariables['list.latest_pages'])
                    ? $navigation->latestPageList($pages, 12)
                    : '',
            ],
        ]);
    }

    /**
     * @param array<int, mixed> $tags
     */
    public function pageTagsHtml(array $tags, string $publicBasePath): string
    {
        $clean = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $clean[$tag] = $tag;
            }
        }

        if ($clean === []) {
            return '';
        }

        $html = '<footer class="page-tags" aria-label="タグ">';
        foreach (array_values($clean) as $tag) {
            $slug = str_replace('.', '%2E', rawurlencode($tag));
            $href = rtrim(Security::publicUrl('/tags/', $publicBasePath), '/') . '/' . $slug;
            $html .= '<a href="' . $this->escape($href) . '" class="tag-link">' . $this->escape($tag) . '</a>';
        }
        $html .= '</footer>';

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array{title:string,url:string}>
     */
    public function relatedItemsFromHtml(
        string $html,
        string $currentUrl,
        array $pages,
        string $publicBasePath
    ): array {
        $pagesByUrl = [];
        foreach ($pages as $page) {
            if (!is_array($page) || !empty($page['draft'])) {
                continue;
            }

            $url = $this->normalizePagePath((string) ($page['url'] ?? ''));
            if ($url === null) {
                continue;
            }

            $pagesByUrl[$url] = $page;
        }

        $current = $this->normalizePagePath($currentUrl);
        if ($pagesByUrl === [] || $current === null) {
            return [];
        }

        preg_match_all(
            '/<a\\b[^>]*\\bhref\\s*=\\s*(["\\\'])(.*?)\\1[^>]*>.*?<\\/a>/isu',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $related = [];
        $seen = [];
        foreach ($matches as $match) {
            $anchorHtml = (string) ($match[0] ?? '');
            if (stripos($anchorHtml, '<img') !== false) {
                continue;
            }

            $path = $this->internalLinkPath((string) ($match[2] ?? ''), $current, $publicBasePath);
            if ($path === null || $path === $current || isset($seen[$path]) || !isset($pagesByUrl[$path])) {
                continue;
            }

            $target = $pagesByUrl[$path];
            $title = trim((string) ($target['title'] ?? ''));
            if ($title === '') {
                $title = (string) ($target['path'] ?? 'Untitled');
            }

            $seen[$path] = true;
            $related[] = [
                'title' => $title,
                'url' => Security::publicUrl($path, $publicBasePath),
            ];
        }

        return $related;
    }

    private function contentWithoutDuplicateTitleHeading(string $markdown, string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return $markdown;
        }

        $normalizedMarkdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        if (preg_match('/\\A([ \\t\\n]*)#\\s+([^\\n]+)[ \\t]*(?:\\n|$)/u', $normalizedMarkdown, $matches) !== 1) {
            return $normalizedMarkdown;
        }

        if (trim($matches[2]) !== $title) {
            return $normalizedMarkdown;
        }

        return ltrim(substr($normalizedMarkdown, strlen($matches[0])), "\n");
    }

    private function pageMetaHtml(string $date, string $updated): string
    {
        $items = [];
        if ($date !== '') {
            $escapedDate = $this->escape($date);
            $items[] = '<time datetime="' . $escapedDate . '">公開日: ' . $escapedDate . '</time>';
        }

        if ($updated !== '' && $updated !== $date) {
            $escapedUpdated = $this->escape($updated);
            $items[] = '<time datetime="' . $escapedUpdated . '">更新日: ' . $escapedUpdated . '</time>';
        }

        if ($items === []) {
            return '';
        }

        return '<div class="page-meta">' . implode(' ', $items) . '</div>';
    }

    private function folderFromIndexPath(string $path): ?string
    {
        if ($path === '' || $path === 'index.md' || substr($path, -9) !== '/index.md') {
            return null;
        }

        $folder = substr($path, 0, -9);
        return $folder === '' ? null : $folder;
    }

    private function internalLinkPath(string $href, string $currentUrl, string $publicBasePath): ?string
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($href === '' || $href[0] === '#' || strpos($href, '//') === 0) {
            return null;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);
        if ($scheme !== null) {
            return null;
        }

        $positions = array_filter([strpos($href, '?'), strpos($href, '#')], static function ($position): bool {
            return $position !== false;
        });
        if ($positions !== []) {
            $href = substr($href, 0, min($positions));
        }
        if ($href === '') {
            return null;
        }

        if ($href[0] !== '/') {
            $base = substr($currentUrl, -1) === '/'
                ? rtrim($currentUrl, '/')
                : dirname($currentUrl);
            $href = rtrim($base, '/') . '/' . ltrim($href, '/');
        }

        $publicBasePath = Security::normalizeBasePath($publicBasePath);
        if ($publicBasePath !== '') {
            if ($href === $publicBasePath) {
                $href = '/';
            } elseif (strpos($href, $publicBasePath . '/') === 0) {
                $href = substr($href, strlen($publicBasePath));
            } else {
                return null;
            }
        }

        return $this->normalizePagePath($href);
    }

    private function normalizePagePath(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $positions = array_filter([strpos($url, '?'), strpos($url, '#')], static function ($position): bool {
            return $position !== false;
        });
        if ($positions !== []) {
            $url = substr($url, 0, min($positions));
        }
        if ($url === '' || $url[0] !== '/') {
            $url = '/' . $url;
        }

        $validation = Security::validateUrlPath($url);
        if (empty($validation['is_valid'])) {
            return null;
        }

        return (string) $validation['path'];
    }

    private function publicBasePath(): string
    {
        $publicBasePath = (string) ($this->config['site']['public_base_path'] ?? '');
        if ($publicBasePath !== '') {
            return $publicBasePath;
        }

        return (string) ($this->config['site']['base_path'] ?? '');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
