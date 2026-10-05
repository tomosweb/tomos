# Tomos Publishing Core v1 仕様

策定日: 2026-10-05  
基準: Tomos v1.1.9  
状態: Phase 3・Phase 4完了。Phase 5実装済み（Human Gate待ち）。

## 1. 目的

TomosのCore版とGitHub版が、公開処理を別々に実装しないための共通契約を定義する。

- **Core版**: 現行のホスティング設置型Tomos。PHP Web Runtimeで動作する。
- **GitHub版**: GitHub Repository / GitHub Actions / GitHub Pagesを基本経路とするTomos。
- **共通Publishing Core**: 両版が共有するコンテンツ解釈・公開モデル・Theme描画の意味論。

製品としての「Core版」と内部コンポーネントとしての「共通Publishing Core」は別概念とする。

## 2. 基本原則

1. 同じMarkdown、同じサイト設定、同じThemeから、両版で同じ公開意味論を得る。
2. GitHub版のためにCore版の既存仕様を無条件に変更しない。
3. 共通仕様のバグは共通Publishing Coreで一度だけ修正する。
4. HTTP、認証、cache、deploy等の実行環境依存処理はRuntimeへ残す。
5. Theme packageは原則としてCore版とGitHub版で共用する。
6. ThemeにPHPを持ち込まない。
7. v1.1.9の公開結果を初期互換基準とする。

## 3. 共通Publishing Coreの入力

### 3.1 Site Model

少なくとも次の意味を共通契約とする。

- site.name
- site.description
- site.url
- site.language
- site.base_path
- site.public_base_path
- theme.name
- 公開機能フラグ
- Navigation設定
- Theme Settings
- Feed設定

保存形式そのものは契約に含めない。

Core版では現在の `config.php` 等を使用でき、GitHub版では別の設定保存方式を採用できる。

### 3.2 Content Source

共通Publishing Coreが扱う論理コンテンツは次とする。

- Markdown本文
- Front Matter
- Markdownの相対path
- content配下の画像・公開asset参照
- Theme package

実ファイルをどこから読むかはRuntimeの責務とする。

### 3.3 Page Model

v1.1.9のPageRepository / MetadataIndexを基準に、少なくとも次の意味を共通化する。

- path
- url
- page_type
- title
- title_explicit
- description
- description_explicit
- excerpt
- date
- published
- updated
- image
- tags
- draft
- language
- content_raw
- search_text
- mtime / size / content_sha256等、build・freshness判定に必要な補助値

補助値はRuntimeによって保持方法が異なってよい。

## 4. 共通Publishing Coreの責務

### 4.1 Front Matter

- Front Matter解析
- title / description / date等の正規化
- draft判定
- language正規化
- Front Matter未指定時のtitle / description / date補完
- Tomos固有のFront Matter記法

### 4.2 URL規則

v1.1.9の規則を基準とする。

- `index.md` -> `/`
- `<folder>/index.md` -> `/<folder>/`
- その他の `*.md` -> 拡張子を除いたURL
- `about.md` -> fixed_page
- 通常記事 -> markdown_page
- 公開子記事があり公開index.mdがないフォルダー -> Virtual Folder候補

public_base_path、canonical URL、多言語文字列を含むURLの安全な生成規則も共通意味論とする。

### 4.3 Markdown Rendering

- Markdown -> HTML
- 見出しID
- H2-H4 TOC
- 重複タイトル見出しの扱い
- raw HTML許可設定
- 標準本文HTML

### 4.4 Wiki Link

- Wiki Link解決
- alias解決
- 公開ページへの内部リンク生成
- unresolved linkの既存v1.1.9挙動

### 4.5 Image Reference

- Markdown画像記法の解釈
- Obsidian互換画像参照
- 相対pathの安全性
- 公開URLの論理生成

画像圧縮、EXIF補正、実ファイルcopy等は共通Publishing Coreの責務に含めない。

### 4.6 Metadata / Page Catalog

- 公開ページ一覧の生成
- draft除外
- PageSorterによる順序
- link alias indexの論理生成
- Virtual Folder判定に使うページ情報

`cache/index/pages.json` の保存自体はRuntime責務とする。

### 4.7 Navigation

- Navigation tree
- mobile tree
- primary links / primary items
- breadcrumbs
- all_url
- フォルダー導線

### 4.8 Tags

- tag一覧
- tag count
- tag items
- tagページ用データ
- 公開ページだけを集計する規則

### 4.9 Virtual Folder

- Virtual Folderの成立条件
- folder title
- 直下公開記事一覧
- list templateを使うというTheme上の意味論

### 4.10 Related Items

本文中の明示的内部リンクから、

