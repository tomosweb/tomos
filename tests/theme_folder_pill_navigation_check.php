<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Tomos\App;

$themes = [
    'tomos-quiet' => [
        'version' => '1.0.5',
        'pillSelector' => '.quiet-more .site-section-link',
        'cssRequirements' => ['.quiet-more', 'flex-wrap: wrap', 'padding: .35rem 1rem', 'border-radius: 999px', ':focus-visible', 'overflow-wrap: anywhere'],
        'backClass' => 'quiet-back-link',
        'backCssRequirements' => ['.quiet-back-link', 'padding: .35rem 1rem', 'border-radius: 999px', ':focus-visible'],
    ],
    'tomos-index' => [
        'version' => '1.0.6',
        'pillSelector' => '.index-more .site-section-link',
        'cssRequirements' => ['.index-more', 'flex-wrap: wrap', 'padding: .35rem 1rem', 'border-radius: 999px', ':focus-visible', 'overflow-wrap: anywhere'],
        'backClass' => 'index-back-link',
        'backCssRequirements' => ['.index-back-link', 'padding: .35rem 1rem', 'border-radius: 999px', ':focus-visible'],
    ],
];

$root = sys_get_temp_dir() . '/tomos-theme-folder-pill-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);

try {
    foreach ($themes as $themeId => $theme) {
        checkThemeManifestAndCss($themeId, $theme);
        checkScenario($themeId, $theme['version'], $theme['backClass'], false, $root . '/single-' . $themeId);
        checkScenario($themeId, $theme['version'], $theme['backClass'], true, $root . '/multiple-' . $themeId);
    }
    echo "theme_folder_pill_navigation_check: OK (Quiet 1.0.5, Index 1.0.6; single/multiple/long folder names and pagination)\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    removeTree($root);
}

function checkThemeManifestAndCss(string $themeId, array $theme): void
{
    $themeRoot = dirname(__DIR__) . '/themes/' . $themeId;
    $manifest = json_decode((string) file_get_contents($themeRoot . '/theme.json'), true);
    if (!is_array($manifest) || (string) ($manifest['version'] ?? '') !== $theme['version']) {
        throw new RuntimeException($themeId . ': theme.json version mismatch');
    }

    $css = (string) file_get_contents($themeRoot . '/assets/style.css');
    if (is_file($themeRoot . '/assets/favicon.svg') || is_file($themeRoot . '/assets/favicon.png')) {
        throw new RuntimeException($themeId . ': Theme must use the Core official favicon fallback instead of bundling a duplicate');
    }
    $layout = (string) file_get_contents($themeRoot . '/templates/layout.html');
    assertContains($layout, '{{ theme.favicon_url }}', $themeId . ' favicon template variable');
    assertContains($layout, '{{ theme.asset_url }}/style.css?v={{ theme.asset_version }}', $themeId . ' versioned stylesheet template');
    foreach ($theme['cssRequirements'] as $requirement) {
        if (strpos($css, $requirement) === false) {
            throw new RuntimeException($themeId . ': CSS requirement missing: ' . $requirement);
        }
    }
    foreach ($theme['backCssRequirements'] as $requirement) {
        if (strpos($css, $requirement) === false) {
            throw new RuntimeException($themeId . ': back-link CSS requirement missing: ' . $requirement);
        }
    }
}

