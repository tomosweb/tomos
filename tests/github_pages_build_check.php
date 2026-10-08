<?php

declare(strict_types=1);

function githubPagesCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function githubPagesWrite(string $path, string $contents): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('could not create fixture directory');
    }
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('could not write fixture');
    }
}

function githubPagesRemoveTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        githubPagesRemoveTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

$tomosRoot = dirname(__DIR__);
$root = sys_get_temp_dir() . '/tomos-github-pages-' . bin2hex(random_bytes(6));
$content = $root . '/content';
$config = $root . '/tomos.config.php';
$output = $root . '/build';
$siteUrl = 'https://pages.example.test';
$basePath = '/sample-site';

try {
    githubPagesWrite(
        $config,
        "<?php\nreturn [\n"
        . "    'site' => [\n"
        . "        'name' => 'Tomos GitHub版',\n"
        . "        'description' => 'GitHub Pages static build fixture',\n"
        . "        'url' => " . var_export($siteUrl, true) . ",\n"
        . "        'base_path' => " . var_export($basePath, true) . ",\n"
        . "        'language' => 'ja',\n"
        . "    ],\n"
        . "    'theme' => ['name' => 'tomos-minimal'],\n"
        . "    'features' => [\n"
        . "        'search' => true,\n"
        . "        'tags' => true,\n"
        . "        'rss' => true,\n"
        . "        'sitemap' => true,\n"
        . "    ],\n"
        . "];\n"
    );
    githubPagesWrite(
        $content . '/index.md',
        "---\ntitle: Tomos GitHub\ndraft: false\n---\n# Tomos GitHub\n\n[[About]]\n"
    );
    githubPagesWrite(
        $content . '/about.md',
        "---\ntitle: About\ndraft: false\n---\nAbout this fixture.\n"
    );
    githubPagesWrite(
        $content . '/news/first-post.md',
        "---\ntitle: First Post\ndate: 2026-10-05\ntags:\n  - github\n  - tomos\ndraft: false\n---\nA first post.\n"
    );
    githubPagesWrite(
        $content . '/日本語/記事.md',
        "---\ntitle: 日本語記事\ndraft: false\n---\n日本語URL fixture.\n"
    );
    githubPagesWrite(
        $content . '/draft.md',
        "---\ntitle: Draft\ndraft: true\n---\nThis must not be public.\n"
    );
    githubPagesWrite($content . '/assets/example.txt', 'content asset');
    for ($i = 1; $i <= 31; $i++) {
        githubPagesWrite(
            $content . '/archive/item-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '.md',
            "---\ntitle: Archive {$i}\ndate: 2026-01-01\ndraft: false\n---\nArchive item {$i}.\n"
        );
    }

    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($tomosRoot . '/tools/build-static-site.php')
        . ' ' . escapeshellarg('--config=' . $config)
        . ' ' . escapeshellarg('--tomos-root=' . $tomosRoot)
        . ' ' . escapeshellarg('--output=' . $output);
    $pipes = [];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    githubPagesCheck(is_resource($process), 'could not start static build CLI');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    githubPagesCheck($exitCode === 0, "static build CLI failed:\n{$stdout}\n{$stderr}");

    foreach ([
        '/index.html',
        '/about/index.html',
        '/news/first-post/index.html',
        '/日本語/記事/index.html',
        '/archive/index.html',
        '/archive/page/2/index.html',
        '/search/index.html',
        '/search-index.json',
        '/feed.xml',
        '/sitemap.xml',
        '/robots.txt',
        '/404.html',
        '/assets/tomos-static-search.js',
        '/themes/tomos-minimal/assets/style.css',
        '/content/assets/example.txt',
    ] as $relative) {
        githubPagesCheck(is_file($output . $relative), 'missing Pages artifact: ' . $relative);
    }

    $indexHtml = (string) file_get_contents($output . '/index.html');
    githubPagesCheck(
        strpos($indexHtml, $siteUrl . $basePath . '/') !== false,
        'GitHub Pages canonical base path is missing'
    );
    $japaneseHtml = (string) file_get_contents($output . '/日本語/記事/index.html');
    githubPagesCheck(
        strpos($japaneseHtml, $siteUrl . $basePath . '/%E6%97%A5%E6%9C%AC%E8%AA%9E/%E8%A8%98%E4%BA%8B') !== false,
        'Japanese canonical URL is missing'
    );
    $paginationHtml = (string) file_get_contents($output . '/archive/index.html');
    githubPagesCheck(
        strpos($paginationHtml, $basePath . '/archive/page/2/') !== false,
        'path-based pagination URL is missing'
    );

    $searchIndex = (string) file_get_contents($output . '/search-index.json');
    githubPagesCheck(strpos($searchIndex, 'Draft') === false, 'draft leaked into search index');
    $sitemap = (string) file_get_contents($output . '/sitemap.xml');
    githubPagesCheck(strpos($sitemap, '/draft') === false, 'draft leaked into sitemap');

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($output, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($output) + 1));
        githubPagesCheck(!preg_match('/\.md\z/i', $relative), 'source Markdown leaked into artifact');
        githubPagesCheck(
            !preg_match('#^(?:core|tools|tests|\.git|\.github)(?:/|$)#', $relative),
            'non-public Tomos directory leaked into artifact: ' . $relative
        );
        githubPagesCheck($relative !== 'tomos.config.php', 'config leaked into artifact');
    }

    echo "github_pages_build_check: OK\n";
} finally {
    githubPagesRemoveTree($root);
}