- 存在する公開ページのみ
- 自己リンク除外
- 重複除外
- 外部URL除外

というv1.1.9の意味論でrelated itemsを生成する。

推薦アルゴリズムにはしない。

### 4.11 Home News

`home.*` の構造化News Contextを共通契約とする。

### 4.12 SEO

- title / document title
- description
- canonical
- OGP
- Twitter metadata
- page type
- language
- publication / modified date
- social image URLの論理規則
- trusted SEO head生成

実ファイル存在確認などI/O部分は将来Port化できるよう分離対象とする。

### 4.13 RSS / Sitemap / robots

RSS、Sitemap、robots.txtの内容生成規則は共通化する。

Core版ではrequest時に返してよく、GitHub版ではbuild artifactとして出力してよい。

### 4.14 Search Data

検索対象データの意味は共通化する。

少なくとも、

- title
- description
- excerpt
- tags
- url
- path
- search_text

を検索可能情報として扱う。

検索の実行方式は共通契約に含めない。

- Core版: PHP SearchIndexによるrequest時検索
- GitHub版: build済みsearch indexを使うbrowser-side検索を基本候補とする

### 4.15 Theme Context / Template Renderer

Theme Contract v1を両版共通の表示契約とする。

主要namespace:

- site.*
- page.*
- nav.*
- tag.*
- list.*
- theme.*
- home.*
- search.*（検索画面で必要な範囲）

次を維持する。

- 通常変数はHTML escape
- allowlistされた三重波括弧変数だけraw HTML可
- URL変数の安全化
- Theme内PHP禁止
- home.html / page.html / list.htmlの選択規則
- Theme asset URL
- Theme Settings
- Theme compatibility validation

## 5. 共通Publishing Coreに含めないもの

### Core版固有

- HTTP request / response
- response header
- Apache / .htaccess
- Routerのrequest処理
- HTML Cache
- metadata cacheのfreshness管理
- PerformanceLogger
- Tomos Post
- 投稿Inbox
- password / Passkey / session
- setup / install.php
- Tomos Update / Browser Update
- PHPサーバー上の設定保存
- Bluesky OAuthおよびサーバー側social publishing
- PHP GDを使う投稿時画像処理
- CSP nonce等のrequest単位セキュリティ処理

### GitHub版固有

- GitHub Actions workflow
- Repository構成
- Pages deploy
- build output directory
- asset copy
- 404.html配置
- browser-side search実装
- GitHub認証
- Workspaceからのcommit / push連携
- GitHub API
- deploy status表示

GitHub APIやWorkspace連携はGitHub版MVP成立後に検討する。

## 6. Runtime Portとして分離を検討するもの

実装時には過剰な抽象化を避けつつ、次のI/O境界を候補とする。

### Content Source

Markdown・画像・Themeを読み出す。

### Config Provider

共通Site Modelへ設定を供給する。

### Artifact Output

生成HTML、RSS、Sitemap、search index、asset等を書き出す。

### External Metadata Resolver

YouTube等、外部通信を伴うmetadata取得を扱う。

Core版ではrequest/cache時、GitHub版ではbuild時に実行できる。

### Asset Resolver

画像・Theme asset等の存在確認と公開先URLの対応を扱う。

名称・interface形状は実装前に固定しすぎない。

## 7. 両版で一致させるもの

同一入力に対し、可能な範囲で次を一致させる。

- 公開 / 非公開判定
- ページtitle / description
- URLの意味
- HTML本文
- heading ID / TOC
- Wiki Link
- related items
- tag集計
- Navigation
- Virtual Folder成立条件
- SEO metadata
- RSS内容
- Sitemap内容
- Theme Context
- Theme package
- languageの意味

## 8. Runtime差を許容するもの

### 8.1 Search

検索データの意味は共通だが、実行方式は異なってよい。

### 8.2 Cache

Core版のHTML / Metadata cacheと、GitHub Actions側のbuild cacheは別物とする。

### 8.3 404

404ページの内容は共通Themeで生成可能とするが、HTTP 404応答の仕組みはHosting依存とする。

### 8.4 External Metadata

結果の意味は共通だが、取得タイミングは異なってよい。

### 8.5 Analytics / CSP

Analytics設定の意味は共通化できるが、request単位CSP nonceはGitHub版では利用できない。

Static HTMLに適したsecurity policyをGitHub版Runtimeで定義する。

## 9. フォルダーページングの未決事項

v1.1.9では `page.folder_pages_html` が1ページ30件で、

`?page=2`

のようなquery parameterを使う。

静的ホスティングでは同一HTMLファイルをqueryごとにサーバー側生成できないため、そのままではGitHub版に移植できない。

候補:

