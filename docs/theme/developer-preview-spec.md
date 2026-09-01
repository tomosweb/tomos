# Tomos Theme Specification

Compatibility baseline: Tomos 0.6.2
Status: Developer Preview

この仕様は、Tomos Theme Developer Centerの初期入口で検査する最小契約です。Themeをサーバーで実行したり保存したりする仕様ではありません。

## 現在保証する部分

Themeは、次の最小構成を持つ静的な表示ファイルです。

```text
my-theme/
├── theme.json
├── templates/
│   ├── layout.html
│   ├── page.html
│   └── list.html
└── assets/
    └── style.css
```

`templates/home.html`は任意で、トップページ専用に使われます。`theme.json`の`name`はThemeディレクトリ名と一致させ、`display_name`、Theme自身の`version`を空にしません。`requires_tomos`を指定する場合は、実際に必要な最低Tomos versionだけを指定します。

Developer Previewの必須placeholderは`docs/theme/theme-rules.json`に定義します。layoutには`page.body`とSEO Foundationの`page.seo_head_html`、pageには`page.content`、listには`list.pages`を置きます。三重波括弧のHTML出力はCoreが許可した値だけに限定されます。

CoreはMarkdown、Front Matter、routing、navigation、URL、tags、search、RSS/sitemap、SEO metadata、画像とYouTube等の本文HTMLを生成します。Themeは渡されたHTMLを表示します。Markdown Compatibility 1.0の`.youtube-embed`などはThemeのCSSで幅・比率を整えますが、ThemeでYouTubeを解析・生成しません。

assetsは静的ファイルです。`assets/style.css`は必須、favicon、apple touch icon、OGPは推奨です。responsive対応では、本文のpre/table/画像/埋め込みを狭い画面からはみ出させないことを確認します。

## 変更される可能性がある部分

Template変数の追加、Core生成HTMLのclass、navigationの細部、SEO fragmentの内部metadata、Virtual FolderやHome APIの拡張は、将来のTomos minor/majorで変更される可能性があります。Themeは未文書化のCore内部classや未定義変数へ依存しないでください。`theme-rules.json`のschema versionと対応Tomos versionを確認し、必要な場合は新しい仕様版へ追随します。

## Security baseline

Developer Previewでは、Themeの能力を表示に限定します。PHP系ファイル、script、外部JavaScript、`javascript:`、inline event handler、object/embed、Theme記述のiframe、動的コード、ネットワークAPI、cookie/storage、危険な動的HTML、meta refreshなどを静的検査します。外部stylesheet/fontや外部form送信はwarningです。base64や難読化らしき記述もwarningです。

ZIPは単一Themeディレクトリを直下に持ち、path traversal、絶対パス、異常なファイル数・展開サイズ・圧縮率、malformed/encrypted ZIPを拒否します。Coreが生成する本文HTMLはZIPのThemeファイルではないため、Theme内のiframeと混同しません。

## 判定の正本

必須構造、推奨ファイル、placeholder、制限値、静的検査patternのIDとseverityは`theme-rules.json`に集約します。CoreのPHP `ThemeValidator`はこのJSONから既存互換の判定へ取り込み、Browser Validatorにはこのcatalogを監査済みのローカルコピーとして同梱します。Browser Validatorはルール取得のための通信を行わないため、catalog変更時はCore JSONとBrowser側コピーを同じレビューで更新します。Coreのcanonical JSON SHA-256は`ThemeRules::sha256()`で計算し、Browser bundleには`RULES_HASH`として埋め込みます。CIまたは同一レビューの検査では`php tools/check-theme-rules-parity.php --browser=/path/to/theme-validator.js`を実行し、両者が一致しない場合は公開を止めます。Server-sideのTheme package policyにあるZIP展開・権限・install時の防御は、今回のBrowser-only checkerとは別の責務です。

この検査は仕様適合と一般的な危険patternの静的確認であり、完全な安全性を保証するものではありません。
