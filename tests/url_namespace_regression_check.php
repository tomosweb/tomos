<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/Security.php';
require_once dirname(__DIR__) . '/core/Router.php';
require_once dirname(__DIR__) . '/core/FrontMatterParser.php';
require_once dirname(__DIR__) . '/core/PublishedMetadata.php';
require_once dirname(__DIR__) . '/core/PageRepository.php';

use Tomos\PageRepository;
use Tomos\Router;

function checkUrlNamespace(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$contentDir = sys_get_temp_dir() . '/tomos-url-namespace-' . bin2hex(random_bytes(6));
if (!mkdir($contentDir . '/themes', 0700, true) || !mkdir($contentDir . '/nested', 0700, true)) {
    throw new RuntimeException('could not create URL namespace fixture');
}

try {
    file_put_contents($contentDir . '/themes.md', "---\ntitle: Themes article\n---\nReserved-looking article.\n", LOCK_EX);
    file_put_contents($contentDir . '/themes/index.md', "---\ntitle: Themes folder\n---\nReserved-looking folder page.\n", LOCK_EX);
    file_put_contents($contentDir . '/normal.md', "---\ntitle: Normal page\n---\nNormal.\n", LOCK_EX);
    file_put_contents($contentDir . '/nested/page.md', "---\ntitle: Nested page\n---\nNested.\n", LOCK_EX);
    file_put_contents($contentDir . '/日本語.md', "---\ntitle: 日本語\n---\nJapanese.\n", LOCK_EX);

    $router = new Router();
    $repository = new PageRepository($contentDir);

    $reservedArticle = $router->resolve('/themes');
    checkUrlNamespace($reservedArticle->isValid, 'reserved-looking article route is valid at Router layer');
    checkUrlNamespace($reservedArticle->contentPathCandidates === ['themes.md', 'themes/index.md'], 'article route candidate precedence is stable');
    checkUrlNamespace($repository->findByRoute($reservedArticle)->page['title'] === 'Themes article', 'article file wins for non-slash route');

    $reservedFolder = $router->resolve('/themes/');
    checkUrlNamespace($reservedFolder->contentPathCandidates === ['themes/index.md'], 'reserved-looking folder route uses index file');
    checkUrlNamespace($repository->findByRoute($reservedFolder)->page['title'] === 'Themes folder', 'folder index remains reachable at application layer');

    $normal = $router->resolve('/normal');
    checkUrlNamespace($repository->findByRoute($normal)->page['title'] === 'Normal page', 'normal page remains reachable');

    $nested = $router->resolve('/nested/page');
    checkUrlNamespace($repository->findByRoute($nested)->page['title'] === 'Nested page', 'nested page remains reachable');

    $japanese = $router->resolve('/%E6%97%A5%E6%9C%AC%E8%AA%9E');
    checkUrlNamespace($repository->findByRoute($japanese)->page['title'] === '日本語', 'encoded Japanese slug remains reachable');

    $htaccess = (string) file_get_contents(dirname(__DIR__) . '/.htaccess');
    checkUrlNamespace(strpos($htaccess, 'RewriteCond %{REQUEST_FILENAME} !-f') !== false, 'Apache file precedence remains documented');
    checkUrlNamespace(strpos($htaccess, 'RewriteCond %{REQUEST_FILENAME} !-d') !== false, 'Apache directory precedence remains documented');

    echo "url_namespace_regression_check: OK\n";
} finally {
    removeUrlNamespaceFixture($contentDir);
}

function removeUrlNamespaceFixture(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeUrlNamespaceFixture($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