1. GitHub版のみ `/folder/page/2/` のような静的URLを生成する
2. browser-side paginationにする
3. GitHub版では一覧を全件出力する
4. 将来Core版もpath-based paginationへ変更する

Phase 4で決定・実装済み。Core版は従来どおり `?page=2` を使用し、Static Buildだけが
`/folder/page/2/` を生成する。Static Buildのpaginationは `NavigationBuilder` と
`PublishingEngine` を利用し、Core版のrequest paginationを変更しない。

**現時点ではv1.1.9 Core版の `?page=N` を変更しない。**

## 10. Configの境界

### 共通

- siteの意味
- Themeの意味
- feature flagの意味
- Navigationの意味
- Feedの意味
- languageの意味

### Core版

- `config.php`
- `theme-settings.php`
- filesystem上の保存・更新

### GitHub版

保存形式は未決定。

GitHub版では `tomos.config.php` を使用し、Core版の `config.php` を利用者へ要求しない。
`tools/build-static-site.php` の `--tomos-root` と `StaticSiteConfig` が、サイト側の
相対 `content/` と固定tagで取得したTomos本体のThemeを接続する。明示された
`site.base_path` は自動判定より優先される。

## 11. Version / Update契約

Tomosの利用者向けversionは引き続き `Tomos 1.x.x` を基本とする。

内部では、

- 共通Publishing Core
- Core版Runtime
- GitHub版Runtime

のcompatibilityを管理する。

### 共通Publishing Core更新

Markdown、URL、Theme Context、SEO等の共通変更。

両版へ一度の修正で反映する。

### Core版固有更新

Tomos Post、Passkey、Updater等。

GitHub版へ影響させない。

### GitHub版固有更新

Actions、Static Build、Pages deploy、browser search等。

Core版へ影響させない。

GitHub版サイトは利用するPublishing Core versionを固定できることを基本とし、自動最新版追従を前提にしない。

## 12. 国際化契約

Stable版以降をグローバル志向とする。

公開サイト言語と管理UI言語を分離する。

```text
site.language
admin.ui_language
```

- site.language: 公開サイトのlanguage
- admin.ui_language: Tomos管理UIのlanguage

初期UI対応は日本語 / English。

UTF-8、多言語URL、多言語title / description / tags等を標準ケースとして扱う。

GitHub版ではGitHubを隠さず、Repository / Actions / Pagesという名称をそのまま利用してよい。

## 13. v1.1.9互換Gate

共通Publishing Coreの整理後、GitHub版のHuman Gateへ進む前にCore版で次を確認する。

- 公開URLが変わらない
- draft挙動が変わらない
- Markdown HTMLが意図せず変わらない
- Theme Contextが欠落しない
- 標準Themeが動く
- 外部Theme Contractを壊さない
- Wiki Linkが動く
- image参照が動く
- Tag / Navigation / Virtual Folderが動く
- TOC / related itemsが動く
- RSS / Sitemapが動く
- SEO metadataが変わらない
- Tomos Post等のCore版固有機能にregressionがない

Core整理による差分は、意図した仕様変更以外は認めない。

## 14. 実装済みPhase 3・4・5

Phase 3では `PublishingEngine`、`PageCatalogBuilder`、`ThemeContextBuilder` を共通処理として
利用する境界を実装した。Phase 4では `StaticSiteBuilder` とそのCLIを実装し、Static Build
の公開artifact、browser-side search、content/theme asset、RSS、Sitemap、404、robots.txt、
日本語URL、Virtual Folder、Tags、`/all/`、path-based paginationを検証している。

Phase 5では `examples/tomos-github`、`tomos.config.php`、固定version取得、最小権限の
GitHub Pages workflow、Pages artifactの内容検証、`tests/github_pages_build_check.php` を
追加した。標準検証Repository名は `tomosweb/tomos-github` に固定する。

Core版のURL、Search request、query pagination、Post、Passkey、Update、Installerの仕様は
この実装によって変更しない。

## 15. Phase 1完了条件

次の条件を満たした時点でPublishing Core v1仕様を固定したとみなす。

1. 共通責務とRuntime固有責務が文書化されている。
2. Site / Page Modelの最低契約が定義されている。
3. Theme Contractを両版共通で使う方針が確定している。
4. Searchが「共通データ・別実行方式」と整理されている。
5. paginationの未決事項が明示されている。
6. Configの意味と保存方式が分離されている。
7. Updateの責務分離が定義されている。
8. 日英UIと公開サイト言語の分離が定義されている。
9. v1.1.9互換Gateが定義されている。

この仕様の初期固定は設計段階の記録であり、現在は後続Phaseの実装記録をこの文書へ追記している。
