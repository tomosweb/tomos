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

function failThemePagination(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function writeThemePaginationFixture(string $path, string $contents): void
{
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        failThemePagination('could not write fixture: ' . $path);
    }
}

function copyThemePaginationTree(string $source, string $destination): void
{
    if (!is_dir($source)) {
        failThemePagination('theme source directory is missing: ' . $source);
    }

    foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $item) {
        $sourcePath = $source . DIRECTORY_SEPARATOR . $item;
        $destinationPath = $destination . DIRECTORY_SEPARATOR . $item;
        if (is_dir($sourcePath)) {
            if (!is_dir($destinationPath) && !mkdir($destinationPath, 0777, true) && !is_dir($destinationPath)) {
                failThemePagination('could not create theme fixture directory: ' . $destinationPath);
            }
            copyThemePaginationTree($sourcePath, $destinationPath);
            continue;
        }

        if (!is_file($sourcePath) || !copy($sourcePath, $destinationPath)) {
            failThemePagination('could not copy theme fixture file: ' . $sourcePath);
        }
    }
}

function removeThemePaginationTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeThemePaginationTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

function runThemePaginationApp(App $app, string $uri): string
{
    http_response_code(200);
    ob_start();
    try {
        $app->run($uri);
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

function firstPageListItems(string $html): array
{
    if (preg_match('/<ul class="page-list-items">(.*?)<\/ul>/s', $html, $matches) !== 1) {
        failThemePagination('rendered HTML did not contain a page list');
    }

    preg_match_all('/<li(?:\s|>)/', $matches[1], $items);
    return [$matches[1], count($items[0])];
}

function assertThemePagination(string $themeName, string $version, string $source, string $root): void
{
    $contentDir = $root . '/content';
    $themeDir = $root . '/themes/' . $themeName;
    if (!mkdir($contentDir . '/news', 0777, true) || !mkdir($root . '/themes', 0777, true)) {
        failThemePagination('could not create test directories');
    }
    if (!mkdir($themeDir, 0777, true)) {
        failThemePagination('could not create theme directory');
    }
    copyThemePaginationTree($source, $themeDir);

    writeThemePaginationFixture($root . '/theme-settings.php', "<?php\nreturn ['folders' => ['news' => ['title' => '記事一覧']]];\n");
    writeThemePaginationFixture(
        $contentDir . '/index.md',
        "---\ntitle: Home\ndraft: false\n---\nHome body\n"
    );

    for ($index = 1; $index <= 35; $index++) {
        $date = (new DateTimeImmutable('2026-01-01'))->modify('+' . (35 - $index) . ' days')->format('Y-m-d');
        writeThemePaginationFixture(
            $contentDir . '/news/article-' . sprintf('%02d', $index) . '.md',
            "---\ntitle: Article {$index}\ndate: {$date}\ndraft: false\n---\nArticle {$index} body\n"
        );
    }

    $config = [
        'site' => [
            'name' => 'Theme Pagination E2E',
            'description' => 'Theme pagination fixture',
            'url' => 'https://example.test',
            'base_path' => '',
            'public_base_path' => '',
        ],
        'paths' => [
            'content_dir' => $contentDir,
            'cache_dir' => $root . '/cache',
            'theme_dir' => $root . '/themes',
            'inbox_dir' => $root . '/inbox',
        ],
        'theme' => ['name' => $themeName],
        'features' => [
            'metadata_cache' => false,
            'html_cache' => false,
            'rss' => false,
            'sitemap' => false,
        ],
        'security' => [
            'allow_raw_html' => false,
            'content_security_policy' => false,
        ],
        'analytics' => ['ga4_measurement_id' => ''],
    ];
    $app = new App($config);

    $home = runThemePaginationApp($app, '/');
    [$homeList, $homeCount] = firstPageListItems($home);
    if ($homeCount !== 12) {
        failThemePagination("{$themeName} {$version}: homepage must render latest 12 items, got {$homeCount}");
    }
    if (strpos($home, 'folder-pagination') !== false) {
        failThemePagination("{$themeName} {$version}: homepage must not render folder pagination");
    }
    if (strpos($home, 'href="/news/"') === false) {
        failThemePagination("{$themeName} {$version}: homepage must link to the section list");
    }
    if (strpos($homeList, 'Article 1') === false || strpos($homeList, 'Article 12') === false) {
        failThemePagination("{$themeName} {$version}: homepage latest range is incorrect");
    }

    $pageOne = runThemePaginationApp($app, '/news/');
    [$pageOneList, $pageOneCount] = firstPageListItems($pageOne);
    if ($pageOneCount !== 30 || strpos($pageOne, '全35件中 1–30件を表示') === false) {
        failThemePagination("{$themeName} {$version}: first section page must render 30 of 35 items");
    }
    if (strpos($pageOneList, 'Article 1') === false || strpos($pageOneList, 'Article 30') === false || strpos($pageOneList, 'Article 31') !== false) {
        failThemePagination("{$themeName} {$version}: first section page has the wrong item range");
    }
    if (strpos($pageOne, 'href="/news/?page=2" class="folder-pagination-next"') === false) {
        failThemePagination("{$themeName} {$version}: first section page must expose the next link");
    }

    $pageTwo = runThemePaginationApp($app, '/news/?page=2');
    [$pageTwoList, $pageTwoCount] = firstPageListItems($pageTwo);
    if ($pageTwoCount !== 5 || strpos($pageTwo, '全35件中 31–35件を表示') === false) {
        failThemePagination("{$themeName} {$version}: second section page must render the remaining 5 items");
    }
    if (strpos($pageTwoList, 'Article 31') === false || strpos($pageTwoList, 'Article 35') === false || strpos($pageTwoList, 'Article 30') !== false) {
        failThemePagination("{$themeName} {$version}: second section page has the wrong item range");
    }
    if (strpos($pageTwo, 'href="/news/" class="folder-pagination-prev"') === false) {
        failThemePagination("{$themeName} {$version}: second section page must expose the previous link");
    }
    if (strpos($pageTwo, 'class="folder-pagination-next is-disabled"') === false) {
        failThemePagination("{$themeName} {$version}: second section page must disable the next link");
    }
}

$root = sys_get_temp_dir() . '/tomos-theme-pagination-' . bin2hex(random_bytes(6));
$fixtureDir = dirname(__DIR__) . '/tests/fixtures/theme-pagination';
$themeSources = [
    'tomos-blog' => [
        'version' => 'built-in',
        'source' => dirname(__DIR__) . '/themes/tomos-blog',
    ],
    'tomos-quiet' => [
        'version' => '1.0.3',
        'source' => $fixtureDir . '/tomos-quiet',
    ],
    'tomos-index' => [
        'version' => '1.0.4',
        'source' => $fixtureDir . '/tomos-index',
    ],
];

try {
    foreach ($themeSources as $themeName => $theme) {
        $themeRoot = $root . '/' . $themeName;
        if (!mkdir($themeRoot, 0777, true)) {
            failThemePagination('could not create isolated theme root');
        }
        assertThemePagination($themeName, $theme['version'], $theme['source'], $themeRoot);
    }

    echo "theme_template_pagination_check: OK (tomos-blog, tomos-quiet 1.0.3, tomos-index 1.0.4)\n";
} finally {
    removeThemePaginationTree($root);
}
