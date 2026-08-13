<?php

declare(strict_types=1);

namespace Tomos;

/**
 * Renders an Inbox Markdown document without making it part of the site.
 *
 * This class deliberately does not use PostUpload, MetadataIndex::save(), or
 * HtmlCache. The only filesystem access to the Inbox document is the read
 * performed by PostInbox::read() at the caller boundary.
 */
final class PostInboxPreview
{
    private array $config;
    private string $rootDir;
    private FrontMatterParser $frontMatterParser;
    private string $publicBasePath;

    public function __construct(array $config, string $rootDir)
    {
        $this->config = $config;
        $this->rootDir = $rootDir;
        $this->frontMatterParser = new FrontMatterParser();
        $this->publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    }

    public function render(string $markdown, string $fileName, string $sourcePath = ''): string
    {
        if (strpos($markdown, "\0") !== false || preg_match('//u', $markdown) !== 1) {
            throw new \RuntimeException('Inbox Markdown is not valid UTF-8.');
        }

        $parsed = $this->frontMatterParser->parse($markdown);
        $metadata = $this->frontMatterParser->buildPageMetadata(
            is_array($parsed['metadata'] ?? null) ? $parsed['metadata'] : [],
            (string) ($parsed['body'] ?? ''),
            $sourcePath !== '' ? $sourcePath : 'inbox/' . $fileName
        );
        $pages = $this->readPublishedPages();
        $contentRaw = $this->withoutDuplicateTitleHeading(
            (string) ($parsed['body'] ?? ''),
            (string) ($metadata['title'] ?? '')
        );

        $wikiLinks = new WikiLinkParser($pages, $this->publicBasePath, $this->readLinkAliases());
        $images = new ImageEmbedParser(
            (string) (($this->config['paths']['content_dir'] ?? '') ?: ($this->rootDir . '/content')),
            $this->publicBasePath
        );
        $contentRaw = $images->replace($contentRaw, $sourcePath !== '' ? $sourcePath : 'inbox/' . $fileName);
        $contentRaw = $wikiLinks->replace($contentRaw);
        $contentHtml = (new MarkdownParser(
            (bool) ($this->config['security']['allow_raw_html'] ?? false),
            $this->publicBasePath
        ))->toHtml($contentRaw);
        $contentHtml = $wikiLinks->restore($contentHtml);
        $contentHtml = $images->restore($contentHtml);

        $internalUrl = '/post/?section=manage&preview_inbox=' . rawurlencode($fileName);
        $page = [
            'title' => (string) ($metadata['title'] ?? $fileName),
            'description' => (string) ($metadata['description'] ?? ''),
            'description_explicit' => !empty($metadata['description_explicit']),
            'url' => $internalUrl,
            'internal_url' => $internalUrl,
            'absolute_url' => '',
            'date' => (string) ($metadata['date'] ?? ''),
            'updated' => (string) ($metadata['updated'] ?? ''),
            'tags' => is_array($metadata['tags'] ?? null) ? $metadata['tags'] : [],
            'tags_html' => $this->tagsHtml(is_array($metadata['tags'] ?? null) ? $metadata['tags'] : []),
            'content' => $contentHtml,
            'path' => '',
            'page_type' => 'markdown_page',
            'draft' => true,
            'track_page' => false,
        ];
        if ($page['description'] === '' && empty($page['description_explicit'])) {
            $page['description'] = (string) (($this->config['site']['description'] ?? ''));
        }

        $navigation = new NavigationBuilder($this->publicBasePath);
        $renderer = new TemplateRenderer($this->config);
        $required = $renderer->requiredVariablesForPage($page);
        $tree = isset($required['nav.tree']) || isset($required['nav.mobile_tree'])
            ? $navigation->tree($pages, $internalUrl, false, !empty($this->config['features']['rss']))
            : '';
        $page['show_updated'] = $page['updated'] !== '' && $page['updated'] !== $page['date'];
        $page['meta_html'] = $this->metaHtml($page['date'], $page['updated']);
        $page['nav'] = [
            'tree' => isset($required['nav.tree']) ? $tree : '',
            'mobile_tree' => isset($required['nav.mobile_tree']) ? $tree : '',
            'sections' => isset($required['nav.sections']) ? $navigation->sectionLinks($pages, $internalUrl) : '',
            'primary_links' => isset($required['nav.primary_links']) ? $navigation->primaryLinks($pages, $internalUrl, !empty($this->config['features']['rss'])) : '',
            'primary_items' => isset($required['nav.primary_items']) ? $navigation->primaryItems($pages, $internalUrl, !empty($this->config['features']['rss'])) : [],
            'breadcrumbs' => $navigation->breadcrumbs($pages, $internalUrl),
        ];
        $page['list'] = [
            'pages' => isset($required['list.pages']) ? $navigation->pageList($pages) : '',
            'latest_pages' => isset($required['list.latest_pages']) ? $navigation->latestPageList($pages, 12) : '',
        ];

        $html = $renderer->renderPage($page);
        $meta = '<meta name="robots" content="noindex, nofollow">';
        return preg_replace('/(<meta charset="utf-8">)/i', '$1' . $meta, $html, 1) ?? $html;
    }

    private function readPublishedPages(): array
    {
        try {
            $index = new MetadataIndex(
                (string) (($this->config['paths']['content_dir'] ?? '') ?: ($this->rootDir . '/content')),
                (string) (($this->config['paths']['cache_dir'] ?? '') ?: ($this->rootDir . '/cache')),
                $this->frontMatterParser,
                false
            );
            // Both methods are read/build-only. Neither writes an index.
            return $index->loadFresh() ?? $index->build();
        } catch (\Throwable $exception) {
            return [];
        }
    }

    private function readLinkAliases(): array
    {
        try {
            return (new LinkAliasIndex((string) (($this->config['paths']['cache_dir'] ?? '') ?: ($this->rootDir . '/cache'))))->load();
        } catch (\Throwable $exception) {
            return ['aliases' => [], 'conflicts' => []];
        }
    }

    private function withoutDuplicateTitleHeading(string $markdown, string $title): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        if ($title === '' || preg_match('/\A[ \t\n]*#\s+([^\n]+)[ \t]*(?:\n|$)/u', $normalized, $matches) !== 1 || trim($matches[1]) !== trim($title)) {
            return $normalized;
        }

        return ltrim(substr($normalized, strlen($matches[0])), "\n");
    }

    private function metaHtml(string $date, string $updated): string
    {
        $items = [];
        if ($date !== '') {
            $items[] = '<time datetime="' . $this->escape($date) . '">公開日: ' . $this->escape($date) . '</time>';
        }
        if ($updated !== '' && $updated !== $date) {
            $items[] = '<time datetime="' . $this->escape($updated) . '">更新日: ' . $this->escape($updated) . '</time>';
        }

        return $items === [] ? '' : '<div class="page-meta">' . implode(' ', $items) . '</div>';
    }

    private function tagsHtml(array $tags): string
    {
        $links = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '') {
                continue;
            }
            $href = rtrim(Security::publicUrl('/tags/', $this->publicBasePath), '/') . '/' . str_replace('.', '%2E', rawurlencode($tag));
            $links[$tag] = '<a href="' . $this->escape($href) . '" class="tag-link">' . $this->escape($tag) . '</a>';
        }

        return $links === [] ? '' : '<footer class="page-tags" aria-label="タグ">' . implode('', $links) . '</footer>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
