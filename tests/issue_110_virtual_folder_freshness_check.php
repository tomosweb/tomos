<?php

declare(strict_types=1);

namespace Tomos {
    function rename($from, $to): bool
    {
        if (
            !empty($GLOBALS['tomos_issue_110_fail_rename'])
            && isset($GLOBALS['tomos_issue_110_target'])
            && $to === $GLOBALS['tomos_issue_110_target']
            && strpos(basename((string) $from), basename((string) $to) . '.tmp-') === 0
        ) {
            $GLOBALS['tomos_issue_110_failed_rename_calls'] = (int) ($GLOBALS['tomos_issue_110_failed_rename_calls'] ?? 0) + 1;
            return false;
        }

        return \rename($from, $to);
    }
}

namespace {
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

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-issue-110-' . bin2hex(random_bytes(6));
    $content = $root . DIRECTORY_SEPARATOR . 'content';
    $cache = $root . DIRECTORY_SEPARATOR . 'cache';
    mkdir($content . DIRECTORY_SEPARATOR . 'news', 0775, true);
    mkdir($cache, 0775, true);

    $write = static function (string $path, string $contents): void {
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('could not write fixture: ' . $path);
        }
    };

    $removeTree = static function (string $path) use (&$removeTree): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
            $removeTree($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
    };

    try {
        $write(
            $content . DIRECTORY_SEPARATOR . 'news' . DIRECTORY_SEPARATOR . 'index.md',
            "---\ntitle: News\ndraft: false\n---\nOld public folder page\n"
        );
        $write(
            $content . DIRECTORY_SEPARATOR . 'news' . DIRECTORY_SEPARATOR . 'article.md',
            "---\ntitle: Public child\ndate: 2026-08-18\ndraft: false\n---\nPublic child\n"
        );
        $write(
            $content . DIRECTORY_SEPARATOR . 'about.md',
            "---\ntitle: About\ndraft: false\n---\nAbout\n"
        );

        $parser = new Tomos\FrontMatterParser();
        $metadataIndex = new Tomos\MetadataIndex($content, $cache, $parser, false);
        $initial = $metadataIndex->rebuild();
        if (!containsPath($initial, 'news/index.md') || !containsPath($initial, 'news/article.md')) {
            throw new RuntimeException('baseline metadata must contain the public news index and child');
        }

        $write(
            $content . DIRECTORY_SEPARATOR . 'news' . DIRECTORY_SEPARATOR . 'index.md',
            "---\ntitle: News\ndraft: true\n---\nDraft folder page\n"
        );
        $cached = $metadataIndex->loadCached();
        if (!is_array($cached) || !containsPath($cached, 'news/index.md')) {
            throw new RuntimeException('fixture must retain the stale public news index in pages.json');
        }

        $config = [
            'paths' => [
                'content_dir' => $content,
                'cache_dir' => $cache,
            ],
            'features' => [
                'metadata_cache' => true,
            ],
            'security' => [
                'allow_raw_html' => false,
            ],
        ];
        $app = new Tomos\App($config);
        $loadPages = new ReflectionMethod(Tomos\App::class, 'loadPages');

        $GLOBALS['tomos_issue_110_target'] = $metadataIndex->indexFile();
        $GLOBALS['tomos_issue_110_fail_rename'] = true;
        $GLOBALS['tomos_issue_110_failed_rename_calls'] = 0;
        $pages = $loadPages->invoke($app, $metadataIndex, new Tomos\PerformanceLogger($config));

        if (!is_array($pages)) {
            throw new RuntimeException('App::loadPages() fallback result must be an array');
        }
        if (containsPath($pages, 'news/index.md')) {
            throw new RuntimeException('App::loadPages() must not return the stale public news index');
        }
        if (!containsPath($pages, 'news/article.md')) {
            throw new RuntimeException('App::loadPages() fallback must retain the public news child');
        }
        if (($GLOBALS['tomos_issue_110_failed_rename_calls'] ?? 0) < 1) {
            throw new RuntimeException('metadata rebuild failure injection was not exercised');
        }

        $route = (new Tomos\Router())->resolve('/news/');
        $repository = new Tomos\PageRepository($content, $parser);
        $virtualPage = $repository->virtualFolderForRoute($route, $pages);
        if (!is_array($virtualPage) || ($virtualPage['page_type'] ?? null) !== 'virtual_folder_index') {
            throw new RuntimeException('/news/ must resolve as virtual_folder_index after build fallback');
        }

        $navigation = new Tomos\NavigationBuilder('');
        $folderList = $navigation->folderPageList($pages, 'news');
        if (strpos($folderList, 'news/article') === false || strpos($folderList, 'Public child') === false) {
            throw new RuntimeException('virtual folder list must contain the public News child');
        }

        $normal = $repository->findByRoute((new Tomos\Router())->resolve('/about'));
        if ($normal->status !== 'ok' || ($normal->page['path'] ?? null) !== 'about.md') {
            throw new RuntimeException('normal Markdown pages must continue to resolve');
        }

        echo "issue_110_virtual_folder_freshness_check: OK\n";
    } finally {
        $GLOBALS['tomos_issue_110_fail_rename'] = false;
        $removeTree($root);
    }

    function containsPath(array $pages, string $path): bool
    {
        foreach ($pages as $page) {
            if (is_array($page) && ($page['path'] ?? null) === $path) {
                return true;
            }
        }

        return false;
    }
}
