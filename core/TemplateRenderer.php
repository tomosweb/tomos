<?php

declare(strict_types=1);

namespace Tomos;

final class TemplateRenderer
{
    private array $config;
    private string $themePath;
    private array $allowedHtmlVariables = [
        'page.body' => true,
        'page.content' => true,
        'page.meta_html' => true,
        'page.toc' => true,
        'page.tags_html' => true,
        'page.folder_pages_html' => true,
        'nav.tree' => true,
        'nav.mobile_tree' => true,
        'nav.sections' => true,
        'nav.primary_links' => true,
        'nav.breadcrumbs' => true,
        'list.pages' => true,
        'list.latest_pages' => true,
        'tag.list' => true,
        'search.results' => true,
        'site.analytics_html' => true,
    ];
    private array $urlVariables = [
        'site.home_url' => true,
        'site.about_url' => true,
        'site.feed_url' => true,
        'site.sitemap_url' => true,
        'page.url' => true,
        'nav.home_url' => true,
        'nav.about_url' => true,
        'theme.asset_url' => true,
        'theme.favicon_url' => true,
        'theme.apple_touch_icon_url' => true,
    ];
    private array $absoluteUrlVariables = [
        'site.ogp_url' => true,
        'page.absolute_url' => true,
    ];
    private array $undefinedVariables = [];
    private array $blockedHtmlVariables = [];
    private string $effectiveThemeName;
    private string $analyticsNonce;

