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

function failVirtualFolderE2e(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function writeVirtualFolderFixture(string $path, string $contents): void
{
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        failVirtualFolderE2e('could not write fixture: ' . $path);
    }
}

function removeVirtualFolderTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeVirtualFolderTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

function runVirtualFolderApp(App $app, string $uri): array
{
    http_response_code(200);
    ob_start();
    try {
        $app->run($uri);
        $html = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }

    return [http_response_code(), $html];
}

$root = sys_get_temp_dir() . '/tomos-virtual-folder-app-' . bin2hex(random_bytes(6));
$contentDir = $root . '/content';
$cacheDir = $root . '/cache';
$themesDir = $root . '/themes';
$themeDir = $themesDir . '/test-theme';

@mkdir($contentDir . '/news', 0777, true);
@mkdir($contentDir . '/about', 0777, true);
@mkdir($cacheDir, 0777, true);
@mkdir($themeDir . '/templates', 0777, true);
@mkdir($themeDir . '/assets', 0777, true);

try {
    writeVirtualFolderFixture($themeDir . '/theme.json', json_encode([
        'name' => 'test-theme',
        'display_name' => 'Virtual Folder E2E',
        'version' => '1.0.0',
        'supports' => ['responsive' => true],
    ], JSON_UNESCAPED_SLASHES));
    writeVirtualFolderFixture($themeDir . '/templates/layout.html', '<html><body>{{{ page.body }}}</body></html>');
    writeVirtualFolderFixture($themeDir . '/templates/page.html', '<article data-template="page">{{{ page.content }}}</article>');
    writeVirtualFolderFixture($themeDir . '/templates/list.html', '<main data-template="list"><h1>{{ page.title }}</h1>{{{ list.pages }}}</main>');
    writeVirtualFolderFixture($themeDir . '/assets/style.css', 'body{}');
    writeVirtualFolderFixture($themeDir . '/assets/favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    writeVirtualFolderFixture($themeDir . '/assets/apple-touch-icon.png', 'icon');
    writeVirtualFolderFixture($themeDir . '/assets/ogp.png', 'ogp');
    writeVirtualFolderFixture($root . '/theme-settings.php', "<?php\nreturn ['folders' => ['news' => ['title' => '更新情報']]];\n");

    $config = [
        'site' => [
            'name' => 'Virtual Folder E2E',
            'description' => '',
            'url' => 'https://example.test',
            'base_path' => '',
            'public_base_path' => '',
        ],
        'paths' => [
            'content_dir' => $contentDir,
            'cache_dir' => $cacheDir,
            'theme_dir' => $themesDir,
            'inbox_dir' => $root . '/inbox',
        ],
        'theme' => ['name' => 'test-theme'],
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

    writeVirtualFolderFixture(
        $contentDir . '/news/index.md',
        "---\ntitle: News\ndraft: true\n---\nDraft index body\n"
    );
    writeVirtualFolderFixture(
        $contentDir . '/news/article-a.md',
        "---\ntitle: Article A\ndate: 2026-08-18\ndraft: false\n---\nArticle A body\n"
    );
    writeVirtualFolderFixture(
        $contentDir . '/news/article-b.md',
        "---\ntitle: Article B\ndate: 2026-08-19\ndraft: false\n---\nArticle B body\n"
    );

    [$status, $html] = runVirtualFolderApp($app, '/news/');
    if ($status !== 200 || strpos($html, 'data-template="list"') === false || strpos($html, '>更新情報<') === false) {
        failVirtualFolderE2e('Test 1: draft index with public children must render list.html with HTTP 200');
    }
    if (strpos($html, 'Article A') === false || strpos($html, 'Article B') === false) {
        failVirtualFolderE2e('Test 1: public child articles must be listed');
    }
    if (strpos($html, 'Draft index body') !== false) {
        failVirtualFolderE2e('Test 1: draft index body must not be rendered');
    }

    writeVirtualFolderFixture(
        $contentDir . '/news/index.md',
        "---\ntitle: Real News Index\ndraft: false\n---\nReal index body\n"
    );
    [$status, $html] = runVirtualFolderApp($app, '/news/');
    if ($status !== 200 || strpos($html, 'data-template="page"') === false || strpos($html, 'Real index body') === false) {
        failVirtualFolderE2e('Test 2: public index.md must win over virtual folder routing');
    }
    if (strpos($html, 'data-template="list"') !== false) {
        failVirtualFolderE2e('Test 2: public index.md must not use list.html');
    }

    @unlink($contentDir . '/news/index.md');
    [$status, $html] = runVirtualFolderApp($app, '/news/');
    if ($status !== 200 || strpos($html, 'data-template="list"') === false) {
        failVirtualFolderE2e('Test 3: missing index.md with public children must render a virtual folder');
    }

    @unlink($contentDir . '/news/article-a.md');
    @unlink($contentDir . '/news/article-b.md');
    [$status, $html] = runVirtualFolderApp($app, '/news/');
    if ($status !== 404 || strpos($html, 'data-template="list"') !== false) {
        failVirtualFolderE2e('Test 4: folder without public children must return 404');
    }

    writeVirtualFolderFixture(
        $contentDir . '/about/index.md',
        "---\ntitle: About\ndraft: false\n---\nAbout body\n"
    );
    [$status, $html] = runVirtualFolderApp($app, '/about/');
    if ($status !== 200 || strpos($html, 'About body') === false) {
        failVirtualFolderE2e('Test 5: normal Markdown page must continue to render');
    }

    echo "virtual_folder_app_e2e_check: OK\n";
} finally {
    removeVirtualFolderTree($root);
}
