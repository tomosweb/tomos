# Tomos Minimal

Tomos標準の最小テーマです。

## 構成

- `templates/layout.html`
- `templates/page.html`
- `templates/list.html`
- `assets/style.css`
- `assets/favicon.png`
- `assets/apple-touch-icon.png`
- `assets/ogp.png`
- `theme.json`

## 方針

テーマはHTML/CSSのみを担当します。

PHPは書きません。

ページ解決、Markdown変換、ナビゲーション、タグ、検索、RSS/sitemap生成はcoreが担当します。

## アセット

標準テーマは既存のTomosロゴアセットから作成した favicon、apple-touch-icon、OGP画像を含みます。

favicon と apple-touch-icon はHTML表示用URLとして出力され、OGP画像は `site.url` 基準の絶対URLとして出力されます。
