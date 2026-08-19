<?php

declare(strict_types=1);

namespace Tomos {
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
}

namespace {
    use Tomos\Route;
    use Tomos\VirtualFolderIndex;

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    function route(string $path): Route
    {
        return (new Tomos\Router())->resolve($path);
    }

    $publicArticle = [
        'path' => 'news/article-a.md',
        'draft' => false,
    ];
    $publicArticleB = [
        'path' => 'news/article-b.md',
        'draft' => false,
    ];
    $draftArticle = [
        'path' => 'news/draft.md',
        'draft' => true,
    ];
    $nestedArticle = [
        'path' => 'news/archive/article.md',
        'draft' => false,
    ];
    $publicIndex = [
        'path' => 'news/index.md',
        'draft' => false,
    ];

    $virtual = VirtualFolderIndex::find(route('/news/'), [$publicArticle, $publicArticleB]);
    check(is_array($virtual), 'public direct child should create a virtual folder index');
    check(($virtual['page_type'] ?? '') === 'virtual_folder_index', 'page type should be virtual_folder_index');
    check(($virtual['folder_path'] ?? '') === 'news', 'folder path should be news');
    check(($virtual['url'] ?? '') === '/news/', 'virtual folder URL should be /news/');
    check(($virtual['title'] ?? '') === 'news', 'default title should be folder basename');

    check(
        VirtualFolderIndex::find(route('/news/'), [$draftArticle]) === null,
        'draft-only folder should not create a virtual folder index'
    );
    check(
        VirtualFolderIndex::find(route('/news/'), [$nestedArticle]) === null,
        'nested-only folder should not create a virtual folder index'
    );
    check(
        VirtualFolderIndex::find(route('/news/article'), [$publicArticle]) === null,
        'article route should not create a virtual folder index'
    );
    check(
        VirtualFolderIndex::find(route('/news/'), [$publicIndex]) === null,
        'index.md alone should not count as a list article'
    );
    check(
        VirtualFolderIndex::find(route('/'), [$publicArticle]) === null,
        'root route should not create a virtual folder index'
    );

    echo "virtual_folder_index_check: OK\n";
}
