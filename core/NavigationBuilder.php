<?php

declare(strict_types=1);

namespace Tomos;

final class NavigationBuilder
{
    private const NAV_FOLDER_PAGE_LIMIT = 30;

    private string $publicBasePath;
    public function __construct(string $publicBasePath = '')
    {
        $this->publicBasePath = $publicBasePath;
    }

    public function tree(array $pages, string $currentUrl = '/', bool $openAllFolders = false, bool $includeFeed = true): string
    {
        $pages = $this->publicPages($pages);
        if ($pages === []) {
            return $this->fallbackTree($currentUrl, $includeFeed);
        }

        $currentUrl = $this->normalizeInternalUrl($currentUrl);

        $children = [];
        $rootPages = [];

        foreach ($pages as $page) {
            $path = (string) ($page['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $parts = explode('/', $path);
            if (count($parts) === 1) {
                $rootPages[] = $page;
                continue;
            }

            $folder = (string) $parts[0];
            $folderKey = 'folder:' . $folder;
            if (!isset($children[$folderKey])) {
                $children[$folderKey] = [
                    'folder' => $folder,
                    'pages' => [],
                ];
            }
            $children[$folderKey]['pages'][] = $page;
        }

        usort($rootPages, [$this, 'comparePages']);
        ksort($children, SORT_NATURAL);

        $html = '<nav class="page-tree" aria-label="サイト内ページ">' . "\n<ul>";

        foreach ($rootPages as $page) {
            $html .= $this->pageListItem($page, $currentUrl);
        }

        foreach ($children as $folderGroup) {
            $folder = (string) $folderGroup['folder'];
            $allFolderPages = $folderGroup['pages'];
            usort($allFolderPages, [$this, 'comparePages']);
            $folderIndex = $this->folderIndexPage($allFolderPages, $folder);
            $folderLabel = $folderIndex !== null ? $this->pageTitle($folderIndex) : $folder;
            $open = $openAllFolders || $this->folderContainsCurrentPage($allFolderPages, $currentUrl);
            $openAttribute = $open ? ' open' : '';
            $folderPages = $this->limitedFolderPages($allFolderPages, $currentUrl);
            $html .= '<li class="nav-folder-item">';
            $html .= '<details class="nav-folder"' . $openAttribute . '>';
            $html .= '<summary>' . $this->escape($folderLabel) . '</summary>';
            $html .= '<ul>';
            foreach ($folderPages as $page) {
                $label = $this->isFolderIndexPage($page, $folder) ? $folderLabel . 'トップ' : null;
                $html .= $this->pageListItem($page, $currentUrl, $label);
            }
            if (count($allFolderPages) > count($folderPages)) {
                $articleCount = count($allFolderPages) - ($folderIndex !== null ? 1 : 0);
                $folderUrl = Security::publicUrl('/' . rawurlencode($folder) . '/', $this->publicBasePath);
                $html .= '<li class="nav-folder-all"><a href="' . $this->escape($folderUrl) . '">すべて見る（' . $articleCount . '件）</a></li>';
            }
            $html .= '</ul></details></li>';
        }

        $html .= $this->systemListItem('/search/', '検索', $currentUrl);
        $html .= $this->systemListItem('/tags/', 'タグ一覧', $currentUrl);
        if ($includeFeed) {
            $html .= $this->systemListItem('/feed.xml', 'RSS', $currentUrl);
        }
        $html .= '</ul>' . "\n</nav>";

        return $html;
    }

    public function breadcrumbs(array $pages, string $currentUrl): string
    {
        $currentUrl = $this->normalizeInternalUrl($currentUrl);
        $byUrl = [];
        foreach ($this->publicPages($pages) as $page) {
            if (!empty($page['url'])) {
                $byUrl[$this->normalizeInternalUrl((string) $page['url'])] = $page;
            }
        }

        $html = '<nav class="breadcrumbs" aria-label="パンくず">';
        $html .= '<a href="' . $this->escape(Security::publicUrl('/', $this->publicBasePath)) . '">Home</a>';

        if ($currentUrl === '/') {
            $html .= '</nav>';
            return $html;
        }

        $segments = array_values(array_filter(explode('/', trim($currentUrl, '/')), 'strlen'));
        $accumulated = '';
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            $accumulated .= '/' . $segment;
            $candidateUrl = $index < $lastIndex ? $accumulated . '/' : $accumulated;
            $html .= ' <span aria-hidden="true">/</span> ';

            if ($index === $lastIndex) {
                $label = $this->breadcrumbLabel($byUrl[$candidateUrl] ?? null, $segment);
                $html .= '<span>' . $this->escape($label) . '</span>';
                continue;
            }

            if (isset($byUrl[$candidateUrl]) || $this->folderHasPublicPages($pages, trim($candidateUrl, '/'))) {
                $html .= '<a href="' . $this->escape(Security::publicUrl($candidateUrl, $this->publicBasePath)) . '">' . $this->escape($segment) . '</a>';
            } else {
                $html .= '<span>' . $this->escape($segment) . '</span>';
            }
        }

        $html .= '</nav>';

        return $html;
    }

    public function notFoundBreadcrumbs(string $label = 'ページが見つかりません'): string
    {
        $html = '<nav class="breadcrumbs" aria-label="パンくず">';
        $html .= '<a href="' . $this->escape(Security::publicUrl('/', $this->publicBasePath)) . '">Home</a>';
        $html .= ' <span aria-hidden="true">/</span> ';
        $html .= '<span>' . $this->escape($label) . '</span>';
        $html .= '</nav>';

        return $html;
    }

    public function pageList(array $pages): string
    {
        $pages = $this->publicPages($pages);
        if ($pages === []) {
            return '';
        }

        usort($pages, [$this, 'comparePages']);
        $html = '<ul class="page-list-items">';
        foreach ($pages as $page) {
            $html .= $this->pageListItem($page, '');
        }
        $html .= '</ul>';

        return $html;
    }

    public function latestPageList(array $pages, int $limit = 12): string
    {
        if ($limit < 1) {
            return '';
        }

        $pages = array_values(array_filter($this->publicPages($pages), function ($page): bool {
            $path = trim(str_replace('\\', '/', (string) ($page['path'] ?? '')), '/');
            if ($path === '' || $path === 'index.md' || $path === 'about.md') {
                return false;
            }

            return substr($path, -9) !== '/index.md';
        }));

        if ($pages === []) {
            return '';
        }

        usort($pages, [$this, 'comparePages']);
        $pages = array_slice($pages, 0, $limit);

        $html = '<ul class="page-list-items">';
        foreach ($pages as $page) {
            $html .= $this->pageListItem($page, '');
        }
        $html .= '</ul>';

        return $html;
    }

    public function folderPageList(array $pages, string $folder, int $currentPage = 1, int $perPage = 30): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        if ($folder === '' || $perPage < 1) {
            return '';
        }

        $prefix = $folder . '/';
        $indexPath = $prefix . 'index.md';
        $folderPages = array_values(array_filter($this->publicPages($pages), function ($page) use ($prefix, $indexPath): bool {
            $path = (string) ($page['path'] ?? '');
            $relative = strpos($path, $prefix) === 0 ? substr($path, strlen($prefix)) : '';
            return $path !== $indexPath && $relative !== '' && strpos($relative, '/') === false;
        }));

        if ($folderPages === []) {
            return '';
        }

        usort($folderPages, [$this, 'comparePages']);

        $totalItems = count($folderPages);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $currentPage = max(1, min($currentPage, $totalPages));
        $offset = ($currentPage - 1) * $perPage;
        $visiblePages = array_slice($folderPages, $offset, $perPage);
        $rangeStart = $offset + 1;
        $rangeEnd = $offset + count($visiblePages);

        $html = '<section class="folder-page-list" aria-label="' . $this->escape($folder) . ' page list">';
        $html .= '<p class="folder-page-summary">全' . $totalItems . '件中 ' . $rangeStart . '–' . $rangeEnd . '件を表示</p>';
        $html .= '<ul class="page-list-items">';
        foreach ($visiblePages as $page) {
            $html .= $this->pageListItem($page, '');
        }
        $html .= '</ul>';
        if ($totalPages > 1) {
            $html .= $this->folderPagination($folder, $currentPage, $totalPages);
        }
        $html .= '</section>';

        return $html;
    }

