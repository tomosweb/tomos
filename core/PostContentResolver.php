<?php

declare(strict_types=1);

namespace Tomos;

if (!class_exists(__NAMESPACE__ . '\\PostBasicPage')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'PostBasicPage.php';
}

final class PostContentResolveResult
{
    public bool $ok;
    /** @var string[] */
    public array $errors;
    public string $contentPath;
    public string $internalUrl;
    public string $absoluteUrl;
    public string $title;
    public string $date;
    public string $updated;
    public string $file;

    /**
     * @param string[] $errors
     */
    public function __construct(
        bool $ok,
        array $errors = [],
        string $contentPath = '',
        string $internalUrl = '',
        string $absoluteUrl = '',
        string $title = '',
        string $date = '',
        string $updated = '',
        string $file = ''
    ) {
        $this->ok = $ok;
        $this->errors = $errors;
        $this->contentPath = $contentPath;
        $this->internalUrl = $internalUrl;
        $this->absoluteUrl = $absoluteUrl;
        $this->title = $title;
        $this->date = $date;
        $this->updated = $updated;
        $this->file = $file;
    }
}

final class PostContentResolver
{
    /** @var string[] */
    private const RESERVED_PATHS = [
        '/post',
        '/post/',
        '/post/reset',
        '/post/reset/',
        '/setup',
        '/setup/',
        '/search',
        '/search/',
        '/tags',
        '/tags/',
        '/feed.xml',
        '/rss.xml',
        '/sitemap.xml',
    ];

    private string $contentDir;
    private array $site;
    private PageRepository $pageRepository;
    private FrontMatterParser $frontMatterParser;

