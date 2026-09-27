<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/ObsidianTagNormalizer.php';
require_once dirname(__DIR__) . '/core/PostInbox.php';

use Tomos\FrontMatterParser;
use Tomos\ObsidianTagNormalizer;
use Tomos\PostInbox;

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected:\n" . var_export($expected, true) . "\nActual:\n" . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . "\nMissing: " . $needle . "\n");
        exit(1);
    }
}

$normalizer = new ObsidianTagNormalizer(new FrontMatterParser());

$body = <<<'MD'
# 見出し

今日は #京都 を #自転車 で走った。
#生活/自転車 も記録する。
同じ #京都 は重複しない。
123は #123 だけならタグにしない。
エスケープした \#除外 は無視する。
インラインコード `#code` は無視する。
URL https://example.com/#fragment は無視する。
[リンク](https://example.com/#linktag) も無視する。

```css
.example { color: #fff; }
/* #codeblock */
```
MD;

assertSameValue(
    ['京都', '自転車', '生活/自転車'],
    $normalizer->extractInlineTags($body),
    'Obsidian inline tags must be extracted without code, URL, escaped hash, heading, or numeric false positives.'
);

$withFrontMatter = <<<'MD'
---
title: 京都散歩
tags:
  - 日記
  - 京都
draft: false
---

今日は #京都 を #自転車 で走った。
MD;

$normalized = $normalizer->normalize($withFrontMatter);
assertContains("tags:\n  - 日記\n  - 京都\n  - 自転車", $normalized, 'Existing Front Matter tags must merge with inline tags in source order.');
assertSameValue(1, substr_count($normalized, '  - 京都'), 'Duplicate inline tags must not duplicate existing Front Matter tags.');
assertContains("今日は #京都 を #自転車 で走った。", $normalized, 'Body inline tags must remain unchanged in Tomos stored Markdown.');

$withoutFrontMatter = "今日は #京都 と #自転車。\n";
$created = $normalizer->normalize($withoutFrontMatter);
assertContains("---\ntags:\n  - 京都\n  - 自転車\n---\n\n今日は #京都 と #自転車。", $created, 'Front Matter must be created only in the publishing copy when missing.');

$alreadyTagged = <<<'MD'
---
tags: [京都, 自転車]
---
#京都 #自転車
MD;
assertSameValue($alreadyTagged, $normalizer->normalize($alreadyTagged), 'Markdown must remain byte-identical when inline tags add no new metadata.');

$crlf = "---\r\ntitle: CRLF\r\n---\r\n\r\n本文 #京都\r\n";
$normalizedCrlf = $normalizer->normalize($crlf);
if (strpos($normalizedCrlf, "\r\n") === false || preg_match('/(?<!\r)\n/', $normalizedCrlf) === 1) {
    fwrite(STDERR, "CRLF line endings must be preserved.\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/tomos-obsidian-tags-' . bin2hex(random_bytes(6));
@mkdir($tmp, 0775, true);
$inbox = new PostInbox([], $tmp);
$manual = $inbox->contentForManualPublish("---\ntitle: Draft\ndraft: true\n---\n\n本文 #京都 #自転車\n");
assertContains("draft: false", $manual, 'Manual Inbox publish must still clear draft.');
assertContains("tags:\n  - 京都\n  - 自転車", $manual, 'Manual Inbox publish must normalize Obsidian inline tags.');
assertContains("本文 #京都 #自転車", $manual, 'Manual Inbox publish must not remove inline tags from the body.');

echo "obsidian_inline_tags_check: PASS\n";