    private function folderPagination(string $folder, int $currentPage, int $totalPages): string
    {
        $html = '<nav class="folder-pagination" aria-label="記事一覧のページ">';
        if ($currentPage > 1) {
            $html .= $this->paginationLink($folder, $currentPage - 1, '前へ', 'folder-pagination-prev', 'prev');
        } else {
            $html .= '<span class="folder-pagination-prev is-disabled" aria-disabled="true">前へ</span>';
        }

        $html .= '<ol class="folder-pagination-pages">';
        $pageNumbers = [1, $totalPages];
        for ($page = max(1, $currentPage - 2); $page <= min($totalPages, $currentPage + 2); $page++) {
            $pageNumbers[] = $page;
        }
        $pageNumbers = array_values(array_unique($pageNumbers));
        sort($pageNumbers, SORT_NUMERIC);

        $previousPage = null;
        foreach ($pageNumbers as $pageNumber) {
            if ($previousPage !== null && $pageNumber > $previousPage + 1) {
                $html .= '<li class="folder-pagination-ellipsis" aria-hidden="true">…</li>';
            }
            $html .= '<li>';
            if ($pageNumber === $currentPage) {
                $html .= '<span aria-current="page">' . $pageNumber . '</span>';
            } else {
                $html .= $this->paginationLink($folder, $pageNumber, (string) $pageNumber);
            }
            $html .= '</li>';
            $previousPage = $pageNumber;
        }
        $html .= '</ol>';

        if ($currentPage < $totalPages) {
            $html .= $this->paginationLink($folder, $currentPage + 1, '次へ', 'folder-pagination-next', 'next');
        } else {
            $html .= '<span class="folder-pagination-next is-disabled" aria-disabled="true">次へ</span>';
        }
        $html .= '</nav>';

        return $html;
    }