    public function __construct(array $config, string $rootDir)
    {
        $this->contentDir = (string) (($config['paths']['content_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'content'));
        $this->site = is_array($config['site'] ?? null) ? $config['site'] : [];
        $this->frontMatterParser = new FrontMatterParser();
        $this->pageRepository = new PageRepository($this->contentDir, $this->frontMatterParser);
    }

    public function resolve(string $urlInput, string $pathInput): PostContentResolveResult
    {
        $urlInput = trim($urlInput);
        $pathInput = trim($pathInput);

        if ($urlInput !== '' && $pathInput !== '') {
            return new PostContentResolveResult(false, ['URLまたはパスのどちらか一方だけを入力してください。']);
        }

        if ($urlInput === '' && $pathInput === '') {
            return new PostContentResolveResult(false, ['取り下げたいページのURL、またはcontent内のパスを入力してください。']);
        }

        return $urlInput !== ''
            ? $this->resolveUrl($urlInput)
            : $this->resolveContentPath($pathInput);
    }

    public function resolveContentPath(string $pathInput): PostContentResolveResult
    {
        $contentPath = trim(str_replace('\\', '/', $pathInput));
        if (strpos($contentPath, 'content/') === 0) {
            $contentPath = substr($contentPath, strlen('content/'));
        }

        if ($contentPath === '' || strpos($contentPath, '/') === 0 || strpos($contentPath, 'trash/') === 0) {
            return new PostContentResolveResult(false, ['content内のMarkdownファイルを指定してください。']);
        }

        if (!Security::isSafeRelativePath($contentPath) || !Security::hasAllowedExtension($contentPath, ['md'])) {
            return new PostContentResolveResult(false, ['取り下げ対象は content/ 配下の .md ファイルだけです。']);
        }

        if (PostBasicPage::isProtectedContentPath($contentPath)) {
            return new PostContentResolveResult(false, ['トップページとAboutページは取り下げできません。']);
        }

        if ($this->isReservedContentPath($contentPath)) {
            return new PostContentResolveResult(false, ['Tomosの予約パスは取り下げ対象にできません。']);
        }

        $fullPath = rtrim($this->contentDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $contentPath);
        return $this->resultFromFile($contentPath, $fullPath);
    }

    private function resolveUrl(string $urlInput): PostContentResolveResult
    {
        $parts = parse_url($urlInput);
        if ($parts === false) {
            return new PostContentResolveResult(false, ['URLを確認できませんでした。']);
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            $path = '/';
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            if (!$this->isAllowedAbsoluteUrl($parts)) {
                return new PostContentResolveResult(false, ['外部サイトのURLは取り下げ対象にできません。Tomos内の公開URL、またはcontent内のパスを入力してください。']);
            }
        }

        $internalPath = $this->internalPathFromUrlPath($path);
        if ($internalPath === null) {
            return new PostContentResolveResult(false, ['Tomos設置URLの配下にあるページを指定してください。']);
        }

        if ($this->isReservedUrlPath($internalPath)) {
            return new PostContentResolveResult(false, ['Tomosの予約パスは取り下げ対象にできません。']);
        }

        $route = (new Router(''))->resolve($internalPath);
        $lookup = $this->pageRepository->findByRoute($route);
        if ($lookup->status !== 'ok' || $lookup->page === null) {
            return new PostContentResolveResult(false, ['指定されたページに対応する公開中のMarkdownファイルが見つかりません。']);
        }

        $page = $lookup->page;
        $contentPath = (string) ($page['path'] ?? '');
        if (PostBasicPage::isProtectedContentPath($contentPath)) {
            return new PostContentResolveResult(false, ['トップページとAboutページは取り下げできません。']);
        }
        if ($this->isReservedContentPath($contentPath)) {
            return new PostContentResolveResult(false, ['Tomosの予約パスは取り下げ対象にできません。']);
        }

        return new PostContentResolveResult(
            true,
            [],
            $contentPath,
            (string) ($page['url'] ?? ''),
            $this->absoluteOrPublicUrl((string) ($page['url'] ?? '')),
            (string) ($page['title'] ?? ''),
            (string) ($page['date'] ?? ''),
            (string) ($page['updated'] ?? ''),
            (string) ($page['file'] ?? '')
        );
    }

    private function resultFromFile(string $contentPath, string $fullPath): PostContentResolveResult
    {
        $realContentDir = realpath($this->contentDir);
        $realPath = realpath($fullPath);
        if ($realContentDir === false || $realPath === false || !is_file($realPath) || is_link($realPath)) {
            return new PostContentResolveResult(false, ['指定されたMarkdownファイルが見つかりません。']);
        }

        if (!Security::isPathInside($realPath, $realContentDir)) {
            return new PostContentResolveResult(false, ['content/ 外のファイルは取り下げ対象にできません。']);
        }

        $markdown = @file_get_contents($realPath);
        if ($markdown === false) {
            return new PostContentResolveResult(false, ['指定されたMarkdownファイルを読み込めませんでした。']);
        }

        $parsed = $this->frontMatterParser->parse($markdown);
        $metadata = $this->frontMatterParser->buildPageMetadata($parsed['metadata'], $parsed['body'], $contentPath);
        $internalUrl = $this->pageRepository->urlFromContentPath($contentPath);

        return new PostContentResolveResult(
            true,
            [],
            $contentPath,
            $internalUrl,
            $this->absoluteOrPublicUrl($internalUrl),
            (string) ($metadata['title'] ?? ''),
            (string) ($metadata['date'] ?? ''),
            (string) ($metadata['updated'] ?? ''),
            $realPath
        );
    }

    private function isAllowedAbsoluteUrl(array $parts): bool
    {
        $siteUrl = (string) ($this->site['url'] ?? '');
        $siteParts = parse_url($siteUrl);
        if (!is_array($siteParts) || empty($siteParts['host'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $siteHost = strtolower((string) ($siteParts['host'] ?? ''));
        if ($host === '' || $host !== $siteHost) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        return in_array($scheme, ['http', 'https'], true);
    }

    private function internalPathFromUrlPath(string $path): ?string
    {
        $path = rawurldecode($path);
        $path = preg_replace('#/+#', '/', $path) ?? '/';
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $prefixes = [];
        $sitePath = (string) (parse_url((string) ($this->site['url'] ?? ''), PHP_URL_PATH) ?: '');
        foreach ([$sitePath, (string) ($this->site['public_base_path'] ?? ''), (string) ($this->site['base_path'] ?? '')] as $prefix) {
            $prefix = Security::normalizeBasePath($prefix);
            if ($prefix !== '' && !in_array($prefix, $prefixes, true)) {
                $prefixes[] = $prefix;
            }
        }

        foreach ($prefixes as $prefix) {
            if ($path === $prefix) {
                return '/';
            }
            if (strpos($path, $prefix . '/') === 0) {
                $stripped = substr($path, strlen($prefix));
                return $stripped === '' ? '/' : $stripped;
            }
        }

        if ($prefixes === []) {
            return $path;
        }

        return null;
    }

    private function absoluteOrPublicUrl(string $internalUrl): string
    {
        $absolute = Security::absoluteUrl((string) ($this->site['url'] ?? ''), $internalUrl);
        if ($absolute !== '') {
            return $absolute;
        }

        $publicBasePath = (string) (($this->site['public_base_path'] ?? '') ?: ($this->site['base_path'] ?? ''));
        return Security::publicUrl($internalUrl, $publicBasePath);
    }

    private function isReservedUrlPath(string $path): bool
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return false;
        }
        if (substr($path, -1) === '/') {
            return in_array($path, self::RESERVED_PATHS, true);
        }

        if (in_array($path, self::RESERVED_PATHS, true) || in_array($path . '/', self::RESERVED_PATHS, true)) {
            return true;
        }

        foreach (['/post/', '/setup/', '/search/', '/tags/'] as $prefix) {
            if (strpos($path . '/', $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    private function isReservedContentPath(string $contentPath): bool
    {
        $url = $this->pageRepository->urlFromContentPath($contentPath);
        return $this->isReservedUrlPath($url);
    }
}