    public function __construct(array $config, string $analyticsNonce = '')
    {
        $this->config = $config;
        $this->analyticsNonce = $analyticsNonce;
        $this->effectiveThemeName = $this->resolveThemeName((string) ($config['theme']['name'] ?? 'tomos-minimal'));
        $this->themePath = rtrim((string) $config['paths']['theme_dir'], DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $this->effectiveThemeName;
        $this->assertThemeDoesNotContainPhp();
    }

    public function renderPage(array $page): string
    {
        $pageTemplate = $this->loadTemplate($this->pageTemplateName($page));
        $layoutTemplate = $this->loadTemplate('layout.html');

        $context = $this->baseContext($page);
        $pageBody = $this->renderTemplate($pageTemplate, $context);
        $context['page']['body'] = $pageBody;

        return $this->renderTemplate($layoutTemplate, $context);
    }

    /**
     * @return array<string, bool>
     */
    public function requiredVariablesForPage(array $page): array
    {
        $template = $this->loadTemplate($this->pageTemplateName($page))
            . "\n"
            . $this->loadTemplate('layout.html');
        $matched = preg_match_all(
            '/\{\{\{?\s*(?:#|\/)?\s*([a-zA-Z0-9_.]+)\s*\}?\}\}/',
            $template,
            $matches
        );
        if ($matched === false || empty($matches[1])) {
            return [];
        }

        return array_fill_keys(array_values(array_unique($matches[1])), true);
    }

    private function pageTemplateName(array $page): string
    {
        $internalUrl = (string) ($page['internal_url'] ?? $page['url'] ?? '');

        if ($internalUrl === '/' && $this->templateExists('home.html')) {
            return 'home.html';
        }

        return 'page.html';
    }

    public function renderStringForCheck(string $template, array $context): string
    {
        return $this->renderTemplate($template, $context);
    }

    public function getDiagnostics(): array
    {
        return [
            'undefined_variables' => array_values(array_unique($this->undefinedVariables)),
            'blocked_html_variables' => array_values(array_unique($this->blockedHtmlVariables)),
        ];
    }

    public function renderErrorPage(int $status, string $title, string $message): string
    {
        $publicBasePath = $this->publicBasePath();

        return $this->renderPage([
            'title' => $title,
            'description' => $message,
            'url' => Security::publicUrl('/404', $publicBasePath),
            'content' => '<p>' . $this->escape($message) . '</p>',
            'status' => $status,
            'track_page' => false,
        ]);
    }

    private function baseContext(array $page): array
    {
        $basePath = (string) ($this->config['site']['base_path'] ?? '');
        $publicBasePath = $this->publicBasePath();
        $site = $this->config['site'];
        $site['base_path'] = Security::normalizeBasePath($basePath);
        $site['public_base_path'] = Security::normalizeBasePath($publicBasePath);
        $site['home_url'] = Security::publicUrl('/', $publicBasePath);
        $site['about_url'] = Security::publicUrl('/about', $publicBasePath);
        $site['feed_url'] = !empty($this->config['features']['rss'])
            ? Security::publicUrl('/feed.xml', $publicBasePath)
            : '';
        $site['sitemap_url'] = Security::publicUrl('/sitemap.xml', $publicBasePath);
        $canRenderAnalytics = empty($this->config['security']['content_security_policy']) || $this->analyticsNonce !== '';
        $site['analytics_html'] = $canRenderAnalytics && (!array_key_exists('track_page', $page) || !empty($page['track_page']))
            ? Ga4::headHtml($this->config, $this->analyticsNonce)
            : '';
        $site['ogp_url'] = Security::absoluteUrl(
            (string) ($site['url'] ?? ''),
            $this->absoluteInternalUrl('/themes/' . rawurlencode($this->effectiveThemeName) . '/assets/ogp.png', $publicBasePath)
        );
        $page['absolute_url'] = Security::absoluteUrl(
            (string) ($site['url'] ?? ''),
            $this->absoluteInternalUrl((string) ($page['internal_url'] ?? '/'), $publicBasePath)
        );
        $nav = [
            'home_url' => Security::publicUrl('/', $publicBasePath),
            'about_url' => Security::publicUrl('/about', $publicBasePath),
            'tree' => $page['nav']['tree'] ?? '',
            'mobile_tree' => $page['nav']['mobile_tree'] ?? '',
            'sections' => $page['nav']['sections'] ?? '',
            'primary_links' => $page['nav']['primary_links'] ?? '',
            'primary_items' => is_array($page['nav']['primary_items'] ?? null) ? $page['nav']['primary_items'] : [],
            'breadcrumbs' => $page['nav']['breadcrumbs'] ?? '',
        ];
        $list = [
            'pages' => $page['list']['pages'] ?? '',
            'latest_pages' => $page['list']['latest_pages'] ?? '',
        ];

        return [
            'site' => $site,
            'page' => $page,
            'nav' => $nav,
            'list' => $list,
            'theme' => [
                'asset_url' => Security::publicUrl('/themes/' . rawurlencode($this->effectiveThemeName) . '/assets', $publicBasePath),
                'favicon_url' => Security::publicUrl('/themes/' . rawurlencode($this->effectiveThemeName) . '/assets/' . $this->faviconFileName(), $publicBasePath),
                'favicon_type' => $this->faviconFileName() === 'favicon.svg' ? 'image/svg+xml' : 'image/png',
                'apple_touch_icon_url' => Security::publicUrl('/themes/' . rawurlencode($this->effectiveThemeName) . '/assets/apple-touch-icon.png', $publicBasePath),
            ],
        ];
    }

    private function resolveThemeName(string $themeName): string
    {
        $themesDir = rtrim((string) ($this->config['paths']['theme_dir'] ?? ''), DIRECTORY_SEPARATOR);
        $validator = new ThemeValidator($themesDir);
        if (!empty($validator->validate($themeName)['valid'])) {
            return $themeName;
        }

        if ($themeName !== 'tomos-minimal' && !empty($validator->validate('tomos-minimal')['valid'])) {
            return 'tomos-minimal';
        }

        return $themeName;
    }

    private function faviconFileName(): string
    {
        if (is_file($this->themePath . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'favicon.svg')) {
            return 'favicon.svg';
        }

        return 'favicon.png';
    }

    private function publicBasePath(): string
    {
        $publicBasePath = (string) ($this->config['site']['public_base_path'] ?? '');
        if ($publicBasePath !== '') {
            return $publicBasePath;
        }

        return (string) ($this->config['site']['base_path'] ?? '');
    }

    private function absoluteInternalUrl(string $internalUrl, string $publicBasePath): string
    {
        $siteUrl = trim((string) ($this->config['site']['url'] ?? ''));
        $sitePath = parse_url($siteUrl, PHP_URL_PATH);
        if (is_string($sitePath) && trim($sitePath, '/') !== '') {
            return $internalUrl;
        }

        $publicBasePath = Security::normalizeBasePath($publicBasePath);
        if ($internalUrl === '' || $internalUrl === '/') {
            return $publicBasePath === '' ? '/' : $publicBasePath . '/';
        }

        return $publicBasePath . '/' . ltrim($internalUrl, '/');
    }

    private function renderTemplate(string $template, array $context): string
    {
        $template = $this->renderSections($template, $context);

        $template = preg_replace_callback('/\{\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}\}/', function (array $matches) use ($context): string {
            $name = $matches[1];
            if (!isset($this->allowedHtmlVariables[$name])) {
                $this->blockedHtmlVariables[] = $name;
                return '';
            }

            $resolved = $this->resolve($context, $name);
            if (!$resolved['found']) {
                $this->undefinedVariables[] = $name;
                return '';
            }

            return $this->stringValue($resolved['value']);
        }, $template) ?? $template;

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $matches) use ($context): string {
            $name = $matches[1];
            $resolved = $this->resolve($context, $name);
            if (!$resolved['found']) {
                $this->undefinedVariables[] = $name;
                return '';
            }

            $value = $this->stringValue($resolved['value']);
            if (isset($this->urlVariables[$name]) || $name === 'url') {
                $value = Security::sanitizeAttributeUrl($value);
            } elseif (isset($this->absoluteUrlVariables[$name])) {
                $value = Security::safeHref($value);
            }

            return $this->escape($value);
        }, $template) ?? $template;
    }

    private function renderSections(string $template, array $context): string
    {
        $pattern = '/\{\{#\s*([a-zA-Z0-9_.]+)\s*\}\}(.*?)\{\{\/\s*\1\s*\}\}/s';

        do {
            $changed = false;
            $template = preg_replace_callback($pattern, function (array $matches) use ($context, &$changed): string {
                $changed = true;
                $name = $matches[1];
                $block = $matches[2];
                $resolved = $this->resolve($context, $name);
                if (!$resolved['found']) {
                    $this->undefinedVariables[] = $name;
                    return '';
                }

                $value = $resolved['value'];
                if (is_array($value)) {
                    if ($value === []) {
                        return '';
                    }

                    $html = '';
                    $lastIndex = count($value) - 1;
                    foreach ($value as $index => $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $itemContext = array_merge($context, $item);
                        $itemContext['index'] = $index;
                        $itemContext['first'] = $index === 0;
                        $itemContext['last'] = $index === $lastIndex;
                        $html .= $this->renderTemplate($block, $itemContext);
                    }
                    return $html;
                }

                return $value ? $this->renderTemplate($block, $context) : '';
            }, $template) ?? $template;
        } while ($changed && preg_match($pattern, $template) === 1);

        return $template;
    }

    private function loadTemplate(string $name): string
    {
        $path = $this->themePath . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $name;
        $content = is_file($path) ? file_get_contents($path) : false;

        if ($content === false) {
            throw new \RuntimeException('Theme template not found: ' . $name);
        }

        return $content;
    }

    private function templateExists(string $name): bool
    {
        return is_file($this->themePath . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $name);
    }

    private function resolve(array $context, string $path): array
    {
        $value = $context;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return ['found' => false, 'value' => null];
            }
            $value = $value[$part];
        }

        return ['found' => true, 'value' => $value];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function stringValue($value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return '';
    }

    private function assertThemeDoesNotContainPhp(): void
    {
        $themeRoot = realpath($this->themePath);
        if ($themeRoot === false || !is_dir($themeRoot)) {
            throw new \RuntimeException('Theme directory not found.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($themeRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['php', 'phtml', 'phar', 'php5', 'php7'], true)) {
                throw new \RuntimeException('Theme PHP files are not allowed.');
            }
        }
    }
}
