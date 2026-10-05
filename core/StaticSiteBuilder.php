<?php

declare(strict_types=1);

namespace Tomos;

/**
 * Local static build runtime for the future GitHub edition.
 *
 * Generates a deployable static site without the Core edition HTTP runtime.
 * Browser-side search and path-based folder pagination are provided for the
 * static runtime while shared publishing semantics remain in Publishing Core.
 */
final class StaticSiteBuilder
{
    private array $config;
    private string $rootDir;
    private string $contentDir;
    private string $themesDir;
    private string $publicBasePath;
    private FrontMatterParser $frontMatterParser;
    private PageCatalogBuilder $catalogBuilder;
    private PageRepository $pageRepository;
    private PublishingEngine $publishingEngine;
    private MarkdownParser $markdownParser;
    private ThemeSettings $themeSettings;

    public function __construct(array $config, ?string $rootDir = null)
    {
        $this->config = $config;
        $this->contentDir = rtrim((string) ($config['paths']['content_dir'] ?? ''), DIRECTORY_SEPARATOR);
        $this->themesDir = rtrim((string) ($config['paths']['theme_dir'] ?? ''), DIRECTORY_SEPARATOR);
        $this->rootDir = rtrim($rootDir ?? dirname($this->themesDir), DIRECTORY_SEPARATOR);
        $this->publicBasePath = (string) ($config['site']['public_base_path'] ?? '');
        if ($this->publicBasePath === '') {
            $this->publicBasePath = (string) ($config['site']['base_path'] ?? '');
        }

        $this->frontMatterParser = new FrontMatterParser();
        $this->catalogBuilder = new PageCatalogBuilder(
            $this->contentDir,
            $this->frontMatterParser,
            false,
            (string) ($config['site']['language'] ?? 'ja')
        );
        $this->pageRepository = new PageRepository($this->contentDir, $this->frontMatterParser);
        $this->publishingEngine = new PublishingEngine($config);
        $this->markdownParser = new MarkdownParser(
            (bool) ($config['security']['allow_raw_html'] ?? false),
            $this->publicBasePath,
            (string) ($config['paths']['cache_dir'] ?? '')
        );
        $this->themeSettings = new ThemeSettings($this->rootDir);
    }

    /**
     * @return array{pages:int,virtual_folders:int,tags:int,files:int}
     */
    public function build(string $outputDir): array
    {
        $outputDir = $this->prepareOutputDirectory($outputDir);

        $pages = $this->catalogBuilder->build();
        $navigation = new NavigationBuilder(
            $this->publicBasePath,
            $this->themeSettings->settings()['navigation'] ?? []
        );
        $renderer = new TemplateRenderer($this->config, '', $this->rootDir);
        $aliases = (new LinkAliasIndex((string) ($this->config['paths']['cache_dir'] ?? '')))->build($pages);

        $written = 0;

        foreach ($pages as $indexedPage) {
            $route = (new Router(''))->resolve((string) ($indexedPage['url'] ?? '/'));
            $lookup = $this->pageRepository->findByRoute($route);
            if ($lookup->status !== 'ok' || $lookup->page === null) {
                throw new \RuntimeException('Static build could not load page: ' . (string) ($indexedPage['path'] ?? ''));
            }

            $page = $lookup->page;
            $rendered = $this->publishingEngine->renderMarkdownContent(
                $page,
                $this->markdownParser,
                $pages,
                $this->publicBasePath,
                $aliases
            );

            $html = $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
                'title' => $page['title'],
                'description' => SeoMetadata::description($page, $this->config['site']),
                'url' => Security::publicUrl((string) $page['url'], $this->publicBasePath),
                'page_type' => $page['page_type'] ?? 'markdown_page',
                'title_explicit' => $page['title_explicit'] ?? false,
                'date' => $page['date'],
                'published' => $page['published'] ?? '',
                'updated' => $page['updated'],
                'image' => $page['image'] ?? '',
                'excerpt' => $page['excerpt'] ?? '',
                'tags' => $page['tags'],
                'language' => $page['language'] ?? null,
                'tags_html' => $this->publishingEngine->pageTagsHtml(
                    is_array($page['tags']) ? $page['tags'] : [],
                    $this->publicBasePath
                ),
                'content' => $rendered['html'],
                'toc' => $rendered['toc'],
                'related_items' => $rendered['related_items'],
                'path' => $page['path'],
                'folder_path' => '',
                'folder_page_number' => 1,
                'static_pagination' => true,
                'internal_url' => $page['url'],
                'breadcrumbs' => $navigation->breadcrumbs($pages, (string) $page['url']),
            ]);

