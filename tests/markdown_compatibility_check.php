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

function compatibilityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$fixturePath = __DIR__ . '/fixtures/markdown-compatibility-1.md';
$markdown = file_get_contents($fixturePath);
compatibilityAssert($markdown !== false, 'fixture could not be read');

$parser = new MarkdownParser();
$html = $parser->toHtml($markdown);

compatibilityAssert(strpos($html, '<h1>H1</h1>') !== false, 'H1 is not rendered');
compatibilityAssert(strpos($html, '<h6>H6</h6>') !== false, 'H6 is not rendered');
compatibilityAssert(strpos($html, '<hr>') !== false, 'horizontal rule is not rendered');
compatibilityAssert(strpos($html, '<em><strong>bold italic</strong></em>') !== false, 'bold italic nesting is invalid');
compatibilityAssert(strpos($html, '</em></strong>') === false, 'bold italic contains crossing closing tags');
compatibilityAssert(strpos($html, '<del>strikethrough</del>') !== false, 'strikethrough is not rendered');
compatibilityAssert(strpos($html, '<ruby>京都<rt>きょうと</rt></ruby>') !== false, 'Aozora ruby is not rendered');
compatibilityAssert(strpos($html, '<ruby>東京<rt>とうきょう</rt></ruby>') !== false, 'multiple Aozora ruby entries are not rendered');
compatibilityAssert(strpos($html, '<code>｜京都《きょうと》</code>') !== false, 'inline code unexpectedly renders ruby');
compatibilityAssert(strpos($html, '<pre><code>**not bold**\n&lt;script&gt;alert(1)&lt;/script&gt;\nhttps://example.com\n｜京都《きょうと》</code></pre>') !== false, 'fenced code unexpectedly renders ruby');
compatibilityAssert(substr_count($html, '<ul>') >= 2 && substr_count($html, '<ol>') >= 2, 'nested list containers are missing');
compatibilityAssert(strpos($html, '<input type="checkbox" disabled> incomplete task') !== false, 'unchecked task is not rendered');
compatibilityAssert(strpos($html, '<input type="checkbox" disabled checked> completed task') !== false, 'checked task is not rendered');
compatibilityAssert(strpos($html, '<pre><code class="language-php">') !== false, 'fenced language class is missing');
compatibilityAssert(strpos($html, '<a href="https://example.com/bare">https://example.com/bare</a>.') !== false, 'bare URL is not autolinked');
compatibilityAssert(strpos($html, '<a href="http://example.org/plain">http://example.org/plain</a>.') !== false, 'bare HTTP URL is not autolinked');
compatibilityAssert(strpos($html, '<a href="https://example.com/autolink">https://example.com/autolink</a>') !== false, 'angle autolink is not rendered');
compatibilityAssert(strpos($html, '<a href="#">javascript</a>') !== false, 'unsafe javascript link policy changed unexpectedly');
compatibilityAssert(substr_count($html, 'class="youtube-embed"') === 3, 'standalone YouTube lines were not all embedded');
compatibilityAssert(strpos($html, '&lt;script&gt;alert(&#039;unsafe&#039;)&lt;/script&gt;') !== false, 'raw script was not escaped');
compatibilityAssert(strpos($html, '<script>alert(\'unsafe\')</script>') === false, 'raw script unexpectedly remained active');

$unsafeRuby = $parser->toHtml('｜<img src=x onerror=alert(1)>《<script>alert(1)</script>》');
compatibilityAssert(strpos($unsafeRuby, '<ruby>&lt;img src=x onerror=alert(1)&gt;<rt>&lt;script&gt;alert(1)&lt;/script&gt;</rt></ruby>') !== false, 'ruby content is not escaped safely');
compatibilityAssert(strpos($unsafeRuby, '<img') === false && strpos($unsafeRuby, '<script>') === false, 'ruby unexpectedly enables raw HTML');

echo "markdown_compatibility_check: OK\n";
