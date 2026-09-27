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

function tocCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$parser = new MarkdownParser();
$rendered = $parser->toHtmlWithToc(<<<'MARKDOWN'
## 概要

本文。

## 概要
### 現存在
#### C++ / HTML & 安全
## !!!

```
## コードブロック内の見出し
```
MARKDOWN
);

$html = $rendered['html'];
$toc = $rendered['toc'];
tocCheck(strpos($html, '<h2 id="概要">概要</h2>') !== false, 'Japanese H2 anchor is missing');
tocCheck(strpos($html, '<h2 id="概要-2">概要</h2>') !== false, 'duplicate H2 anchor was not suffixed');
tocCheck(strpos($html, '<h3 id="現存在">現存在</h3>') !== false, 'Japanese H3 anchor is missing');
tocCheck(strpos($html, '<h4 id="C-HTML-安全">C++ / HTML &amp; 安全</h4>') !== false, 'mixed special-character heading anchor is unsafe or missing');
tocCheck(strpos($html, '<h2 id="section">!!!</h2>') !== false, 'special-character-only heading needs a safe fallback id');
tocCheck(strpos($html, '## コードブロック内の見出し</code>') !== false, 'code block content changed');
tocCheck(strpos($html, 'id="コードブロック内の見出し"') === false, 'code block heading was parsed as Markdown');

tocCheck(strpos($toc, '<nav class="toc" aria-label="目次"><ul>') === 0, 'TOC must use a semantic navigation root');
tocCheck(strpos($toc, '<a href="#概要">概要</a>') !== false, 'TOC link for the first H2 is missing');
tocCheck(strpos($toc, '<a href="#現存在">現存在</a>') !== false, 'TOC link for the H3 is missing');
tocCheck(strpos($toc, '<a href="#C-HTML-安全">C++ / HTML &amp; 安全</a>') !== false, 'TOC link for the H4 is missing');
tocCheck(substr_count($toc, '<a href="#概要">') === 1, 'TOC must distinguish duplicate headings by unique ids');
tocCheck(strpos($toc, 'href="#概要-2"') !== false, 'TOC must include the suffixed duplicate heading');
tocCheck(strpos($toc, 'コードブロック内の見出し') === false, 'TOC must exclude code block headings');
tocCheck(strpos($toc, '<ul><li><a href="#現存在">現存在</a><ul>') !== false, 'TOC hierarchy must nest H4 under H3');

$noHeadings = $parser->toHtmlWithToc("本文だけです。\n\n```\n## code\n```");
tocCheck($noHeadings['toc'] === '', 'pages without H2-H4 headings must have an empty TOC');

$legacyHtml = $parser->toHtml("# H1\n\n## H2\n\n###### H6");
tocCheck(strpos($legacyHtml, '<h1>H1</h1>') !== false, 'H1 rendering regressed');
tocCheck(strpos($legacyHtml, '<h2 id="H2">H2</h2>') !== false, 'toHtml must also emit heading anchors');
tocCheck(strpos($legacyHtml, '<h6>H6</h6>') !== false, 'H6 rendering regressed');

echo "markdown_toc_check: OK\n";
