# Markdown Compatibility 1.0

Tomos Markdown 1.0 is a stable Markdown subset for the path from Markdown to a Tomos page. It is not a promise of complete CommonMark, GFM, or Obsidian compatibility.

Supported syntax includes headings H1-H6, paragraphs, Tomos single-line breaks, emphasis, strikethrough, blockquotes, unordered and ordered lists including nesting, fenced code blocks, links, HTTP(S) autolinks, images, and GFM-compatible tables with alignment.

| 記法 / 機能 | 分類 | 備考 |
| --- | --- | --- |
| H1-H6、段落、改行、強調、引用、リスト、code | 対応 | Tomos Markdown 1.0の基本記法 |
| strikethrough、task list、table、alignment、autolink、fenced language | 対応 | GFM-compatible subset |
| `[[page]]`、`[[page|label]]`、`[[page#heading|label]]` | Tomos Extension | 公開Coreの内部リンク |
| `![[image.jpg]]` | Tomos Extension | 画像形式のみ。任意ページembedではない |
| raw HTML、script、style、SVG、任意iframe | 非対応 | 標準設定では実行・適用しない |
| `![[page]]`、callout、footnote、highlight、block reference | 非対応 | Obsidian完全互換は目標外 |
| `allow_raw_html=true` | 制限あり | Compatibility 1.0対象外のadvanced / unsafe configuration |

Task list items such as `- [ ] item` and `- [x] item` are rendered as disabled, read-only checkboxes. A fenced language such as ```` ```php ```` is retained as `class="language-php"`; syntax highlighting is outside this compatibility contract.

Tomos Extensions are `[[page]]`, `[[page|label]]`, `[[page#heading|label]]`, and image-only `![[image.jpg]]`. Arbitrary page embeds are not included.

Raw HTML is not a Markdown feature in the standard configuration. HTML tags, scripts, styles, SVG, arbitrary iframes, event handlers, and dangerous URL attributes are not executed as authored HTML. The dedicated standalone YouTube URL extension remains separate from raw HTML.

The compatibility fixture in `tests/fixtures/markdown-compatibility-1.md` is the executable reference for Core and Write Preview behavior.