function checkScenario(string $themeId, string $version, string $backClass, bool $multiple, string $root): void
{
    mkdir($root . '/content/diary', 0777, true);
    if ($multiple) {
        mkdir($root . '/content/notes', 0777, true);
        mkdir($root . '/content/a-folder-name-that-is-long-enough-to-wrap-on-a-phone', 0777, true);
    }
    mkdir($root . '/themes', 0777, true);
    mkdir($root . '/assets', 0777, true);
    mkdir($root . '/themes/tomos-minimal', 0777, true);
    copyTree(dirname(__DIR__) . '/themes/tomos-minimal', $root . '/themes/tomos-minimal');
    copyTree(dirname(__DIR__) . '/themes/' . $themeId, $root . '/themes/' . $themeId);
    copy(dirname(__DIR__) . '/assets/tomos-default-favicon.png', $root . '/assets/tomos-default-favicon.png');
    $canonicalFaviconHash = hash_file('sha256', $root . '/assets/tomos-default-favicon.png');
    file_put_contents($root . '/VERSION', "1.0.2\n", LOCK_EX);
    file_put_contents($root . '/theme-settings.php', "<?php\nreturn ['folders' => ['diary' => ['title' => 'diary'], 'notes' => ['title' => 'notes']]];\n", LOCK_EX);
    file_put_contents($root . '/content/index.md', "---\ntitle: Home\ndraft: false\n---\nHome\n", LOCK_EX);

    for ($index = 1; $index <= 35; $index++) {
        $date = (new DateTimeImmutable('2026-01-01'))->modify('+' . (35 - $index) . ' days')->format('Y-m-d');
        file_put_contents(
            $root . '/content/diary/article-' . sprintf('%02d', $index) . '.md',
            "---\ntitle: Article {$index}\ndate: {$date}\ndraft: false\n---\nArticle {$index}\n",
            LOCK_EX
        );
    }
    if ($multiple) {
        file_put_contents($root . '/content/notes/entry.md', "---\ntitle: Notes entry\ndate: 2025-12-01\ndraft: false\n---\nNotes\n", LOCK_EX);
        file_put_contents($root . '/content/a-folder-name-that-is-long-enough-to-wrap-on-a-phone/entry.md', "---\ntitle: Long folder entry\ndate: 2025-11-01\ndraft: false\n---\nLong\n", LOCK_EX);
    }

    $config = [
        'site' => [
            'name' => 'Theme Folder Pill',
            'description' => 'Folder navigation test',
            'url' => 'https://example.test',
            'base_path' => '',
            'public_base_path' => '',
        ],
        'paths' => [
            'content_dir' => $root . '/content',
            'cache_dir' => $root . '/cache',
            'theme_dir' => $root . '/themes',
            'inbox_dir' => $root . '/inbox',
        ],
        'theme' => ['name' => $themeId],
        'features' => ['metadata_cache' => false, 'html_cache' => false, 'rss' => false, 'sitemap' => false],
        'security' => ['allow_raw_html' => false, 'content_security_policy' => false],
        'analytics' => ['ga4_measurement_id' => ''],
    ];

    $home = render($config, '/');
    assertPageListCount($home, 12, $themeId . ' home latest list');
    assertNotContains($home, 'folder-pagination', $themeId . ' home pagination');
    assertContains($home, '<link rel="icon" href="/assets/tomos-default-favicon.png?v=' . $canonicalFaviconHash . '" type="image/png">', $themeId . ' official favicon fallback');
    assertContains($home, '<link rel="stylesheet" href="/themes/' . $themeId . '/assets/style.css?v=' . $version . '">', $themeId . ' versioned stylesheet URL');
    $links = sectionLinks($home);
    $expected = $multiple
        ? ['/a-folder-name-that-is-long-enough-to-wrap-on-a-phone/', '/diary/', '/notes/']
        : ['/diary/'];
    assertSame($expected, array_column($links, 'href'), $themeId . ' section link URLs');
    assertSame(array_map(static fn (string $href): string => trim($href, '/'), $expected), array_column($links, 'label'), $themeId . ' section link labels');
    assertNotContains($home, '記事一覧を見る', $themeId . ' fixed section-link copy');

    $pageOne = render($config, '/diary/');
    assertPageListCount($pageOne, 30, $themeId . ' page 1 list');
    assertContains($pageOne, '全35件中 1–30件を表示', $themeId . ' page 1 summary');
    assertContains($pageOne, 'href="/diary/?page=2" class="folder-pagination-next"', $themeId . ' next link');

    $pageTwo = render($config, '/diary/?page=2');
    assertPageListCount($pageTwo, 5, $themeId . ' page 2 list');
    assertContains($pageTwo, '全35件中 31–35件を表示', $themeId . ' page 2 summary');
    assertContains($pageTwo, 'href="/diary/" class="folder-pagination-prev"', $themeId . ' previous link');

    $article = render($config, '/diary/article-01');
    assertContains($article, '<a class="' . $backClass . '" href="/">Home</a>', $themeId . ' Home back link');
    assertNotContains($article, '← すべての記事', $themeId . ' legacy back-link copy');
}

function sectionLinks(string $html): array
{
    if (preg_match_all('/<a href="([^"]+)" class="site-section-link(?: [^"]+)?">([^<]*)<\/a>/', $html, $matches, PREG_SET_ORDER) !== false) {
        return array_map(static fn (array $match): array => [
            'href' => html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'label' => trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        ], $matches);
    }
    return [];
}

function render(array $config, string $uri): string
{
    http_response_code(200);
    ob_start();
    try {
        (new App($config))->run($uri);
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

function assertPageListCount(string $html, int $expected, string $label): void
{
    if (preg_match('/<ul class="page-list-items">(.*?)<\/ul>/s', $html, $matches) !== 1) {
        throw new RuntimeException($label . ': page list is missing');
    }
    preg_match_all('/<li(?:\s|>)/', $matches[1], $items);
    assertSame($expected, count($items[0]), $label);
}

function copyTree(string $source, string $destination): void
{
    if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
        throw new RuntimeException('could not create ' . $destination);
    }
    foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $item) {
        $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
        $destinationPath = $destination . DIRECTORY_SEPARATOR . $item;
        if (is_dir($sourcePath)) {
            copyTree($sourcePath, $destinationPath);
        } elseif (!copy($sourcePath, $destinationPath)) {
            throw new RuntimeException('could not copy ' . $sourcePath);
        }
    }
}

function assertContains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($label . ': missing ' . $needle);
    }
}

function assertNotContains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($label . ': unexpected ' . $needle);
    }
}

function assertSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
