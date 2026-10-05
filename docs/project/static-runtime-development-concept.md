# Tomos GitHub版 開発構想

策定日: 2026-10-05  
対象: Tomos v1.1.9 / main  
状態: 構想・境界整理段階。実装は未着手。

## 呼称

本構想では、今後Tomosの2つの提供形態を次のように呼称する。

- **Core版**: 現行のホスティング環境へ設置して利用するTomos。技術的にはPHP Web Runtimeを利用する。
- **GitHub版**: GitHub対応のTomos。GitHub Repository / GitHub Actions / GitHub Pages等を利用するStatic Build Runtimeを基本とする。

なお、両者で共用する内部コンポーネントを指す場合は **共通Publishing Core** と表記し、製品呼称としての「Core版」と区別する。

## 目的

Tomosの初期設置経路として、従来のPHPレンタルサーバー版に加え、GitHub PagesやCloudflare Pages等の静的ホスティング環境を利用できる経路を検討する。

GitHub版を別製品・別Coreとして開発せず、現行Tomosの公開処理を共通のPublishing Coreとして整理し、そのCoreをCore版のPHP Web RuntimeとGitHub版のStatic Build Runtimeの双方から利用できる構造を目標とする。

## 基本構造

```text
                 Tomos Publishing Core
                         |
             +-----------+-----------+
             |                       |
       PHP Web Runtime           Static Build Runtime
       Core版                    GitHub版
```

利用者からは同じTomosとして扱い、内部で実行方式だけを分ける。

## Publishing Coreに含める責務

- Front Matter解析と正規化
- Markdown変換
- ページモデルと公開判定
- URL規則
- Wikiリンク
- Markdown画像参照の解決規則
- Metadata Indexの生成ロジック
- ページ並び順
- Tag
- Navigation
- Virtual Folder
- Related Items
- Home News
- SEO metadata
- RSS
- Sitemap
- Theme Context
- Template Renderer
- Theme Contract / Theme validationの共通仕様

同じMarkdown、同じ設定、同じThemeを入力した場合、Core版とGitHub版で可能な限り同じ公開結果を得ることを基本とする。

## Runtime固有とする責務

### Core版（PHP Web Runtime）

- HTTP request / response
- Apache routing / .htaccess
- Tomos Post
- 投稿Inbox
- 認証、Passkey、session
- PHPサーバー上の設定保存
- Tomos Update
- Browser Update
- Social Publishingのサーバー側認証処理
- HTML Cache
- setup / installer
- PHP環境・書き込み権限等の設置確認

### GitHub版（Static Build Runtime）

- 全公開ページの列挙と一括build
- HTMLファイル出力
- Theme / content assetの配置
- GitHub版search index生成
- GitHub Pages / Cloudflare Pages等へのdeploy連携
- build時に必要となる外部処理

GitHub版では、Core版の管理機能を全面移植することを目的としない。

## 移植しない、または別方式とする機能

GitHub版には以下をそのまま移植しない。

- Tomos Post管理画面
- Passkey・管理用合言葉認証
- 投稿Inbox
- setup / installer
- Tomos Update / 更新ZIP / updater self-update
- HTML Cache
- Apache / .htaccess
- PHP sessionやrate limit等のRuntime向けセキュリティ
- PHP GD前提のサーバー側画像処理
- 現行Bluesky OAuth実装

次の機能は目的を維持しつつGitHub版向けに実行方式を変える。

- Search: build時に検索データを生成し、ブラウザ側検索を基本候補とする
- Metadata Index: 生成ロジックは共通化し、アクセス高速化用cacheとしての保存方式は共通化しない
- 外部URLカード: 変換仕様は共通化し、外部通信はbuild時処理として扱う
- 画像: Markdown上の解決規則は共通化し、実ファイル配置はGitHub版が担当する
- Social Publishing: 現行サーバー側OAuthを移植せず、Workspace等を含め別経路を検討する

## 現行実装上の主な境界

現行 `core/` はPublishing Coreだけではなく、Post、認証、Bluesky、Updater等を含むため、ディレクトリ名をそのまま共通Coreの境界とは見なさない。

特に `App.php` は現在、

- HTTP制御
- Router
- MetadataIndex
- Markdown / Wiki / Image処理
- Navigation / Tag
- SEO
- Theme Context
- Template Rendering

を統括している。

GitHub版対応では `App.php` をGitHub版向けに複製せず、「1ページまたは1サイトをTomosとして組み立てる処理」をPublishing Core側へ整理し、Core版とGitHub版の双方が利用できる形を検討する。

`TemplateRenderer` はTheme共用の中心とする。`MetadataIndex` はデータモデル生成を共用し、cache I/OをRuntime側の責務として分離する方向で検討する。

## Themeの扱い

ThemeをCore版とGitHub版で二重管理しない。

現在のTheme Contractに基づく、

- `theme.json`
- HTML templates
- CSS
- static assets
- `site.*`
- `page.*`
- `nav.*`
- `tag.*`
- `list.*`
- `theme.*`
- `home.*`

のContextを基本契約として維持し、同じTheme packageが両Runtimeで利用できることを目標とする。

Theme ContractまたはFront Matter仕様の変更は両Runtimeに共通するTomos API変更として扱う。


## Stable版以降のグローバル志向

