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
use Tomos\MetadataIndex;
use Tomos\PublicMetadataFreshener;

function failVirtualFolderTransition(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function writeVirtualFolderTransition(string $path, string $contents): void
{
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        failVirtualFolderTransition('could not write fixture: ' . $path);
    }
}

function removeVirtualFolderTransitionTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeVirtualFolderTransitionTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

function runVirtualFolderTransitionRequest(App $app, string $uri): array
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

$root = sys_get_temp_dir() . '/tomos-virtual-folder-transition-' . bin2hex(random_bytes(6));
$contentDir = $root . '/content';
$cacheDir = $root . '/cache';
$themeDir = $root . '/themes/test-theme';
@mkdir($contentDir . '/news', 0777, true);
@mkdir($cacheDir, 0777, true);
@mkdir($themeDir . '/templates', 0777, true);
@mkdir($themeDir . '/assets', 0777, true);

try {
    writeVirtualFolderTransition($themeDir . '/theme.json', json_encode([
        'name' => 'test-theme',
        'display_name' => 'Virtual Folder Transition',
        'version' => '1.0.0',
    ], JSON_UNESCAPED_SLASHES));
    writeVirtualFolderTransition($themeDir . '/templates/layout.html', '<html><body>{{{ page.body }}}</body></html>');
    writeVirtualFolderTransition($themeDir . '/templates/page.html', '<article data-template="page">{{{ page.content }}}</article>');
    writeVirtualFolderTransition($themeDir . '/templates/list.html', '<main data-template="list"><h1>{{ page.title }}</h1>{{{ list.pages }}}</main>');
    writeVirtualFolderTransition($themeDir . '/assets/style.css', 'body{}');
    writeVirtualFolderTransition($themeDir . '/assets/favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    writeVirtualFolderTransition($themeDir . '/assets/apple-touch-icon.png', 'icon');
    writeVirtualFolderTransition($themeDir . '/assets/ogp.png', 'ogp');
    writeVirtualFolderTransition($root . '/theme-settings.php', "<?php\nreturn ['folders' => ['news' => ['title' => '更新情報']]];\n");

    $indexFile = $contentDir . '/news/index.md';
    $publicIndex = "---\ntitle: News\ndraft: false\n---\nManual links: /news/article-a /news/article-b\n";
    $draftIndex = "---\ntitle: News\ndraft: true\n---\n";
    $draftIndex .= str_repeat(' ', strlen($publicIndex) - strlen($draftIndex));
    if (strlen($publicIndex) !== strlen($draftIndex)) {
        failVirtualFolderTransition('fixture must preserve source size');
    }

    writeVirtualFolderTransition($indexFile, $publicIndex);
    writeVirtualFolderTransition($contentDir . '/news/article-a.md', "---\ntitle: Article A\ndraft: false\n---\nA\n");
    writeVirtualFolderTransition($contentDir . '/news/article-b.md', "---\ntitle: Article B\ndraft: false\n---\nB\n");

    $config = [
        'site' => ['name' => 'Transition', 'description' => '', 'url' => 'https://example.test'],
        'paths' => [
            'content_dir' => $contentDir,
            'cache_dir' => $cacheDir,
            'theme_dir' => $root . '/themes',
            'inbox_dir' => $root . '/inbox',
        ],
        'theme' => ['name' => 'test-theme'],
        'features' => ['metadata_cache' => true, 'html_cache' => true, 'rss' => false, 'sitemap' => false],
        'security' => ['allow_raw_html' => false, 'content_security_policy' => false],
        'analytics' => ['ga4_measurement_id' => ''],
    ];
    $app = new App($config);

    PublicMetadataFreshener::ensure($config);
    [$firstStatus, $firstHtml] = runVirtualFolderTransitionRequest($app, '/news/');
    if ($firstStatus !== 200 || strpos($firstHtml, 'data-template="page"') === false || strpos($firstHtml, 'Manual links:') === false) {
        failVirtualFolderTransition('first request must render the public real index');
    }

    $oldMtime = filemtime($indexFile);
    if ($oldMtime === false) {
        failVirtualFolderTransition('could not read the initial index mtime');
    }
    writeVirtualFolderTransition($indexFile, $draftIndex);
    if (!touch($indexFile, $oldMtime)) {
        failVirtualFolderTransition('could not preserve the index mtime');
    }

    $metadataIndex = new MetadataIndex($contentDir, $cacheDir);
    if ($metadataIndex->loadFresh() !== null) {
        failVirtualFolderTransition('changed source content must make metadata cache stale');
    }

    PublicMetadataFreshener::ensure($config);
    [$secondStatus, $secondHtml] = runVirtualFolderTransitionRequest($app, '/news/');
    if ($secondStatus !== 200 || strpos($secondHtml, 'data-template="list"') === false) {
        failVirtualFolderTransition('second request must render list.html through VirtualFolderIndex');
    }
    if (strpos($secondHtml, 'Article A') === false || strpos($secondHtml, 'Article B') === false) {
        failVirtualFolderTransition('second request must list only public child articles');
    }
    if (strpos($secondHtml, 'Manual links:') !== false || strpos($secondHtml, 'data-template="page"') !== false) {
        failVirtualFolderTransition('old real-index HTML must not survive the draft transition');
    }
    if (strpos($secondHtml, '>更新情報<') === false) {
        failVirtualFolderTransition('virtual folder title must be applied after the transition');
    }

    echo "virtual_folder_transition_check: OK\n";
} finally {
    removeVirtualFolderTransitionTree($root);
}
