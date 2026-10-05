<?php

declare(strict_types=1);

namespace Tomos;

final class App
{
    private array $config;
    private string $ga4Nonce = '';
    private ?ThemeSettings $themeSettings = null;
    private PublishingEngine $publishingEngine;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->publishingEngine = new PublishingEngine($config);
        if (Ga4::measurementId($config) !== '') {
            try {
                $this->ga4Nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            } catch (\Throwable $exception) {
                $this->ga4Nonce = '';
            }
        }
    }

    public function run(string $requestUri): void
    {
        $this->applyRuntimeSettings();
        $this->sendSecurityHeaders();
        $performance = new PerformanceLogger($this->config);

        try {
        $basePath = (string) ($this->config['site']['base_path'] ?? '');
        $publicBasePath = $this->publicBasePath();
        $router = new Router($basePath);
        $frontMatterParser = new FrontMatterParser();
        try {
            (new PostInboxAutoPublisher(
                new PostInbox($this->config, dirname(__DIR__)),
                $this->config,
                dirname(__DIR__)
            ))->process(null, '');
        } catch (\Throwable $exception) {
            // Public page rendering must continue when best-effort Inbox processing fails.
        }
        $metadataIndex = $this->createMetadataIndex($frontMatterParser);
        $markdownParser = new MarkdownParser(
            (bool) $this->config['security']['allow_raw_html'],
            $publicBasePath,
            (string) ($this->config['paths']['cache_dir'] ?? '')
        );
        $htmlCache = new HtmlCache(
            (string) $this->config['paths']['cache_dir'],
            (bool) ($this->config['features']['html_cache'] ?? false)
        );
        $renderer = new TemplateRenderer($this->config, $this->ga4Nonce);

        $route = $router->resolve($requestUri);
        $performance->lap('route_resolve');
        $pages = $metadataIndex !== null ? $this->loadPages($metadataIndex, $performance) : [];
        $performance->lap('pages_ready');
        $navigation = new NavigationBuilder($publicBasePath, $this->themeSettings()->settings()['navigation'] ?? []);

        if ($route->isValid && $this->isRobotsRoute($route->urlPath)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $this->robotsTxt($publicBasePath);
            return;
        }

        if ($route->isValid && $this->isFeedRoute($route->urlPath)) {
            if (empty($this->config['features']['rss'])) {
                http_response_code(404);
                echo $this->renderNotFoundPage($renderer, $markdownParser, $navigation, $pages, $route->urlPath);
                return;
            }

            header('Content-Type: application/rss+xml; charset=utf-8');
            $feedConfig = is_array($this->config['feed'] ?? null) ? $this->config['feed'] : [];
            echo (new FeedGenerator(
                $pages,
                $this->config['site'],
                20,
                (string) ($feedConfig['path_prefix'] ?? '')
            ))->xml();
            return;
        }

        if ($route->isValid && $this->isSitemapRoute($route->urlPath)) {
            if (empty($this->config['features']['sitemap'])) {
                http_response_code(404);
                echo $this->renderNotFoundPage($renderer, $markdownParser, $navigation, $pages, $route->urlPath);
                return;
            }

            header('Content-Type: application/xml; charset=utf-8');
            echo (new SitemapGenerator(
                $pages,
                (string) ($this->config['site']['url'] ?? ''),
                $publicBasePath
            ))->xml();
            return;
        }

        if ($route->isValid && $this->isSearchRoute($route->urlPath)) {
            $searchIndex = new SearchIndex($pages, $publicBasePath);
            $performance->lap('search_index_ready');
            echo $this->renderSearchPage($renderer, $navigation, $searchIndex, $pages, $requestUri, $publicBasePath);
            return;
        }

        if ($route->isValid && $this->isTagsRoute($route->urlPath)) {
            $tagIndex = new TagIndex($pages, $publicBasePath);
            $performance->lap('tag_index_ready');
            echo $this->renderTagsPage($renderer, $navigation, $tagIndex, $pages, $route->urlPath, $publicBasePath);
            return;
        }

        if ($route->isValid && $this->isAllRoute($route->urlPath)) {
            echo $this->renderAllPage($renderer, $navigation, $pages, $publicBasePath);
            return;
        }

        $page = $this->findIndexedPageForRoute($pages, $route);
        $contentHtml = null;
        $contentToc = '';
        $relatedItems = [];
        if ($page !== null) {
            $page['file'] = $this->sourceFileForIndexedPage($page);
            $cachedContent = $htmlCache->readWithToc((string) ($page['path'] ?? ''), (string) ($page['file'] ?? ''));
            if ($cachedContent !== null) {
                $contentHtml = $cachedContent['html'];
                $contentToc = $cachedContent['toc'];
                $relatedItems = $this->publishingEngine->relatedItemsFromHtml($contentHtml, (string) ($page['url'] ?? ''), $pages, $publicBasePath);
                $performance->set('html_cache', 'hit');
            } else {
                $performance->set('html_cache', 'miss');
            }
            $performance->lap('html_cache_check');
        }

        if ($page === null) {
            $page = VirtualFolderIndex::find($route, $pages);
            if ($page !== null) {
                $page['title'] = $this->themeSettings()->virtualFolderTitle((string) ($page['folder_path'] ?? ''));
                $contentHtml = '';
                $performance->set('html_cache', 'skipped');
                $performance->set('markdown_render', 'skipped');
                $performance->set('wiki_link_parse', 'skipped');
            }
        }

        if ($page === null || $contentHtml === null) {
            if ($page === null || ($page['page_type'] ?? '') !== 'virtual_folder_index') {
                $repository = $this->createPageRepository($frontMatterParser);
                $lookup = $repository !== null
                    ? $repository->findByRoute($route)
                    : new PageLookupResult('not_found');
                $performance->lap('page_repository_lookup');

                if ($lookup->status !== 'ok' || $lookup->page === null) {
                    http_response_code(404);
                    echo $this->renderNotFoundPage($renderer, $markdownParser, $navigation, $pages, $route->urlPath);
                    return;
                }

                $page = $lookup->page;
                $renderedContent = $this->renderMarkdownContent($page, $markdownParser, $pages, $publicBasePath, $htmlCache, $performance);
                $contentHtml = $renderedContent['html'];
                $contentToc = $renderedContent['toc'];
                $relatedItems = $renderedContent['related_items'];
            }
        } else {
            $performance->set('markdown_render', 'skipped');
            $performance->set('wiki_link_parse', 'skipped');
        }

        echo $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
            'title' => $page['title'],
            'description' => SeoMetadata::description($page, $this->config['site']),
            'url' => Security::publicUrl($page['url'], $publicBasePath),
            'page_type' => $page['page_type'] ?? 'markdown_page',
            'title_explicit' => $page['title_explicit'] ?? false,
            'date' => $page['date'],
            'published' => $page['published'] ?? '',
            'updated' => $page['updated'],
            'image' => $page['image'] ?? '',
            'excerpt' => $page['excerpt'] ?? '',
            'tags' => $page['tags'],
            'language' => $page['language'] ?? null,
            'tags_html' => $this->publishingEngine->pageTagsHtml(is_array($page['tags']) ? $page['tags'] : [], $publicBasePath),
            'content' => $contentHtml,
            'toc' => $contentToc,
            'related_items' => $relatedItems,
            'path' => $page['path'],
            'folder_path' => $page['folder_path'] ?? '',
            'folder_page_number' => $this->positivePageNumber($requestUri),
            'internal_url' => $page['url'],
            'breadcrumbs' => $navigation->breadcrumbs($pages, $page['url']),
        ]);
        $performance->lap('theme_render');
        } finally {
            $performance->finish($requestUri);
        }
    }

    /**
     * @return array{html: string, toc: string, related_items: array<int, array{title: string, url: string}>}
     */
    private function renderMarkdownContent(
        array $page,
        MarkdownParser $markdownParser,
        array $pages,
        string $publicBasePath,
        HtmlCache $htmlCache,
        PerformanceLogger $performance
    ): array {
        $sourcePath = (string) ($page['path'] ?? '');
        $sourceFile = (string) ($page['file'] ?? '');
        $cachedContent = $htmlCache->readWithToc($sourcePath, $sourceFile);
        if ($cachedContent !== null) {
            $performance->set('html_cache', 'hit');
            $performance->set('markdown_render', 'skipped');
            $performance->set('wiki_link_parse', 'skipped');
            $performance->lap('html_cache_check');
            $cachedContent['related_items'] = $this->publishingEngine->relatedItemsFromHtml(
                $cachedContent['html'],
                (string) ($page['url'] ?? ''),
                $pages,
                $publicBasePath
            );
            return $cachedContent;
        }
        $performance->set('html_cache', 'miss');
        $performance->lap('html_cache_check');

        $linkAliases = $this->loadLinkAliases();
        $performance->increment('link_aliases_load');
        $rendered = $this->publishingEngine->renderMarkdownContent(
            $page,
            $markdownParser,
            $pages,
            $publicBasePath,
            $linkAliases
        );
        $performance->set('wiki_link_parse', 'run');
        $performance->set('markdown_render', 'run');

        $htmlCache->write($sourcePath, $sourceFile, $rendered['html'], $rendered['toc']);

        return $rendered;
    }

    private function loadPages(MetadataIndex $metadataIndex, PerformanceLogger $performance): array
    {
        try {
            $features = is_array($this->config['features'] ?? null) ? $this->config['features'] : [];
            $metadataCacheEnabled = !array_key_exists('metadata_cache', $features) || !empty($features['metadata_cache']);
            if (!$metadataCacheEnabled) {
                $performance->set('metadata_index', 'build_uncached');
                return $metadataIndex->build();
            }

            $pages = $metadataIndex->loadFresh();
            if ($pages === null) {
                $performance->set('metadata_index', 'rebuild');
                return $metadataIndex->rebuild();
            }

            $performance->set('metadata_index', 'load');
            $performance->increment('pages_json_load');
            return $pages;
        } catch (\Throwable $exception) {
            try {
                $performance->set('metadata_index', 'build_fallback');
                return $metadataIndex->build();
            } catch (\Throwable $fallbackException) {
                return [];
            }
        }
    }

    private function loadLinkAliases(): array
    {
        try {
            $index = new LinkAliasIndex((string) $this->config['paths']['cache_dir']);
            return $index->load();
        } catch (\Throwable $exception) {
            return [
                'aliases' => [],
                'conflicts' => [],
            ];
        }
    }

    private function findIndexedPageForRoute(array $pages, Route $route): ?array
    {
        if (!$route->isValid) {
            return null;
        }

        foreach ($pages as $page) {
            if (!is_array($page) || !empty($page['draft'])) {
                continue;
            }

            if ($this->normalizeInternalUrl((string) ($page['url'] ?? '/')) === $this->normalizeInternalUrl($route->urlPath)) {
                return $page;
            }
        }

        return null;
    }

    private function sourceFileForIndexedPage(array $page): string
    {
        $path = (string) ($page['path'] ?? '');
        if ($path === '' || !Security::isSafeRelativePath($path) || !Security::hasAllowedExtension($path, ['md'])) {
            return '';
        }

        return rtrim((string) $this->config['paths']['content_dir'], DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function pageTagsHtml(array $tags, string $publicBasePath): string
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

    private function createPageRepository(FrontMatterParser $frontMatterParser): ?PageRepository
    {
        try {
            return new PageRepository((string) $this->config['paths']['content_dir'], $frontMatterParser);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function createMetadataIndex(FrontMatterParser $frontMatterParser): ?MetadataIndex
    {
        try {
            return new MetadataIndex(
                (string) $this->config['paths']['content_dir'],
                (string) $this->config['paths']['cache_dir'],
                $frontMatterParser,
                (bool) ($this->config['metadata']['include_drafts'] ?? false),
                (string) ($this->config['site']['language'] ?? 'ja')
            );
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function themeSettings(): ThemeSettings
    {
        if ($this->themeSettings === null) {
            $themesDir = rtrim((string) ($this->config['paths']['theme_dir'] ?? ''), DIRECTORY_SEPARATOR);
            $this->themeSettings = new ThemeSettings(dirname($themesDir));
        }

        return $this->themeSettings;
    }

    private function contentWithoutDuplicateTitleHeading(string $markdown, string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return $markdown;
        }

        $normalizedMarkdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        if (preg_match('/\A([ \t\n]*)#\s+([^\n]+)[ \t]*(?:\n|$)/u', $normalizedMarkdown, $matches) !== 1) {
            return $normalizedMarkdown;
        }

        if (trim($matches[2]) !== $title) {
            return $normalizedMarkdown;
        }

        return ltrim(substr($normalizedMarkdown, strlen($matches[0])), "\n");
    }

    private function renderNotFoundPage(
        TemplateRenderer $renderer,
        MarkdownParser $markdownParser,
        NavigationBuilder $navigation,
        array $pages,
        string $currentUrl
    ): string {
        $title = 'ページが見つかりません';
        $description = '指定されたページは存在しないか、非公開になっています。';
        $content = $markdownParser->toHtml('指定されたページは存在しないか、非公開になっています。' . "\n\n" . '[トップページへ戻る](/)');

        return $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
            'title' => $title,
            'description' => $description,
            'url' => '',
            'page_type' => 'website',
            'title_explicit' => true,
            'date' => '',
            'published' => '',
            'updated' => '',
            'image' => '',
            'excerpt' => $description,
            'tags' => [],
            'tags_html' => '',
            'content' => $content,
            'internal_url' => $currentUrl,
            'status' => 404,
            'is_not_found' => true,
            'breadcrumbs' => $navigation->notFoundBreadcrumbs($title),
            'track_page' => false,
        ]);
    }

    private function renderTagsPage(
        TemplateRenderer $renderer,
        NavigationBuilder $navigation,
        TagIndex $tagIndex,
        array $pages,
        string $urlPath,
        string $publicBasePath
    ): string {
        if ($urlPath === '/tags' || $urlPath === '/tags/') {
            return $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
                'title' => 'タグ一覧',
                'description' => 'タグからページを探します。',
                'url' => Security::publicUrl('/tags/', $publicBasePath),
                'page_type' => 'website',
                'title_explicit' => true,
                'date' => '',
                'published' => '',
                'updated' => '',
                'image' => '',
                'excerpt' => 'タグからページを探します。',
                'tags' => [],
                'tags_html' => '',
                'content' => $tagIndex->indexHtml(),
                'internal_url' => '/tags/',
                'breadcrumbs' => $tagIndex->breadcrumbs(),
            ]);
        }

        $slug = substr($urlPath, strlen('/tags/'));
        $tag = $tagIndex->resolveTag($slug);
        if ($tag === null) {
            http_response_code(404);
            return $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
                'title' => 'タグが見つかりません',
                'description' => '指定されたタグのページはありません。',
                'url' => '',
                'page_type' => 'website',
                'title_explicit' => true,
                'date' => '',
                'published' => '',
                'updated' => '',
                'image' => '',
                'excerpt' => '指定されたタグのページはありません。',
                'tags' => [],
                'tags_html' => '',
                'content' => '<p>指定されたタグのページはありません。</p>',
                'internal_url' => $urlPath,
                'is_not_found' => true,
                'breadcrumbs' => $navigation->notFoundBreadcrumbs('タグが見つかりません'),
                'track_page' => false,
            ]);
        }

        return $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
            'title' => 'タグ: ' . $tag,
            'description' => 'タグ「' . $tag . '」のページ一覧です。',
            'url' => $tagIndex->tagUrl($tag),
            'page_type' => 'website',
            'title_explicit' => true,
            'date' => '',
            'published' => '',
            'updated' => '',
            'image' => '',
            'excerpt' => 'タグ「' . $tag . '」のページ一覧です。',
            'tags' => [],
            'tags_html' => '',
            'content' => $tagIndex->tagPageHtml($tag),
            'internal_url' => '/tags/' . rawurlencode($tag),
            'breadcrumbs' => $tagIndex->breadcrumbs($tag),
        ]);
    }

    private function renderSearchPage(
        TemplateRenderer $renderer,
        NavigationBuilder $navigation,
        SearchIndex $searchIndex,
        array $pages,
        string $requestUri,
        string $publicBasePath
    ): string {
        $query = $this->queryParam($requestUri, 'q');

        return $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
            'title' => '検索',
            'description' => 'サイト内を検索します。',
            'url' => Security::publicUrl('/search/', $publicBasePath),
            'page_type' => 'website',
            'title_explicit' => true,
            'date' => '',
            'published' => '',
            'updated' => '',
            'image' => '',
            'excerpt' => 'サイト内を検索します。',
            'tags' => [],
            'tags_html' => '',
            'content' => $searchIndex->pageHtml($query),
            'internal_url' => '/search/',
            'breadcrumbs' => $searchIndex->breadcrumbs(),
        ]);
    }

    private function renderAllPage(
        TemplateRenderer $renderer,
        NavigationBuilder $navigation,
        array $pages,
        string $publicBasePath
    ): string {
        return $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
            'title' => 'すべての項目',
            'description' => '公開中のすべてのページを一覧します。',
            'url' => Security::publicUrl('/all/', $publicBasePath),
            'page_type' => 'website',
            'title_explicit' => true,
            'date' => '',
            'published' => '',
            'updated' => '',
            'image' => '',
            'excerpt' => '公開中のすべてのページを一覧します。',
            'tags' => [],
            'tags_html' => '',
            'content' => $navigation->pageList($pages),
            'toc' => '',
            'related_items' => [],
            'internal_url' => '/all/',
            'breadcrumbs' => $navigation->breadcrumbs($pages, '/all/'),
        ]);
    }

    private function renderPage(TemplateRenderer $renderer, NavigationBuilder $navigation, array $pages, array $page): string
    {
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

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isTagsRoute(string $urlPath): bool
    {
        return $urlPath === '/tags' || $urlPath === '/tags/' || strpos($urlPath, '/tags/') === 0;
    }

    private function isAllRoute(string $urlPath): bool
    {
        return $urlPath === '/all' || $urlPath === '/all/';
    }

    private function isSearchRoute(string $urlPath): bool
    {
        return $urlPath === '/search' || $urlPath === '/search/';
    }

    private function isFeedRoute(string $urlPath): bool
    {
        return $urlPath === '/feed.xml' || $urlPath === '/rss.xml';
    }

    private function isSitemapRoute(string $urlPath): bool
    {
        return $urlPath === '/sitemap.xml';
    }

    private function isRobotsRoute(string $urlPath): bool
    {
        return $urlPath === '/robots.txt';
    }

    private function robotsTxt(string $publicBasePath): string
    {
        $lines = ['User-agent: *', 'Allow: /'];
        $siteUrl = (string) ($this->config['site']['url'] ?? '');
        if (!empty($this->config['features']['sitemap'])) {
            $sitemapUrl = Security::absolutePublicUrl($siteUrl, '/sitemap.xml', $publicBasePath);
            if ($sitemapUrl !== '') {
                $lines[] = '';
                $lines[] = 'Sitemap: ' . $sitemapUrl;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function queryParam(string $requestUri, string $name): string
    {
        $query = parse_url($requestUri, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return '';
        }

        $params = [];
        parse_str($query, $params);
        $value = $params[$name] ?? '';
        if (is_array($value)) {
            return '';
        }

        return (string) $value;
    }

    private function positivePageNumber(string $requestUri): int
    {
        $value = $this->queryParam($requestUri, 'page');
        if (preg_match('/^[1-9][0-9]{0,8}$/', $value) !== 1) {
            return 1;
        }

        return (int) $value;
    }

    private function normalizeInternalUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        $path = preg_replace('#/+#', '/', $path) ?? '/';
        if ($path !== '/' && substr($path, -1) === '/') {
            return rtrim($path, '/') . '/';
        }

        return $path;
    }

    /**
     * @return array<int, array{title: string, url: string}>
     */
    private function relatedItemsFromHtml(string $html, string $currentUrl, array $pages, string $publicBasePath): array
    {
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

    private function applyRuntimeSettings(): void
    {
        if (!empty($this->config['site']['timezone'])) {
            date_default_timezone_set((string) $this->config['site']['timezone']);
        }
    }

    private function sendSecurityHeaders(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        if (!empty($this->config['security']['content_security_policy'])) {
            header('Content-Security-Policy: ' . ContentSecurityPolicy::build(
                Ga4::measurementId($this->config) !== '',
                $this->ga4Nonce
            ));
        }
    }

}
