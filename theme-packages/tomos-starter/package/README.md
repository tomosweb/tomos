# Tomos Starter Theme

Tomos Themeの構成を読みながら学べる最小テーマです。デザインを完成させるためではなく、CoreとThemeの責務を読み分けるための教材として作っています。

## ファイルの役割

- `theme.json`: Theme ID、表示名、Theme自身のversion、対応Tomos version、対応機能を宣言します。
- `templates/layout.html`: 全ページのHTML外枠、SEO placeholder、navigation、stylesheetを持ちます。
- `templates/page.html`: 通常ページのtitle、metadata、Markdown本文を配置します。
- `templates/list.html`: 通常一覧とVirtual Folder一覧の入れ物です。一覧HTMLはCoreが生成します。
- `assets/style.css`: ThemeのCSS入口です。YouTubeのようなCore生成HTMLもここで表示調整します。

## 編集してよい箇所

HTMLの構造、クラス名、色、余白、文字サイズ、CSSは自由に編集できます。Theme IDを変えるときはディレクトリ名と`theme.json`の`name`を同じ新しいIDへ変更し、Theme自身の`version`も更新してください。

## 必須placeholder

次のplaceholderはThemeの必須契約です。

- `templates/layout.html`: `{{{ page.body }}}`、`{{{ page.seo_head_html }}}`
- `templates/page.html`: `{{{ page.content }}}`
- `templates/list.html`: `{{{ list.pages }}}`

`page.seo_head_html`の中身（title、description、canonical、OGP、Twitter metadata）はCoreが生成します。Theme側でSEO metadataを再実装しません。

## Markdown本文と埋め込み

Markdown変換、画像、wiki link、tags、一覧、URL解決はCoreの責務です。YouTube等の埋め込みもCoreが生成したHTMLを`page.content`として渡すため、Theme側でURL解析やiframe生成を行いません。このThemeでは`.youtube-embed`をレスポンシブに表示するCSSだけを用意しています。

## JavaScript

配布Themeは任意JavaScriptを持たないことを基本とします。`.js`ファイル、`<script>`、inline event handler、外部JavaScript、ブラウザ保存領域、ネットワークAPIは使わないでください。Theme Developer CenterのValidator画面自体に必要なJavaScriptは公式サイト側の機能であり、配布Themeの能力を広げるものではありません。

## ZIP化

このフォルダー自体を、ZIP直下の単一フォルダー`tomos-starter/`としてZIP化してください。`__MACOSX`や`.DS_Store`は含めず、ZIPをTheme Developer Centerの「Themeをチェック」へ渡します。ValidatorはZIPをサーバーへ送信せず、ブラウザ内だけで展開・静的検査します。

## 互換性

このStarter Themeの最低互換versionはTomos `0.6.1`です。Theme自身のversionとTomos本体のversionは独立して管理します。Theme仕様はCoreリポジトリの`docs/theme/theme-contract-v1.md`と`docs/theme/theme-rules.json`で確認してください。