    private function paginationLink(
        string $folder,
        int $page,
        string $label,
        string $class = '',
        string $rel = ''
    ): string {
        $segments = array_values(array_filter(explode('/', $folder), 'strlen'));
        $internalUrl = '/' . implode('/', array_map('rawurlencode', $segments)) . '/';
        $href = Security::publicUrl($internalUrl, $this->publicBasePath);
        if ($page > 1) {
            $href .= '?page=' . $page;
        }

        $attributes = $class !== '' ? ' class="' . $this->escape($class) . '"' : '';
        if ($rel !== '') {
            $attributes .= ' rel="' . $this->escape($rel) . '"';
        }

        return '<a href="' . $this->escape($href) . '"' . $attributes . '>' . $this->escape($label) . '</a>';
    }

    public function sectionLinks(array $pages, string $currentUrl = ''): string
    {
        $pages = $this->publicPages($pages);
        if ($pages === []) {
            return '';
        }

        $currentUrl = $this->normalizeInternalUrl($currentUrl);
        $sections = [];

        foreach ($pages as $page) {
            $path = (string) ($page['path'] ?? '');
            if ($path === '' || strpos($path, '/') === false) {
                continue;
            }

            $parts = explode('/', $path);
            $folder = (string) $parts[0];
            if ($folder === '') {
                continue;
            }

            if (!isset($sections[$folder])) {
                $sections[$folder] = [
                    'folder' => $folder,
                ];
            }
        }

        if ($sections === []) {
            return '';
        }

        ksort($sections, SORT_NATURAL);

        $html = '';
        foreach ($sections as $section) {
            $folder = (string) $section['folder'];
            $internalUrl = '/' . rawurlencode($folder) . '/';
            $href = Security::publicUrl($internalUrl, $this->publicBasePath);
            $active = $this->isCurrentSection($currentUrl, $folder);
            $attributes = $active ? ' class="site-section-link is-active" aria-current="page"' : ' class="site-section-link"';
            $html .= '<a href="' . $this->escape($href) . '"' . $attributes . '>' . $this->escape($folder) . '</a>';
        }

        return $html;
    }

    public function primaryLinks(array $pages, string $currentUrl = '', bool $includeFeed = true): string
    {
        $items = $this->primaryItems($pages, $currentUrl, $includeFeed);

        $html = '';
        foreach ($items as $index => $item) {
            $href = (string) $item['url'];
            $active = !empty($item['active']);
            $attributes = $active ? ' class="is-active" aria-current="page"' : '';
            if ($index > 0) {
                $html .= '<span class="site-link-separator" aria-hidden="true"> | </span>';
            }

            $html .= '<span class="site-link-item">';
            if ($active) {
                $html .= '<span class="site-link-current-mark" aria-hidden="true">[</span>';
            }
            $html .= '<a href="' . $this->escape($href) . '"' . $attributes . '>' . $this->escape((string) $item['label']) . '</a>';
            if ($active) {
                $html .= '<span class="site-link-current-mark" aria-hidden="true">]</span>';
            }
            $html .= '</span>';
        }

        return $html;
    }