Stable版以降のTomosは、日本国内のホスティング事情だけを前提にせず、海外利用を含むグローバル志向で設計する。

GitHub版ではGitHubを世界中で広く利用される開発・公開インフラの一つとして扱い、GitHubであることを利用者から隠さない。

Tomos独自用語へ置き換えてGitHubの存在を抽象化するのではなく、必要に応じて次の名称をそのままUIやドキュメントへ表示する。

- GitHub Repository
- GitHub Pages
- GitHub Actions

TomosはGitHub専用製品にはしないが、GitHub版の最初の標準経路としてGitHubを有力候補とする。将来Cloudflare Pages等を追加する場合も、Publishing Coreを共通化し、Hosting Adapter側で差分を吸収する。

### 公開サイト言語と管理UI言語を分離する

公開サイトの言語とTomos管理画面の表示言語は別設定として扱う。

概念上は少なくとも次を分離する。

```text
site.language      = 公開サイトの言語
admin.ui_language  = 管理画面の表示言語
```

これにより、例えば次の組み合わせを可能にする。

- 英語サイトを日本語UIで管理する
- 日本語サイトを英語UIで管理する

### 管理UIの国際化

Stable版以降の管理UIは、日本語とEnglishの切り替えを正式要件とする。

対象には少なくとも次を含める。

- Tomos Post
- Site Settings
- Theme管理
- Security / Passkey
- Tomos Update
- setup
- エラー、警告、確認メッセージ
- 将来のGitHub版初期設定・管理UI

UI文言は画面コードへ直接固定せず、共通メッセージ辞書等へ分離できる構造を検討する。初期対応言語は日本語とEnglishを基本とし、将来の追加言語を妨げない構造とする。

ブラウザ言語から初期値を推定することは候補とするが、利用者が管理画面から明示的に切り替えられ、その選択を保持できることを優先する。

### グローバル対応でCore側が意識する事項

- 日本語を暗黙の前提にしない
- UTF-8と多言語文字列を標準ケースとして扱う
- 多言語URLを例外扱いしない
- 公開サイトの `site.language` と管理UI言語を混同しない
- 日付表示等のlocale依存表示をTheme / UIの責務として整理する
- Theme Contractと開発者ドキュメントを英語利用者にも成立させる
- 独自ドメイン利用を特定地域向けの例外として扱わない

この国際化方針はGitHub版だけの要件ではなく、Core版を含むTomos全体のStable版以降の設計原則とする。

## アップデートの考え方

Tomosのアップデートを「Core版とGitHub版の二重開発」にしない。

更新を次の3種類に分類する。

### 1. Publishing Coreの更新

例:

- Markdown解析の修正
- Wikiリンクの修正
- URL生成の修正
- Theme Context追加・修正
- SEO修正
- Tag / Navigation / RSS / Sitemap修正
- Front Matter仕様追加

Coreを一度修正し、Core版とGitHub版の双方が同じ共通Publishing Core更新を利用する。

Core版では従来のTomos Updateから更新する。GitHub版では指定Publishing Core versionを更新して再buildする。

### 2. PHP Runtime固有の更新

例:

- Tomos Post
- Passkey
- Inbox API
- PHP session
- Apache routing
- Browser Update / Tomos Update

GitHub版には影響させない。

### 3. GitHub版固有の更新

例:

- GitHub Actions
- GitHub版HTML出力処理
- asset copy
- GitHub版search
- Pages deploy処理

Core版には影響させない。

## GitHub版のversion方針

GitHub版サイトが常にTomos最新版へ自動追従する方式は基本としない。

Themeや生成HTMLの変化で既存サイト表示が変わる可能性があるため、サイト側で利用するTomos Publishing Core versionを固定できる構造を基本候補とする。

概念例:

```text
Tomos Core 1.2.0
      |
      +-- PHP Runtime 1.2.x compatible
      +-- GitHub版 1.2.x compatible
```

GitHub版では、

1. 利用Publishing Core versionを明示
2. 更新時にversionを変更
3. 再build
4. 問題がある場合は旧versionへ戻す

という運用を可能にする。

利用者向けの製品versionは従来どおり `Tomos 1.x.x` を基本とし、内部でCore / Runtime compatibilityを管理する。

## バグ修正時の原則

バグの責務がどこに属するかを先に判定する。

例:

- 日本語URLのcanonical二重encode -> Publishing Coreで一度修正
- Tomos Postの認証不具合 -> PHP Runtimeのみ修正
- GitHub Pagesへのasset配置不具合 -> GitHub版のみ修正

共通仕様のバグをRuntimeごとに個別修正しない。

## Publishing Core v1仕様

詳細仕様は `docs/project/publishing-core-v1.md` を参照する。

## 開発開始前に固定する事項

実装開始前に少なくとも次を仕様として固定する。

1. Tomos Publishing Core v1の責務・非責務
2. CoreとRuntime間で受け渡すSite / Page model
3. Theme Contractの両Runtime共通保証範囲
4. Config SchemaとRuntime固有Config Storageの境界
5. Static buildの出力URL規則
6. Search、外部URL、画像、Social PublishingのGitHub版向け扱い
7. Publishing Core versionとRuntime compatibilityの管理方法
8. 共通Publishing Core回帰テストをCore版・GitHub版で共有する方法

この文書の段階では、GitHub版の実装、既存Coreの切り出し、ディレクトリ再構成は行わない。
