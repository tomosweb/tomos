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

use Tomos\MarkdownParser;
use Tomos\PublishingEngine;

function publishingEngineCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/tomos-publishing-engine-' . bin2hex(random_bytes(6));
$content = $root . '/content';

try {
    if (!mkdir($content, 0700, true) && !is_dir($content)) {
        throw new RuntimeException('could not create content fixture');
    }

    $config = [
        'site' => [
            'base_path' => '/tomos',
            'public_base_path' => '',
        ],
        'paths' => [
            'content_dir' => $content,
        ],
        'features' => [
            'rss' => false,
        ],
    ];

    $engine = new PublishingEngine($config);
    $parser = new MarkdownParser(false, '/tomos', '');

    $pages = [
        [
            'path' => 'article.md',
            'url' => '/article',
            'title' => '起点記事',
            'draft' => false,
        ],
        [
            'path' => 'second.md',
            'url' => '/second',
            'title' => '第二記事',
            'draft' => false,
        ],
        [
            'path' => 'draft.md',
            'url' => '/draft',
            'title' => '下書き',
            'draft' => true,
        ],
    ];

    $page = [
        'path' => 'article.md',
        'url' => '/article',
        'title' => '起点記事',
        'content_raw' => "# 起点記事\n\n## 節\n\n[第二記事](/second)\n[下書き](/draft)\n[外部](https://example.com/)\n",
    ];

    $rendered = $engine->renderMarkdownContent(
        $page,
        $parser,
        $pages,
        '/tomos',
        ['aliases' => [], 'conflicts' => []]
    );

    publishingEngineCheck(strpos($rendered['html'], '<h1') === false, 'duplicate title heading was not removed');
    publishingEngineCheck(strpos($rendered['html'], 'id="節"') !== false, 'heading id was not generated');
    publishingEngineCheck(strpos($rendered['toc'], '<nav class="toc"') !== false, 'TOC was not generated');
    publishingEngineCheck(count($rendered['related_items']) === 1, 'related items were not filtered');
    publishingEngineCheck($rendered['related_items'][0]['title'] === '第二記事', 'related item title mismatch');
    publishingEngineCheck($rendered['related_items'][0]['url'] === '/tomos/second', 'related item URL mismatch');

    $tagsHtml = $engine->pageTagsHtml(['哲学', '哲学', 'test.tag'], '/tomos');
    publishingEngineCheck(substr_count($tagsHtml, 'class="tag-link"') === 2, 'tags were not deduplicated');
    publishingEngineCheck(strpos($tagsHtml, '/tomos/tags/%E5%93%B2%E5%AD%A6') !== false, 'Japanese tag URL mismatch');
    publishingEngineCheck(strpos($tagsHtml, '/tomos/tags/test%2Etag') !== false, 'dot tag URL mismatch');

    echo "publishing_engine_boundary_check: OK\n";
} finally {
    if (is_dir($content)) {
        foreach (array_diff(scandir($content) ?: [], ['.', '..']) as $item) {
            @unlink($content . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($content);
    }
    @rmdir($root);
}