    public function primaryItems(array $pages, string $currentUrl = '', bool $includeFeed = true): array
    {
        $items = [
            $this->primaryLinkItem('/', 'Home', 'home', $currentUrl, '', false, true),
            $this->primaryLinkItem('/about', 'About', 'about', $currentUrl),
        ];

        foreach ($this->firstLevelFolders($pages) as $folder) {
            $internalUrl = '/' . rawurlencode($folder) . '/';
            $items[] = [
                'label' => $folder,
                'url' => Security::publicUrl($internalUrl, $this->publicBasePath),
                'type' => 'section',
                'active' => $this->isCurrentSection($currentUrl, $folder),
                'slug' => $folder,
                'is_section' => true,
            ];
        }

        $items[] = $this->primaryLinkItem('/search/', 'Search', 'search', $currentUrl);
        $items[] = $this->primaryLinkItem('/tags/', 'Tags', 'tags', $currentUrl);
        if ($includeFeed) {
            $items[] = [
                'label' => 'RSS',
                'url' => Security::publicUrl('/feed.xml', $this->publicBasePath),
                'type' => 'rss',
                'active' => false,
                'slug' => '',
                'is_section' => false,
            ];
        }

        return $items;
    }

    private function fallbackTree(string $currentUrl, bool $includeFeed = true): string
    {
        $href = Security::publicUrl('/', $this->publicBasePath);
        $class = $this->normalizeInternalUrl($currentUrl) === '/' ? ' class="is-active" aria-current="page"' : '';

        $html = '<nav class="page-tree" aria-label="サイト内ページ"><ul><li><a href="' . $this->escape($href) . '"' . $class . '>Home</a></li>';
        $html .= $this->systemListItem('/search/', '検索', $currentUrl);
        $html .= $this->systemListItem('/tags/', 'タグ一覧', $currentUrl);
        if ($includeFeed) {
            $html .= $this->systemListItem('/feed.xml', 'RSS', $currentUrl);
        }
        $html .= '</ul></nav>';

        return $html;
    }

    private function systemListItem(string $internalUrl, string $label, string $currentUrl): string
    {
        $normalizedUrl = $this->normalizeInternalUrl($internalUrl);
        $normalizedCurrent = $this->normalizeInternalUrl($currentUrl);
        $href = Security::publicUrl($normalizedUrl, $this->publicBasePath);
        $active = $normalizedCurrent === $normalizedUrl || strpos($normalizedCurrent, rtrim($normalizedUrl, '/') . '/') === 0;
        $attributes = $active ? ' class="is-active" aria-current="page"' : '';

        return '<li class="nav-system-item"><a href="' . $this->escape($href) . '"' . $attributes . '>' . $this->escape($label) . '</a></li>';
    }

    private function primaryLinkItem(
        string $internalUrl,
        string $label,
        string $type,
        string $currentUrl,
        string $slug = '',
        bool $isSection = false,
        bool $exactOnly = false
    ): array
    {
        $normalizedUrl = $this->normalizeInternalUrl($internalUrl);
        $normalizedCurrent = $this->normalizeInternalUrl($currentUrl);
        $active = $exactOnly
            ? $normalizedCurrent === $normalizedUrl
            : $normalizedCurrent === $normalizedUrl || strpos($normalizedCurrent, rtrim($normalizedUrl, '/') . '/') === 0;

        return [
            'label' => $label,
            'url' => Security::publicUrl($normalizedUrl, $this->publicBasePath),
            'type' => $type,
            'active' => $active,
            'slug' => $slug,
            'is_section' => $isSection,
        ];
    }

    private function firstLevelFolders(array $pages): array
    {
        $folders = [];
        foreach ($this->publicPages($pages) as $page) {
            $path = (string) ($page['path'] ?? '');
            if ($path === '' || strpos($path, '/') === false) {
                continue;
            }

            $folder = (string) explode('/', $path)[0];
            if ($folder !== '') {
                $folders[$folder] = true;
            }
        }

        $folders = array_map('strval', array_keys($folders));
        sort($folders, SORT_NATURAL);
        return $folders;
    }

    private function limitedFolderPages(array $folderPages, string $currentUrl): array
    {
        $limit = self::NAV_FOLDER_PAGE_LIMIT;
        foreach ($folderPages as $page) {
            $path = trim(str_replace('\\', '/', (string) ($page['path'] ?? '')), '/');
            if (substr($path, -9) === '/index.md') {
                $limit++;
                break;
            }
        }

        if (count($folderPages) <= $limit) {
            return $folderPages;
        }

        $limited = array_slice($folderPages, 0, $limit);
        foreach ($folderPages as $page) {
            $pageUrl = $this->normalizeInternalUrl((string) ($page['url'] ?? '/'));
            if ($pageUrl !== $currentUrl) {
                continue;
            }

            foreach ($limited as $visiblePage) {
                if ($this->normalizeInternalUrl((string) ($visiblePage['url'] ?? '/')) === $currentUrl) {
                    return $limited;
                }
            }

            array_pop($limited);
            $limited[] = $page;
            break;
        }

        return $limited;
    }

