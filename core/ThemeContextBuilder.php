<?php

declare(strict_types=1);

namespace Tomos;

/**
 * Builds the shared Theme Context consumed by Tomos themes.
 *
 * Template loading/rendering remains in TemplateRenderer. This class owns the
 * shared semantic context so Core edition and the future GitHub edition can
 * produce the same theme data.
 */
final class ThemeContextBuilder
{
    private array $config;
    private string $effectiveThemeName;
    private string $themePath;
    private string $rootDir;
    private string $analyticsNonce;
    private ThemeSettings $themeSettings;
    private SiteBrandingAssets $siteBrandingAssets;

    public function __construct(
        array $config,
        string $effectiveThemeName,
        string $analyticsNonce = '',
        ?string $rootDir = null
    ) {
        $this->config = $config;
        $this->effectiveThemeName = $effectiveThemeName;
        $this->analyticsNonce = $analyticsNonce;

        $themesDir = rtrim((string) ($config['paths']['theme_dir'] ?? ''), DIRECTORY_SEPARATOR);
        $this->themePath = $themesDir . DIRECTORY_SEPARATOR . $this->effectiveThemeName;
        $this->rootDir = rtrim($rootDir ?? dirname($themesDir), DIRECTORY_SEPARATOR);
        $this->themeSettings = new ThemeSettings($this->rootDir);
        $this->siteBrandingAssets = new SiteBrandingAssets($this->rootDir);
    }

    public function build(array $page): array
    {
        $basePath = (string) ($this->config['site']['base_path'] ?? '');
        $publicBasePath = $this->publicBasePath();
        $site = $this->config['site'];
        $site['language'] = LanguageTag::fallback($site['language'] ?? null);
        $page['language'] = LanguageTag::fallback($page['language'] ?? null, $site['language']);
        $site['base_path'] = Security::normalizeBasePath($basePath);
        $site['public_base_path'] = Security::normalizeBasePath($publicBasePath);
        $site['home_url'] = Security::publicUrl('/', $publicBasePath);
        $site['about_url'] = Security::publicUrl('/about', $publicBasePath);
        $site['feed_url'] = !empty($this->config['features']['rss'])
            ? Security::publicUrl('/feed.xml', $publicBasePath)
            : '';
        $site['sitemap_url'] = Security::publicUrl('/sitemap.xml', $publicBasePath);

        $canRenderAnalytics = empty($this->config['security']['content_security_policy'])
            || $this->analyticsNonce !== '';
        $site['analytics_html'] = $canRenderAnalytics
            && (!array_key_exists('track_page', $page) || !empty($page['track_page']))
            ? Ga4::headHtml($this->config, $this->analyticsNonce)
            : '';

        $brandingOgpUrl = $this->siteBrandingAssets->absoluteUrl(
            'ogp',
            (string) ($site['url'] ?? ''),
            $publicBasePath
        );
        $ogpAsset = $this->themeAsset('ogp.png');
        $defaultSocialImageUrl = $brandingOgpUrl !== ''
            ? $brandingOgpUrl
            : (is_file($ogpAsset['path'])
                ? Security::absolutePublicUrl(
                    (string) ($site['url'] ?? ''),
                    '/themes/' . rawurlencode($ogpAsset['theme']) . '/assets/' . rawurlencode($ogpAsset['file']),
                    $publicBasePath
                )
                : null);

        $site['ogp_url'] = $defaultSocialImageUrl ?? '';

        $seo = SeoMetadata::build(
            $page,
            $site,
            $publicBasePath,
            $defaultSocialImageUrl,
            (string) ($this->config['paths']['content_dir'] ?? '')
        );
        $page['absolute_url'] = $seo['canonical_url'];
        $page['seo_head_html'] = SeoMetadata::headHtml(
            $seo,
            !empty($this->config['features']['rss'])
                ? Security::publicUrl('/feed.xml', $publicBasePath)
                : ''
        );
        $page['seo'] = $seo;

        $nav = [
            'home_url' => Security::publicUrl('/', $publicBasePath),
            'about_url' => Security::publicUrl('/about', $publicBasePath),
            'all_url' => Security::publicUrl('/all/', $publicBasePath),
            'tree' => $page['nav']['tree'] ?? '',
            'mobile_tree' => $page['nav']['mobile_tree'] ?? '',
            'sections' => $page['nav']['sections'] ?? '',
            'primary_links' => $page['nav']['primary_links'] ?? '',
            'primary_items' => is_array($page['nav']['primary_items'] ?? null)
                ? $page['nav']['primary_items']
                : [],
            'breadcrumbs' => $page['nav']['breadcrumbs'] ?? '',
        ];

        $listPages = (string) ($page['list']['pages'] ?? '');
        if ($listPages === '' && ($page['page_type'] ?? '') === 'virtual_folder_index') {
            $listPages = (string) ($page['folder_pages_html'] ?? '');
        }
        $list = [
            'pages' => $listPages,
            'latest_pages' => $page['list']['latest_pages'] ?? '',
        ];

        $faviconAsset = $this->faviconAsset();
        $brandingFavicon = $this->siteBrandingAssets->asset('favicon');
        $brandingFaviconUrl = $this->siteBrandingAssets->publicUrl('favicon', $publicBasePath);
        $appleTouchIconAsset = $this->themeAsset('apple-touch-icon.png');
        $themeVersion = $this->themeVersion();

        $theme = array_merge([
            'asset_url' => Security::publicUrl(
                '/themes/' . rawurlencode($this->effectiveThemeName) . '/assets',
                $publicBasePath
            ),
            'asset_version' => $themeVersion,
            'version' => $themeVersion,
            'favicon_url' => $brandingFaviconUrl !== ''
                ? $brandingFaviconUrl
                : $this->faviconUrl($faviconAsset, $publicBasePath),
            'favicon_type' => $brandingFavicon !== null
                ? (string) $brandingFavicon['mime']
                : ($faviconAsset['file'] === 'favicon.svg' ? 'image/svg+xml' : 'image/png'),
            'apple_touch_icon_url' => Security::publicUrl(
                '/themes/' . rawurlencode($appleTouchIconAsset['theme']) . '/assets/' . rawurlencode($appleTouchIconAsset['file']),
                $publicBasePath
            ),
        ], $this->themeSettings->templateContext($publicBasePath));

        $home = [
            'has_news' => false,
            'news_items' => [],
            'news_url' => Security::publicUrl('/news/', $publicBasePath),
        ];
        if ((string) ($page['internal_url'] ?? '') === '/') {
            $home = HomeNewsProvider::fromConfig(
                $this->config,
                $this->themeSettings,
                $publicBasePath
            )->context();
        }

        $tag = [
            'list' => (string) ($page['tag']['list'] ?? ''),
            'items' => is_array($page['tag']['items'] ?? null) ? $page['tag']['items'] : [],
        ];

        return [
            'site' => $site,
            'page' => $page,
            'nav' => $nav,
            'list' => $list,
            'tag' => $tag,
            'theme' => $theme,
            'home' => $home,
        ];
    }

