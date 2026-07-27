<?php

declare(strict_types=1);

namespace Tomos;

final class App
{
    private array $config;
    private string $ga4Nonce = '';

    public function __construct(array $config)
    {
        $this->config = $config;
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
        $metadataIndex = $this->createMetadataIndex($frontMatterParser);
        $markdownParser = new MarkdownParser((bool) $this->config['security']['allow_raw_html'], $publicBasePath);
        $htmlCache = new HtmlCache(
            (string) $this->config['paths']['cache_dir'],
            (bool) ($this->config['features']['html_cache'] ?? false)
        );
        $renderer = new TemplateRenderer($this->config, $this->ga4Nonce);

        $route = $router->resolve($requestUri);
        $performance->lap('route_resolve');
        $pages = $metadataIndex !== null ? $this->loadPages($metadataIndex, $performance) : [];
        $performance->lap('pages_ready');
        $navigation = new NavigationBuilder($publicBasePath);

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

        $page = $this->findIndexedPageForRoute($pages, $route);
        $contentHtml = null;
        if ($page !== null) {
            $page['file'] = $this->sourceFileForIndexedPage($page);
            $cachedHtml = $htmlCache->read((string) ($page['path'] ?? ''), (string) ($page['file'] ?? ''));
            if ($cachedHtml !== null) {
                $contentHtml = $cachedHtml;
                $performance->set('html_cache', 'hit');
            } else {
                $performance->set('html_cache', 'miss');
            }
            $performance->lap('html_cache_check');
        }

        if ($page === null || $contentHtml === null) {
            $repository = $this->createPageRepository($frontMatterParser);
            $lookup = $repository !== null
                ? $repository->findByRoute($route)
                : new PageLookupResult('not_found');
            $performance->lap('page_repository_lookup');

            if ($lookup->status !== 'ok' || $lookup->page === null) {
                $virtualPage = $repository !== null ? $repository->virtualFolderForRoute($route, $pages) : null;
                if ($virtualPage === null) {
                    http_response_code(404);
                    echo $this->renderNotFoundPage($renderer, $markdownParser, $navigation, $pages, $route->urlPath);
                    return;
                }

                $page = $virtualPage;
                $contentHtml = '';
                $performance->set('html_cache', 'skipped');
                $performance->set('markdown_render', 'skipped');
                $performance->set('wiki_link_parse', 'skipped');
            } else {
                $page = $lookup->page;
                $contentHtml = $this->renderMarkdownContentHtml($page, $markdownParser, $pages, $publicBasePath, $htmlCache, $performance);
            }
        } else {
            $performance->set('markdown_render', 'skipped');
            $performance->set('wiki_link_parse', 'skipped');
        }

        echo $this->renderPage($renderer, $navigation, $pages, [
            'title' => $page['title'],
            'description' => $page['description'] !== '' || !empty($page['description_explicit'])
                ? $page['description']
                : $this->config['site']['description'],
            'url' => Security::publicUrl($page['url'], $publicBasePath),
            'date' => $page['date'],
            'updated' => $page['updated'],
            'tags' => $page['tags'],
            'tags_html' => $this->pageTagsHtml(is_array($page['tags']) ? $page['tags'] : [], $publicBasePath),
            'content' => $contentHtml,
            'path' => $page['path'],
            'page_type' => $page['page_type'] ?? 'markdown_page',
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

    private function renderMarkdownContentHtml(
        array $page,
        MarkdownParser $markdownParser,
        array $pages,
        string $publicBasePath,
        HtmlCache $htmlCache,
        PerformanceLogger $performance
    ): string {
        $sourcePath = (string) ($page['path'] ?? '');
        $sourceFile = (string) ($page['file'] ?? '');
        $cachedHtml = $htmlCache->read($sourcePath, $sourceFile);
        if ($cachedHtml !== null) {
            $performance->set('html_cache', 'hit');
            $performance->set('markdown_render', 'skipped');
            $performance->set('wiki_link_parse', 'skipped');
            $performance->lap('html_cache_check');
            return $cachedHtml;
        }
        $performance->set('html_cache', 'miss');
        $performance->lap('html_cache_check');

        $wikiLinkParser = new WikiLinkParser($pages, $publicBasePath, $this->loadLinkAliases());
        $performance->increment('link_aliases_load');
        $imageEmbedParser = new ImageEmbedParser((string) $this->config['paths']['content_dir'], $publicBasePath);
        $contentRaw = $this->contentWithoutDuplicateTitleHeading((string) $page['content_raw'], (string) $page['title']);
        $contentRaw = $imageEmbedParser->replace($contentRaw, $sourcePath !== '' ? $sourcePath : 'index.md');
        $contentRaw = $wikiLinkParser->replace($contentRaw);
        $performance->set('wiki_link_parse', 'run');
        $contentHtml = $markdownParser->toHtml($contentRaw);
        $performance->set('markdown_render', 'run');
        $contentHtml = $wikiLinkParser->restore($contentHtml);
        $contentHtml = $imageEmbedParser->restore($contentHtml);

        $htmlCache->write($sourcePath, $sourceFile, $contentHtml);

        return $contentHtml;
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

            $pages = $metadataIndex->loadCached();
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
                (bool) ($this->config['metadata']['include_drafts'] ?? false)
            );
        } catch (\Throwable $exception) {
            return null;
        }
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

        return $this->renderPage($renderer, $navigation, $pages, [
            'title' => $title,
            'description' => $description,
            'url' => '',
            'date' => '',
            'updated' => '',
            'tags' => [],
            'tags_html' => '',
            'content' => $content,
            'internal_url' => $currentUrl,
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
            return $this->renderPage($renderer, $navigation, $pages, [
                'title' => 'タグ一覧',
                'description' => 'タグからページを探します。',
                'url' => Security::publicUrl('/tags/', $publicBasePath),
                'date' => '',
                'updated' => '',
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
            return $this->renderPage($renderer, $navigation, $pages, [
                'title' => 'タグが見つかりません',
                'description' => '指定されたタグのページはありません。',
                'url' => '',
                'date' => '',
                'updated' => '',
                'tags' => [],
                'tags_html' => '',
                'content' => '<p>指定されたタグのページはありません。</p>',
                'internal_url' => $urlPath,
                'breadcrumbs' => $navigation->notFoundBreadcrumbs('タグが見つかりません'),
                'track_page' => false,
            ]);
        }

        return $this->renderPage($renderer, $navigation, $pages, [
            'title' => 'タグ: ' . $tag,
            'description' => 'タグ「' . $tag . '」のページ一覧です。',
            'url' => $tagIndex->tagUrl($tag),
            'date' => '',
            'updated' => '',
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

        return $this->renderPage($renderer, $navigation, $pages, [
            'title' => '検索',
            'description' => 'サイト内を検索します。',
            'url' => Security::publicUrl('/search/', $publicBasePath),
            'date' => '',
            'updated' => '',
            'tags' => [],
            'tags_html' => '',
            'content' => $searchIndex->pageHtml($query),
            'internal_url' => '/search/',
            'breadcrumbs' => $searchIndex->breadcrumbs(),
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

        return $renderer->renderPage($page + [
            'tags_html' => '',
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
            if (Ga4::measurementId($this->config) !== '' && $this->ga4Nonce !== '') {
                header("Content-Security-Policy: default-src 'self'; script-src https://www.googletagmanager.com 'nonce-" . $this->ga4Nonce . "'; connect-src 'self' https://www.google-analytics.com https://*.google-analytics.com https://*.analytics.google.com; style-src 'self'; img-src 'self' data: http: https:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
            } else {
                header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self' data: http: https:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
            }
        }
    }

}