    private function isCurrentSection(string $currentUrl, string $folder): bool
    {
        $normalizedCurrent = $this->normalizeInternalUrl($currentUrl);
        $sectionUrl = '/' . rawurlencode($folder) . '/';

        return $normalizedCurrent === $sectionUrl || strpos($normalizedCurrent, $sectionUrl) === 0;
    }

    private function pageListItem(array $page, string $currentUrl, ?string $labelOverride = null): string
    {
        $internalUrl = $this->normalizeInternalUrl((string) ($page['url'] ?? '/'));
        $href = Security::publicUrl($internalUrl, $this->publicBasePath);
        $title = $labelOverride !== null ? $labelOverride : $this->pageTitle($page);
        $date = trim((string) ($page['date'] ?? ''));
        $active = $currentUrl !== '' && $this->normalizeInternalUrl($currentUrl) === $internalUrl;
        $attributes = $active ? ' class="is-active" aria-current="page"' : '';
        $itemAttributes = $date !== '' ? ' data-published-date="' . $this->escape($date) . '"' : '';

        return '<li' . $itemAttributes . '><a href="' . $this->escape($href) . '"' . $attributes . '>' . $this->escape($title) . '</a></li>';
    }

    private function folderIndexPage(array $pages, string $folder): ?array
    {
        foreach ($pages as $page) {
            if ($this->isFolderIndexPage($page, $folder)) {
                return $page;
            }
        }

        return null;
    }

    private function folderHasPublicPages(array $pages, string $folder): bool
    {
        if ($folder === '') {
            return false;
        }

        $prefix = $folder . '/';
        foreach ($this->publicPages($pages) as $page) {
            $path = (string) ($page['path'] ?? '');
            $relative = strpos($path, $prefix) === 0 ? substr($path, strlen($prefix)) : '';
            if ($path !== $prefix . 'index.md' && $relative !== '' && strpos($relative, '/') === false) {
                return true;
            }
        }

        return false;
    }

    private function isFolderIndexPage(array $page, string $folder): bool
    {
        return (string) ($page['path'] ?? '') === $folder . '/index.md';
    }

    private function folderContainsCurrentPage(array $pages, string $currentUrl): bool
    {
        foreach ($pages as $page) {
            $internalUrl = $this->normalizeInternalUrl((string) ($page['url'] ?? '/'));
            if ($internalUrl === $currentUrl) {
                return true;
            }
        }

        return false;
    }

    private function pageTitle(array $page): string
    {
        $title = trim((string) ($page['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        $path = (string) ($page['path'] ?? '');
        if ($path === '') {
            return 'Untitled';
        }

        return basename($path, '.md');
    }

    private function breadcrumbLabel(?array $page, string $fallback): string
    {
        if ($page === null) {
            return $fallback;
        }

        $title = trim((string) ($page['title'] ?? ''));
        return $title !== '' ? $title : $fallback;
    }

    private function publicPages(array $pages): array
    {
        return array_values(array_filter($pages, function ($page): bool {
            return is_array($page) && empty($page['draft']);
        }));
    }

    private function comparePages(array $a, array $b): int
    {
        $pathA = (string) ($a['path'] ?? '');
        $pathB = (string) ($b['path'] ?? '');
        $indexA = $this->isIndexPath($pathA);
        $indexB = $this->isIndexPath($pathB);

        if ($indexA !== $indexB) {
            return $indexA ? -1 : 1;
        }

        return PageSorter::compare($a, $b);
    }

    private function isIndexPath(string $path): bool
    {
        return $path === 'index.md' || substr($path, -9) === '/index.md';
    }

    private function pageDate(array $page): ?string
    {
        $date = trim((string) ($page['date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1) {
            return substr($date, 0, 10);
        }

        $path = (string) ($page['path'] ?? '');
        $basename = basename($path, '.md');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $basename) === 1) {
            return $basename;
        }

        return null;
    }

    private function normalizeInternalUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '/';
        }

        $url = parse_url($url, PHP_URL_PATH);
        $url = is_string($url) ? $url : '/';
        $url = preg_replace('#/+#', '/', $url) ?? '/';

        if ($url[0] !== '/') {
            $url = '/' . $url;
        }

        if ($url !== '/' && substr($url, -1) === '/' && substr_count($url, '/') > 1) {
            return $url;
        }

        return $url;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