            $this->writeRouteHtml($outputDir, (string) $page['url'], $html);
            $written++;
        }

        $virtualFolderCount = 0;
        foreach ($this->virtualFolders($pages) as $page) {
            $page['title'] = $this->themeSettings->virtualFolderTitle((string) ($page['folder_path'] ?? ''));

            $html = $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
                'title' => $page['title'],
                'description' => '',
                'url' => Security::publicUrl((string) $page['url'], $this->publicBasePath),
                'page_type' => 'virtual_folder_index',
                'title_explicit' => false,
                'date' => '',
                'published' => '',
                'updated' => '',
                'image' => '',
                'excerpt' => '',
                'tags' => [],
                'language' => null,
                'tags_html' => '',
                'content' => '',
                'toc' => '',
                'related_items' => [],
                'path' => '',
                'folder_path' => (string) $page['folder_path'],
                'folder_page_number' => 1,
                'static_pagination' => true,
                'internal_url' => (string) $page['url'],
                'breadcrumbs' => $navigation->breadcrumbs($pages, (string) $page['url']),
            ]);

            $this->writeRouteHtml($outputDir, (string) $page['url'], $html);
            $written++;
            $virtualFolderCount++;
        }

        $this->writeFolderPaginationPages(
            $outputDir,
            $pages,
            $renderer,
            $navigation,
            $aliases,
            $written
        );

        $this->writeSystemPages($outputDir, $pages, $renderer, $navigation, $written);

        if (!empty($this->config['features']['rss'])) {
            $feedConfig = is_array($this->config['feed'] ?? null) ? $this->config['feed'] : [];
            $feed = (new FeedGenerator(
                $pages,
                $this->config['site'],
                20,
                (string) ($feedConfig['path_prefix'] ?? '')
            ))->xml();
            $this->writeFile($outputDir . DIRECTORY_SEPARATOR . 'feed.xml', $feed);
            $written++;
        }

        if (!empty($this->config['features']['sitemap'])) {
            $sitemap = (new SitemapGenerator(
                $pages,
                (string) ($this->config['site']['url'] ?? ''),
                $this->publicBasePath
            ))->xml();
            $this->writeFile($outputDir . DIRECTORY_SEPARATOR . 'sitemap.xml', $sitemap);
            $written++;
        }

        $this->writeFile(
            $outputDir . DIRECTORY_SEPARATOR . 'robots.txt',
            $this->robotsTxt()
        );
        $written++;

        $this->copyPublicAssets($outputDir);

        $tagCount = 0;
        if (!empty($this->config['features']['tags'])) {
            $tagCount = count((new TagIndex($pages, $this->publicBasePath))->items());
        }

        return [
            'pages' => count($pages),
            'virtual_folders' => $virtualFolderCount,
            'tags' => $tagCount,
            'files' => $written,
        ];
    }

    private function writeFolderPaginationPages(
        string $outputDir,
        array $pages,
        TemplateRenderer $renderer,
        NavigationBuilder $navigation,
        array $aliases,
        int &$written
    ): void {
        foreach ($this->folderPageCounts($pages) as $folder => $totalItems) {
            $totalPages = (int) ceil($totalItems / 30);
            if ($totalPages <= 1) {
                continue;
            }

            $indexPath = $folder . '/index.md';
            $indexedPage = null;
            foreach ($pages as $candidate) {
                if (is_array($candidate) && (string) ($candidate['path'] ?? '') === $indexPath) {
                    $indexedPage = $candidate;
                    break;
                }
            }

            if ($indexedPage !== null) {
                $route = (new Router(''))->resolve((string) ($indexedPage['url'] ?? '/'));
                $lookup = $this->pageRepository->findByRoute($route);
                if ($lookup->status !== 'ok' || $lookup->page === null) {
                    throw new \RuntimeException('Static pagination could not load folder index: ' . $indexPath);
                }

                $page = $lookup->page;
                $rendered = $this->publishingEngine->renderMarkdownContent(
                    $page,
                    $this->markdownParser,
                    $pages,
                    $this->publicBasePath,
                    $aliases
                );

                for ($pageNumber = 2; $pageNumber <= $totalPages; $pageNumber++) {
                    $internalUrl = '/' . $folder . '/page/' . $pageNumber . '/';
                    $html = $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
                        'title' => $page['title'],
                        'description' => SeoMetadata::description($page, $this->config['site']),
                        'url' => Security::publicUrl($internalUrl, $this->publicBasePath),
                        'page_type' => $page['page_type'] ?? 'markdown_page',
                        'title_explicit' => $page['title_explicit'] ?? false,
                        'date' => $page['date'],
                        'published' => $page['published'] ?? '',
                        'updated' => $page['updated'],
                        'image' => $page['image'] ?? '',
                        'excerpt' => $page['excerpt'] ?? '',
                        'tags' => $page['tags'],
                        'language' => $page['language'] ?? null,
                        'tags_html' => $this->publishingEngine->pageTagsHtml(
                            is_array($page['tags']) ? $page['tags'] : [],
                            $this->publicBasePath
                        ),
                        'content' => $rendered['html'],
                        'toc' => $rendered['toc'],
                        'related_items' => $rendered['related_items'],
                        'path' => $page['path'],
                        'folder_path' => $folder,
                        'folder_page_number' => $pageNumber,
                        'static_pagination' => true,
                        'internal_url' => $internalUrl,
                        'breadcrumbs' => $navigation->breadcrumbs($pages, (string) $page['url']),
                    ]);
                    $this->writeRouteHtml($outputDir, $internalUrl, $html);
                    $written++;
                }

                continue;
            }

            $baseUrl = '/' . $folder . '/';
            $virtual = VirtualFolderIndex::find(
                new Route($baseUrl, [$indexPath]),
                $pages
            );
            if ($virtual === null) {
                continue;
            }

            $title = $this->themeSettings->virtualFolderTitle($folder);
            for ($pageNumber = 2; $pageNumber <= $totalPages; $pageNumber++) {
                $internalUrl = '/' . $folder . '/page/' . $pageNumber . '/';
                $html = $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
                    'title' => $title,
                    'description' => '',
                    'url' => Security::publicUrl($internalUrl, $this->publicBasePath),
                    'page_type' => 'virtual_folder_index',
                    'title_explicit' => false,
                    'date' => '',
                    'published' => '',
                    'updated' => '',
                    'image' => '',
                    'excerpt' => '',
                    'tags' => [],
                    'language' => null,
                    'tags_html' => '',
                    'content' => '',
                    'toc' => '',
                    'related_items' => [],
                    'path' => '',
                    'folder_path' => $folder,
                    'folder_page_number' => $pageNumber,
                    'static_pagination' => true,
                    'internal_url' => $internalUrl,
                    'breadcrumbs' => $navigation->breadcrumbs($pages, $baseUrl),
                ]);
                $this->writeRouteHtml($outputDir, $internalUrl, $html);
                $written++;
            }
        }
    }

    /**
     * @return array<string,int>
     */
    private function folderPageCounts(array $pages): array
    {
        $counts = [];

        foreach ($pages as $page) {
            if (!is_array($page) || !empty($page['draft'])) {
                continue;
            }

            $path = trim(str_replace('\\', '/', (string) ($page['path'] ?? '')), '/');
            if ($path === '' || strpos($path, '/') === false || substr($path, -9) === '/index.md') {
                continue;
            }

            $folder = dirname($path);
            $relative = substr($path, strlen($folder) + 1);
            if ($folder === '.' || $folder === '' || strpos($relative, '/') !== false) {
                continue;
            }

            $counts[$folder] = ($counts[$folder] ?? 0) + 1;
        }

        ksort($counts, SORT_NATURAL);

        return $counts;
    }

    private function writeSystemPages(
        string $outputDir,
        array $pages,
        TemplateRenderer $renderer,
        NavigationBuilder $navigation,
        int &$written
    ): void {
        $allHtml = $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
            'title' => 'すべての項目',
            'description' => '公開中のすべてのページを一覧します。',
            'url' => Security::publicUrl('/all/', $this->publicBasePath),
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
        $this->writeRouteHtml($outputDir, '/all/', $allHtml);
        $written++;

        if (!empty($this->config['features']['search'])) {
            $searchIndex = new SearchIndex($pages, $this->publicBasePath);
            $documents = $searchIndex->documents();
            $json = json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (!is_string($json)) {
                throw new \RuntimeException('Static search index could not be encoded.');
            }
            $this->writeFile(
                $outputDir . DIRECTORY_SEPARATOR . 'search-index.json',
                $json . "\n"
            );
            $written++;

            $searchHtml = $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
                'title' => '検索',
                'description' => 'サイト内を検索します。',
                'url' => Security::publicUrl('/search/', $this->publicBasePath),
                'page_type' => 'website',
                'title_explicit' => true,
                'date' => '',
                'published' => '',
                'updated' => '',
                'image' => '',
                'excerpt' => 'サイト内を検索します。',
                'tags' => [],
                'tags_html' => '',
                'content' => $this->staticSearchPageHtml(),
                'internal_url' => '/search/',
                'breadcrumbs' => $searchIndex->breadcrumbs(),
            ]);
            $this->writeRouteHtml($outputDir, '/search/', $searchHtml);
            $written++;
        }

        if (!empty($this->config['features']['tags'])) {
            $tagIndex = new TagIndex($pages, $this->publicBasePath);

            $indexHtml = $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
                'title' => 'タグ一覧',
                'description' => 'タグからページを探します。',
                'url' => Security::publicUrl('/tags/', $this->publicBasePath),
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
            $this->writeRouteHtml($outputDir, '/tags/', $indexHtml);
            $written++;

            foreach ($tagIndex->items() as $item) {
                $tag = (string) ($item['name'] ?? '');
                $publicUrl = (string) ($item['url'] ?? '');
                $internalUrl = $this->internalUrlFromPublicUrl($publicUrl);

                $tagHtml = $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
                    'title' => 'タグ: ' . $tag,
                    'description' => 'タグ「' . $tag . '」のページ一覧です。',
                    'url' => $publicUrl,
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
                    'internal_url' => $internalUrl,
                    'breadcrumbs' => $tagIndex->breadcrumbs($tag),
                ]);
                $this->writeRouteHtml($outputDir, $internalUrl, $tagHtml);
                $written++;
            }
        }

        $notFoundHtml = $this->publishingEngine->renderPage($renderer, $navigation, $pages, [
            'title' => 'ページが見つかりません',
            'description' => '指定されたページは存在しないか、非公開になっています。',
            'url' => '',
            'page_type' => 'website',
            'title_explicit' => true,
            'date' => '',
            'published' => '',
            'updated' => '',
            'image' => '',
            'excerpt' => '指定されたページは存在しないか、非公開になっています。',
            'tags' => [],
            'tags_html' => '',
            'content' => '<p>指定されたページは存在しないか、非公開になっています。</p>',
            'internal_url' => '/404',
            'is_not_found' => true,
            'breadcrumbs' => $navigation->notFoundBreadcrumbs('ページが見つかりません'),
            'track_page' => false,
        ]);
        $this->writeFile($outputDir . DIRECTORY_SEPARATOR . '404.html', $notFoundHtml);
        $written++;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function virtualFolders(array $pages): array
    {
        $folders = [];

        foreach ($pages as $page) {
            if (!is_array($page) || !empty($page['draft'])) {
                continue;
            }

            $path = trim(str_replace('\\', '/', (string) ($page['path'] ?? '')), '/');
            if ($path === '' || strpos($path, '/') === false) {
                continue;
            }

            $folder = dirname($path);
            if ($folder === '.' || $folder === '') {
                continue;
            }

            while ($folder !== '.' && $folder !== '') {
                $folders[$folder] = true;
                $parent = dirname($folder);
                if ($parent === $folder) {
                    break;
                }
                $folder = $parent;
            }
        }

        ksort($folders, SORT_NATURAL);

        $result = [];
        foreach (array_keys($folders) as $folder) {
            $url = '/' . trim((string) $folder, '/') . '/';
            $route = new Route($url, [trim((string) $folder, '/') . '/index.md']);
            $virtual = VirtualFolderIndex::find($route, $pages);
            if ($virtual !== null) {
                $result[] = $virtual;
            }
        }

        return $result;
    }

    private function writeRouteHtml(string $outputDir, string $internalUrl, string $html): void
    {
        $path = $this->outputPathForRoute($outputDir, $internalUrl);
        $this->writeFile($path, $html);
    }

    private function outputPathForRoute(string $outputDir, string $internalUrl): string
    {
        $internalUrl = $this->internalUrlFromPublicUrl($internalUrl);
        if ($internalUrl === '/') {
            return $outputDir . DIRECTORY_SEPARATOR . 'index.html';
        }

        $relative = trim(rawurldecode($internalUrl), '/');
        if ($relative === '' || !Security::isSafeRelativePath($relative)) {
            throw new \RuntimeException('Unsafe static output route: ' . $internalUrl);
        }

        return $outputDir
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative)
            . DIRECTORY_SEPARATOR
            . 'index.html';
    }

    private function internalUrlFromPublicUrl(string $url): string
    {
        $path = trim($url);
        if ($path === '') {
            return '/';
        }

        // Keep raw UTF-8 path bytes intact. parse_url() can corrupt raw
        // multibyte path strings on some runtimes, so strip query/fragment
        // manually and decode only after the public base path is removed.
        $positions = array_filter(
            [strpos($path, '?'), strpos($path, '#')],
            static function ($position): bool {
                return $position !== false;
            }
        );
        if ($positions !== []) {
            $path = substr($path, 0, min($positions));
        }

        $base = Security::normalizeBasePath($this->publicBasePath);
        if ($base !== '') {
            if ($path === $base || $path === $base . '/') {
                $path = '/';
            } elseif (strpos($path, $base . '/') === 0) {
                $path = substr($path, strlen($base));
            }
        }

        $path = rawurldecode($path);
        $validation = Security::validateUrlPath($path);
        if (empty($validation['is_valid'])) {
            throw new \RuntimeException('Invalid static output URL: ' . $url);
        }

        return (string) $validation['path'];
    }

    private function staticSearchPageHtml(): string
    {
        $action = Security::publicUrl('/search/', $this->publicBasePath);
        $indexUrl = Security::publicUrl('/search-index.json', $this->publicBasePath);
        $scriptUrl = Security::publicUrl('/assets/tomos-static-search.js', $this->publicBasePath);

        return '<section class="search-page" data-static-search data-search-index-url="' . $this->escape($indexUrl) . '">'
            . '<h1>検索</h1>'
            . '<form action="' . $this->escape($action) . '" method="get" class="search-form">'
            . '<label for="search-q">検索語</label>'
            . '<input id="search-q" type="search" name="q" value="">'
            . '<button type="submit">検索</button>'
            . '</form>'
            . '<p class="search-summary" data-static-search-summary>検索語を入力してください。</p>'
            . '<ul class="search-results" data-static-search-results></ul>'
            . '<script src="' . $this->escape($scriptUrl) . '" defer></script>'
            . '</section>';
    }

    private function robotsTxt(): string
    {
        $lines = ['User-agent: *', 'Allow: /'];
        if (!empty($this->config['features']['sitemap'])) {
            $sitemapUrl = Security::absolutePublicUrl(
                (string) ($this->config['site']['url'] ?? ''),
                '/sitemap.xml',
                $this->publicBasePath
            );
            if ($sitemapUrl !== '') {
                $lines[] = '';
                $lines[] = 'Sitemap: ' . $sitemapUrl;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function copyPublicAssets(string $outputDir): void
    {
        $this->copyDirectory(
            $this->themesDir,
            $outputDir . DIRECTORY_SEPARATOR . 'themes',
            static function (string $path): bool {
                return is_dir($path) || strpos(str_replace('\\', '/', $path), '/assets/') !== false;
            }
        );

        $themeAssets = $this->rootDir . DIRECTORY_SEPARATOR . 'theme-assets';
        if (is_dir($themeAssets)) {
            $this->copyDirectory($themeAssets, $outputDir . DIRECTORY_SEPARATOR . 'theme-assets');
        }

        $rootAssets = $this->rootDir . DIRECTORY_SEPARATOR . 'assets';
        if (is_dir($rootAssets)) {
            $this->copyDirectory($rootAssets, $outputDir . DIRECTORY_SEPARATOR . 'assets');
        }

        $this->copyDirectory(
            $this->contentDir,
            $outputDir . DIRECTORY_SEPARATOR . 'content',
            static function (string $path): bool {
                return is_dir($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'md';
            }
        );
    }

    /**
     * @param callable(string):bool|null $include
     */
    private function copyDirectory(string $source, string $destination, ?callable $include = null): void
    {
        if (!is_dir($source)) {
            return;
        }

        $sourceReal = realpath($source);
        if ($sourceReal === false) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceReal, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isLink()) {
                continue;
            }
            if ($include !== null && !$include($path)) {
                continue;
            }

            $relative = substr($path, strlen($sourceReal) + 1);
            $target = $destination . DIRECTORY_SEPARATOR . $relative;

            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new \RuntimeException('Static asset directory could not be created.');
                }
                continue;
            }

            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new \RuntimeException('Static asset directory could not be created.');
            }

            if (!copy($path, $target)) {
                throw new \RuntimeException('Static asset could not be copied: ' . $relative);
            }
        }
    }

    private function prepareOutputDirectory(string $outputDir): string
    {
        $outputDir = rtrim($outputDir, DIRECTORY_SEPARATOR);
        if ($outputDir === '') {
            throw new \RuntimeException('Static output directory is required.');
        }

        $protected = array_filter([
            realpath($this->rootDir),
            realpath($this->contentDir),
            realpath($this->themesDir),
            realpath((string) ($this->config['paths']['cache_dir'] ?? '')),
        ]);
        $outputReal = realpath($outputDir);
        if ($outputReal !== false && in_array($outputReal, $protected, true)) {
            throw new \RuntimeException('Static output directory overlaps a Tomos source directory.');
        }

        if (is_dir($outputDir)) {
            $this->removeDirectoryContents($outputDir);
        } elseif (!mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new \RuntimeException('Static output directory could not be created.');
        }

        return $outputDir;
    }

    private function removeDirectoryContents(string $directory): void
    {
        $items = scandir($directory);
        if ($items === false) {
            throw new \RuntimeException('Static output directory could not be read.');
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_link($path) || is_file($path)) {
                if (!unlink($path)) {
                    throw new \RuntimeException('Static output file could not be removed.');
                }
                continue;
            }

            if (is_dir($path)) {
                $this->removeTree($path);
            }
        }
    }

    private function removeTree(string $path): void
    {
        $items = scandir($path);
        if ($items === false) {
            throw new \RuntimeException('Static output directory could not be read.');
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_link($child) || is_file($child)) {
                if (!unlink($child)) {
                    throw new \RuntimeException('Static output file could not be removed.');
                }
            } elseif (is_dir($child)) {
                $this->removeTree($child);
            }
        }

        if (!rmdir($path)) {
            throw new \RuntimeException('Static output directory could not be removed.');
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Static output directory could not be created.');
        }

        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Static output file could not be written: ' . $path);
        }
    }
}
