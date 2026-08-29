<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
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

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL . "HTML: {$haystack}" . PHP_EOL);
        exit(1);
    }
}

function assertNotContainsText(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, $message . PHP_EOL . "HTML: {$haystack}" . PHP_EOL);
        exit(1);
    }
}

function assertSameText(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL . "Expected: {$expected}\nActual: {$actual}\n");
        exit(1);
    }
}

$parser = new MarkdownParser();

$normalCases = [
    'watch URL' => [
        "https://youtube.com/watch?v=dQw4w9WgXcQ",
        'https://www.youtube.com/embed/dQw4w9WgXcQ',
    ],
    'youtu.be URL' => [
        "https://youtu.be/dQw4w9WgXcQ",
        'https://www.youtube.com/embed/dQw4w9WgXcQ',
    ],
    'shorts URL' => [
        "https://www.youtube.com/shorts/dQw4w9WgXcQ",
        'https://www.youtube.com/embed/dQw4w9WgXcQ',
    ],
    'youtu.be query URL' => [
        "https://youtu.be/dQw4w9WgXcQ?si=xxxxx",
        'https://www.youtube.com/embed/dQw4w9WgXcQ',
    ],
    'watch query URL' => [
        "https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30s",
        'https://www.youtube.com/embed/dQw4w9WgXcQ',
    ],
    'watch fragment URL' => [
        "https://www.youtube.com/watch?v=dQw4w9WgXcQ#example",
        'https://www.youtube.com/embed/dQw4w9WgXcQ',
    ],
    'shorts query URL' => [
        "https://www.youtube.com/shorts/dQw4w9WgXcQ?si=xxxxx",
        'https://www.youtube.com/embed/dQw4w9WgXcQ',
    ],
];

foreach ($normalCases as $label => [$markdown, $embedUrl]) {
    $html = $parser->toHtml($markdown);
    assertContainsText('<div class="youtube-embed"><iframe', $html, "{$label} was not embedded");
    assertContainsText('src="' . $embedUrl . '"', $html, "{$label} generated the wrong embed URL");
    assertContainsText('loading="lazy"', $html, "{$label} is not lazy-loaded");
    assertContainsText('allowfullscreen', $html, "{$label} is not fullscreen-capable");
    assertNotContainsText('si=xxxxx', $html, "{$label} leaked the input query into the iframe");
    assertNotContainsText('t=30s', $html, "{$label} leaked the input query into the iframe");
    assertNotContainsText('#example', $html, "{$label} leaked the input fragment into the iframe");
}

$mixed = $parser->toHtml("前の本文です。\n\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\n\n後の本文です。\n\nhttps://youtu.be/9bZkp7q19f0");
assertContainsText('<p>前の本文です。</p>', $mixed, 'preceding paragraph was changed');
assertContainsText('<p>後の本文です。</p>', $mixed, 'following paragraph was changed');
assertSameText('2', (string) substr_count($mixed, 'class="youtube-embed"'), 'multiple videos were not embedded');

$nonEmbedCases = [
    'markdown link' => '[参考動画](https://www.youtube.com/watch?v=dQw4w9WgXcQ)',
    'inline URL' => 'この動画 https://www.youtube.com/watch?v=dQw4w9WgXcQ を見てください。',
    'fenced code' => "```\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\n```",
    'blockquote' => '> https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'unordered list' => '- https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'ordered list' => '1. https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'table' => "| 動画 | URL |\n|---|---|\n| 参考 | https://www.youtube.com/watch?v=dQw4w9WgXcQ |",
    'unsupported domain' => 'https://www.youtube-nocookie.com/watch?v=dQw4w9WgXcQ',
    'invalid ID' => 'https://www.youtube.com/watch?v=short',
    'lookalike domain' => 'https://www.youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
];

foreach ($nonEmbedCases as $label => $markdown) {
    $html = $parser->toHtml($markdown);
    assertNotContainsText('youtube-embed', $html, "{$label} was unexpectedly embedded");
}

$regression = $parser->toHtml(<<<'MARKDOWN'
# 見出し

段落 **太字** と [リンク](https://example.com/)。

- 箇条書き

> 引用

```php
echo '<script>alert(1)</script>';
```

| A | B |
|---|---|
| 1 | 2 |

<script>alert(1)</script>
MARKDOWN
);
assertContainsText('<h1>見出し</h1>', $regression, 'heading regression');
assertContainsText('<strong>太字</strong>', $regression, 'paragraph regression');
assertContainsText('<a href="https://example.com/">リンク</a>', $regression, 'link regression');
assertContainsText('<ul>', $regression, 'list regression');
assertContainsText('<blockquote><p>引用</p></blockquote>', $regression, 'blockquote regression');
assertContainsText('<pre><code>echo &#039;&lt;script&gt;alert(1)&lt;/script&gt;&#039;;</code></pre>', $regression, 'code block regression');
assertContainsText('<table>', $regression, 'table regression');
assertContainsText('&lt;script&gt;alert(1)&lt;/script&gt;', $regression, 'raw HTML was not suppressed');
assertNotContainsText('<script>', $regression, 'raw HTML unexpectedly executed');

$wikiParser = new Tomos\WikiLinkParser([
    ['url' => '/about', 'title' => 'About', 'draft' => false],
], '/tomos');
$wikiHtml = $wikiParser->restore($parser->toHtml($wikiParser->replace('[[about]]')));
assertContainsText('<a href="/tomos/about" class="wiki-link">About</a>', $wikiHtml, 'Wiki link regression');

$imageParser = new Tomos\ImageEmbedParser('/tmp/tomos-test-content', '/tomos');
$imageHtml = $imageParser->restore($parser->toHtml($imageParser->replace('![画像](https://example.com/photo.jpg)', 'index.md')));
assertContainsText('<img src="https://example.com/photo.jpg" alt="画像">', $imageHtml, 'image regression');

echo "markdown_parser_youtube_check: OK\n";