    private function faviconAsset(): array
    {
        foreach (['favicon.svg', 'favicon.png'] as $file) {
            if (is_file($this->themePath . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $file)) {
                return [
                    'theme' => $this->effectiveThemeName,
                    'file' => $file,
                    'path' => $this->themePath . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $file,
                ];
            }
        }

        $path = $this->rootDir
            . DIRECTORY_SEPARATOR
            . 'assets'
            . DIRECTORY_SEPARATOR
            . 'tomos-default-favicon.png';

        return [
            'theme' => '',
            'file' => 'tomos-default-favicon.png',
            'path' => $path,
            'core' => true,
        ];
    }

    private function faviconUrl(array $asset, string $publicBasePath): string
    {
        $path = !empty($asset['core'])
            ? '/assets/' . rawurlencode((string) $asset['file'])
            : '/themes/' . rawurlencode((string) $asset['theme'])
                . '/assets/' . rawurlencode((string) $asset['file']);

        $url = Security::publicUrl($path, $publicBasePath);
        $version = is_file((string) ($asset['path'] ?? ''))
            ? hash_file('sha256', (string) $asset['path'])
            : $this->themeVersion();

        return $url . '?v=' . rawurlencode((string) $version);
    }

    private function themeVersion(): string
    {
        $path = $this->themePath . DIRECTORY_SEPARATOR . 'theme.json';
        $json = is_file($path) ? file_get_contents($path) : false;
        $theme = is_string($json) ? json_decode($json, true) : null;
        $version = is_array($theme) ? trim((string) ($theme['version'] ?? '')) : '';

        return preg_replace('/[^A-Za-z0-9._-]/', '', $version) ?? '';
    }

    private function themeAsset(string $file): array
    {
        $themeAssetPath = $this->themePath . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $file;
        if (is_file($themeAssetPath)) {
            return [
                'theme' => $this->effectiveThemeName,
                'file' => $file,
                'path' => $themeAssetPath,
            ];
        }

        $fallbackPath = $this->themeDirectory('tomos-minimal')
            . DIRECTORY_SEPARATOR
            . 'assets'
            . DIRECTORY_SEPARATOR
            . $file;
        if (is_file($fallbackPath)) {
            return [
                'theme' => 'tomos-minimal',
                'file' => $file,
                'path' => $fallbackPath,
            ];
        }

        return [
            'theme' => $this->effectiveThemeName,
            'file' => $file,
            'path' => $this->themePath . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $file,
        ];
    }

    private function themeDirectory(string $themeName): string
    {
        return rtrim((string) ($this->config['paths']['theme_dir'] ?? ''), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $themeName;
    }

    private function publicBasePath(): string
    {
        $publicBasePath = (string) ($this->config['site']['public_base_path'] ?? '');
        if ($publicBasePath !== '') {
            return $publicBasePath;
        }

        return (string) ($this->config['site']['base_path'] ?? '');
    }
}
