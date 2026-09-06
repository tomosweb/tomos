# 更新履歴

## v0.6.7 - 2026-09-06

### Fixed

- v0.6.6で承認後にTomos Writeタブが開かないことがある往路popup regressionを修正しました。
- PR #179で導入した、click user activation中のpopup予約、確認ダイアログ、予約windowのWrite URL遷移の順序へ戻しました。
- v0.6.6で修正したTomos WriteからTomosへ戻る復路receiverは維持しています。

### Security / Compatibility

- 承認、origin/source/session検証、ACK確認、自動公開しない仕様は変更ありません。
- v0.6.6からv0.6.7へ、署名付きTomos Updateで更新できます。`config.php`、`content/`、uploads、サイト固有Theme、運用データはCore Updateの更新対象に含めません。

## v0.6.6 - 2026-09-06

### Fixed

- Tomos WriteからTomosへ戻る際、既存のPublished一覧windowが再利用されると本文受信ACKが返らない問題を修正しました。
- 再利用するTomos windowをTomos Postのupload receiverへ遷移し、receiverの起動確認後に編集済みMarkdownを渡すようにしました。
- v0.6.5で修正したChromeのポップアップ起動問題への対応を維持しています。

### Security / Compatibility

- 承認、origin/source/session検証、ACK確認、自動公開しない仕様は変更ありません。
- 編集途中の内容はブラウザ内に保持され、従来の「Markdownを取得」とMarkdown upload更新も継続して利用できます。
- v0.6.5からv0.6.6へ、署名付きTomos Updateで更新できます。`config.php`、`content/`、uploads、サイト固有Theme、運用データはCore Updateの更新対象に含めません。

## v0.6.5 - 2026-09-06

### Fixed

- 「Tomos Writeで編集」で、Chrome環境により承認後のTomos Write起動がポップアップブロックされる問題を修正しました。
- ポップアップ起動に失敗した場合は、記事本文を送信せず安全に停止するようにしました。

### Compatibility

- v0.6.4で追加したTomos Write編集フローを継続して利用できます。
- 承認、origin/session検証、自動公開しない仕様は変更ありません。
- 従来の「Markdownを取得」とMarkdown upload更新を継続して利用できます。
- Mac Chrome、Mac Safari、iPhone Safariでhandoffを確認しました。

## v0.6.4 - 2026-09-06

### Added

- 公開済み記事の一覧から「Tomos Writeで編集」を利用できるようにしました。
- ブラウザ間のhandoffで編集用MarkdownをTomos Writeへ渡し、「Tomosで更新する」からTomos Postの更新フローへ戻せるようにしました。
- 編集途中の内容をブラウザのlocalStorageへ保持し、再読み込みや再開に対応しました。
- Mac Chrome、Mac Safari、iPhone Safariでテキスト編集、画像追加、Tomosへの返送、更新を確認しました。

### Fixed

- distribution ZIPの内容検査で、`pipefail`と`grep -q`の組み合わせによる誤判定を修正しました。

### Compatibility

- 従来の「Markdownを取得」とMarkdown upload更新を継続して利用できます。
- 既存画像を保持し、新しい画像は既存のTomos Post画像追加フローを利用します。
- Tomos本体へeditorを内蔵せず、記事本文をtomoswords.orgのサーバーへ送信・保存しません。
- v0.6.3からv0.6.4へ、署名付きTomos Updateで更新できます。`config.php`、`content/`、uploads、サイト固有Theme、運用データはCore Updateの更新対象に含めません。

## v0.6.3 - 2026-09-02

### Fixed

- v0.6.1またはv0.6.2からv0.6.3へ、現在の環境に対応した署名付きBrowser Updateを選択できるようにしました。
- 同一バージョンの修復更新を通常更新と分離し、将来の緊急更新を旧クライアントの通常更新経路へ影響させないようにしました。

### Compatibility

- v0.6.1からv0.6.3、およびv0.6.2からv0.6.3へ更新できます。
- `config.php`、`content/`、uploads、サイト固有Theme、運用データはCore Updateの更新対象に含めません。
- Theme Developer Centerの公式サイトproduction公開は、このReleaseには含みません。

## v0.6.2 - 2026-09-01

### Fixed

- v0.6.1以降で追加された`docs/theme/theme-rules.json`が、Distribution ZIPとUpdate ZIPの両方へ確実に含まれるようにし、更新後のruntime整合性を強化しました。
- `allow_theme_scripts`を削除し、CSP生成をfail-closedに整理しました。Google Analytics 4を設定しない場合はscript実行を許可しません。
- Theme rulesの正本をCoreから参照し、canonical SHA-256を比較できるようにしました。
- Reserved URLの現状と実ディレクトリ衝突を回帰fixtureで検証できるようにしました。既存contentとの互換性のため、予約slugの全面禁止は行いません。

### Added

- Theme Specification、ThemeRules、ThemePackagePolicy / ThemeValidator共通rule基盤、Starter Theme source、Theme security rule基盤を追加しました。これらは今後のTheme development foundationです。

### Compatibility

- v0.6.1からv0.6.2へは、通常の署名付きTomos Updateで更新します。`from_version`は`0.6.1`、`version`は`0.6.2`です。
- `config.php`、`content/`、uploads、サイト固有Theme、運用データはCore Updateの更新対象に含めません。
- Theme Developer Centerの公式サイトproduction公開は、このReleaseには含みません。

## v0.6.1 - 2026-08-31

### Fixed

- 日本語などのUTF-8を含む公開URLを一度だけpercent-encodeし、canonical、OGP、sitemap、RSSで同じ正規URLを使うようにしました。
- 404ページではcanonicalと`og:url`を出力しないようにしました。
- mbstringがない環境でもdescriptionのUTF-8文字列を安全に切り詰めます。

### Compatibility

- v0.6.0からv0.6.1へは、通常の署名付きTomos Updateで更新します。`from_version`は`0.6.0`、`version`は`0.6.1`です。
- 既存のURL構造、Markdown、テーマ、設定、content、運用データは変更しません。

## v0.6.0 - 2026-08-31

### Added

- Markdown Compatibility 1.0を追加し、nested list、task list、strikethrough、fenced code language、HTTP(S) autolink、GFM-compatible tableに対応しました。
- SEO Foundation 1.0を追加し、Coreで正規化したtitle、description、canonical、OGP、Twitter Card、sitemap、robotsを出力できるようにしました。
- Themeの`{{{ page.seo_head_html }}}`契約を追加し、同梱6Themeと実運用custom Themeを移行しました。

### Fixed

- bold italicのHTML nestingを修正しました。
- RSS、sitemap、OGP、canonicalで公開URL生成規則を揃えました。
- sitemapの`lastmod`からfilesystem mtime fallbackを除外しました。

### Compatibility

- Tomos Write PreviewとCore公開HTMLのMarkdown意味・構造を整合させました。
- raw HTMLは標準設定で実行・適用しません。
- 既存記事ではnested list、task list、`~~...~~`、bare URL、language fenced codeの表示が変わる可能性があります。重大な既存記事破壊は確認されていません。
- v0.5.3からv0.6.0へは、通常の署名付きTomos Updateで更新します。`from_version`は`0.5.3`、`version`は`0.6.0`です。
- `config.php`、`content/`、サイト固有Theme、運用データはCore Updateの更新対象に含めません。

## v0.5.3 - 2026-08-30

### Added

- Tomos Publisher 0.2.4からの画像付き投稿を非公開stagingで受信し、全画像の受信完了後に既存の投稿処理へ引き渡すフローを追加しました。
- 自動公開に失敗したPublisher画像付き投稿を通常のTomos Post下書きへ変換し、「下書き」から確認・公開・削除できるようにしました。
- Publisher由来の未変更な失敗下書きを、完全な再送時に安全に置き換える復旧処理を追加しました。

### Fixed

- Publisherの画像chunk送信がTomos Coreで完結できず、Markdownだけが内部保存領域に残る問題を修正しました。
- PHP 8.5でdeprecatedとなった`imagedestroy()`の呼び出しを整理し、PHP 7.4以下のresourceだけを明示解放するようにしました。
- PHP 8.5でdeprecatedとなった`curl_close()`をPHP 8.5では実行しないようにしました。

### Compatibility

- PHP compatibility matrixへPHP 8.5を追加し、PHP 7.4 / 8.0 / 8.2 / 8.5をCI対象としました。
- CIへGDを追加し、`E_ALL`でのImageProcessor互換性テストを追加しました。
- v0.5.2からv0.5.3へは、通常の署名付きTomos Updateで更新します。`from_version`は`0.5.2`、`version`は`0.5.3`です。
- `config.php`、`content/`、サイト固有Theme、運用データは更新対象に含めません。

## v0.5.2 - 2026-08-30

### Fixed

- Obsidian Inboxから公開済み記事を同じ保存先・同じファイル名で再送した場合、同一内容は重複原稿として安全に整理できるようにしました。
- 内容が異なる再送は自動上書きせず、既存の競合確認・更新フローへ接続するようにしました。
- 更新承認時は再送Markdownの`date`を反映し、既存記事の初回公開日時を示す`published`を維持します。
- 保存先folderが異なる同名ファイルを別記事として判定するようにしました。

### Compatibility

- v0.5.1からv0.5.2へは、通常の署名付きTomos Updateで更新します。`from_version`は`0.5.1`、`version`は`0.5.2`です。
- v0.4.0からは、v0.5.0、v0.5.1を経由してv0.5.2へ更新します。
- `config.php`、`content/`、サイト固有Theme、運用データは更新対象に含めません。

## v0.4.0 - 2026-08-29

### Added

- Theme Platform v1拡張を完成させ、テーマ設定、ナビゲーション、News、サイト固有資産の責務境界を整理しました。
- Navigation Settings v1に対応し、auto/manual切替、manual順序、label上書き、hidden設定を利用できるようにしました。
- Tomos PostのSite SettingsへNavigation Settings UI v1を追加しました。既存の認証、CSRF、UpdateLock境界を維持したまま、既存のHero、News、Design、Folders等の設定を保持します。
- 同一テーマIDのTheme ZIPを安全に反復更新できるようにしました。same-versionおよびhigher-version更新、active themeの選択維持、directory単位の置換、削除済みファイルのcleanup、失敗時rollbackに対応します。
- downgradeは拒否し、サイト固有の`theme-settings.php`、`theme-assets/`、`content/`、`config.php`をTheme ZIP更新の対象外として保持します。
- `tomos-lab`商用Themeの制作工程と、同一version反復更新を含むPhase 6 / Gate 6検証を追加しました。

### Compatibility

- v0.3.1からv0.4.0へは、通常の署名付きTomos Updateで更新できます。`from_version`は`0.3.1`、`version`は`0.4.0`です。
- v0.3.1の認証導線改善を継承し、サブディレクトリ設置でのTomos Post認証引き継ぎを維持します。
- `config.php`、`content/`、サイト固有Theme、運用データはCore Updateの更新対象に含めません。

### Scope

- 本節はv0.4.0 RC準備時点の変更を整理したものです。正式Release、Browser Update公開、Installer公開、mirror同期、公式サイトdeployは別工程です。
- Issue #90およびIssue #95由来のOPEN PR変更は含みません。

## v0.3.1 - 2026-08-23

### Fixed

- 新規インストール直後などRemember認証Cookieがまだないブラウザで、Tomos Postから「テーマ」「サイト設定」を開くと投稿画面へ戻されることがある問題を修正しました。
- PHP session cookieのPathをTomos Post配下へ明示し、サブディレクトリ設置でも投稿画面で成立した認証状態を設定・テーマ画面へ引き継げるようにしました。

### Compatibility

- v0.3.0の機能仕様、Markdown、テーマ、URL構造は変更しません。
- Phase 6以降の未リリースTheme Platform機能は含みません。

## v0.3.0 - 2026-08-21

### Added

- サイトの既定言語設定に対応しました。
- Markdownのfrontmatterでページごとの `language` を指定できるようにしました。
- `page.language` をTheme contextとして提供します。
- BCP 47形式に沿った言語タグの検証に対応しました。
- SetupとTomos Postのサイト設定から言語を変更できるようにしました。
- 同一サイト内で異なる言語のMarkdownページを共存できるようにしました。

### Changed

- 標準テーマが `<html lang="{{ page.language }}">` を出力します。
- 標準6テーマを1.3.0世代へ更新しました。
- `language` 未指定のページはサイトの既定言語を継承します。

### Compatibility

- 既存サイトの既定言語は `ja` です。
- 既存Markdownの変更やmigrationは必要ありません。
- URL構造は変更しません。
- RSSはサイト単位の言語を維持します。

### Not included

- 自動翻訳・翻訳API
- 翻訳workflow・翻訳関係管理
- 自動hreflang生成

## v0.2.0 - 2026-08-20

### Theme Platform v1

- `theme.json` の `requires_tomos` による最低Tomos versionの宣言に対応しました。
- ThemeSettings、theme-assets、structured Home News API、Virtual Folder Indexを公開契約として整理しました。
- Theme Platformのtemplate contextを整備しました。
- 標準6テーマを1.2.0世代へ整理しました。

### runtime / freshness

- metadata freshnessを改善しました。
- HTML cache freshnessを改善しました。
- dated filenameからの日付fallbackに対応しました。
- Virtual Folderのfreshness関連処理を改善しました。

### safety / responsibility boundary

- `theme-settings.php` にself-guardを導入しました。
- `.htaccess` はdefense in depthとして維持します。
- Tomos Updateはroot `.htaccess`を更新しません。
- ThemeSettingsへsecretsを保存しない責務を明確化しました。

### compatibility

- `requires_tomos` 未指定themeは従来どおり互換扱いです。
- v0.2.0の新APIを必須利用するthemeは最低Tomos versionを指定できます。
- theme versionとTomos core versionは独立して管理します。

## v0.1.0-beta.1 - 2026-08-17

### 概要

- alpha最終版のシステム整合性監査を完了し、最初のbeta版へ移行します。
- 新機能追加より、投稿・設定・索引・cache・Update・Passkeyの整合性とfailure時rollbackの確認を優先しました。
- alpha.19 → beta.1は通常の署名済みUpdate経路を使用します。

### 信頼性・安全性

- PostのMarkdownと管理画像のtransaction境界を揃え、途中失敗時のrollbackを強化しました。
- metadata aliasとHTML cacheで世代不一致を検出し、不整合なderived stateを利用しないようにしました。
- `config.php` の同時更新をlockとcompare-and-swapで保護しました。
- Passkey credentialの利用回数更新を直列化し、`sign_count`等の後退を防止しました。
- Update、Updater self-update、Theme、Post、Configへfailure injection回帰を追加しました。
- derived index/cacheの固定temporary pathを整理しました。

### 改善

- 画像加工が安全側へフォールバックして元画像保存に成功した場合、GDやメモリ判定等の内部事情を利用者向け警告として表示しないようにしました。
- EXIF向き補正不可など利用者に意味のある警告と、画像保存失敗のエラーは維持します。

## v0.1.0-alpha.19 - 2026-08-16

### 概要

- alpha.18で導入した公式オンライン更新経路を開始するための最小リリースです。
- Tomos本体のruntime差分は `VERSION` のみです。
- alpha.18 → alpha.19は、`from_version`を厳密に確認する通常Updateです。
- 手動の署名済みUpdate ZIPも正式な更新経路として維持します。
- alpha.17 → alpha.18で必要だったUpdater finalizeは、alpha.18 → alpha.19では不要です。

## v0.1.0-alpha.18 - 2026-08-16

### 追加

- Tomos Updateから公式catalogを確認するオンライン更新機能を追加しました。
- `UpdatePackageDownloader` と `UpdateReleaseProvider` を追加しました。
- オンライン更新と手動Update ZIPを同じ `UpdateService` へ統合しました。

### 安全性・更新方式

- Update ZIPの `from_version` を現在の `VERSION` と厳密に照合します。
- 更新は1バージョンずつ行い、自動多段更新は行いません。
- alpha.17 → alpha.18だけは、旧Updaterから新Updaterへ移行する一回限りのlegacy bridgeを使用します。
- `update/index.php` と `core/UpdateService.php` はUpdater bundleとしてatomicにfinalizeします。

## v0.1.0-alpha.17 - 2026-08-14

### 追加

- 1ファイルインストーラーを追加しました。`install.php` をサーバーへ設置し、ブラウザからTomos本体を導入できます。
- インストーラーが配布先から対象versionのmanifest、署名、ZIPを取得し、検証後にTomosを配置する仕組みを追加しました。

### 改善

- 初回セットアップ時に、アクセス中のURLから `site_url` と `base_path` を自動判定するようにしました。通常はURLや設置パスを手入力する必要がありません。
- Installer用Release候補を一定の手順で生成・検証できるビルド処理を追加しました。

### 安全性・信頼性

- manifest署名、SHA-256、ZIP内容を配置前に検証し、検証に失敗した配布物を展開しません。
- 検証済みファイルをhidden stagingへ展開してから配置し、途中失敗時は既存状態を保つか復元するようにしました。
- Installer公開用のversioned assetとlatest pointerを分離し、検証済みversionだけを最新として案内できる配布構成にしました。
- 既存の通常配布ZIP、Tomos Update、Tomos Postの利用方法は変更しません。

## v0.1.0-alpha.16 - 2026-08-13

### 追加

- Tomos Postに下書き管理を追加しました。Inboxおよびcontent内の下書きを一覧から確認し、プレビュー、Markdown取得、公開、削除ができます。
- 公開済み投稿管理を追加しました。公開済み記事を一覧表示し、検索、年別絞り込み、Markdown取得、公開ページ確認、取り下げができます。

### 改善

- Tomos Postの管理画面を「投稿」「下書き」「公開済み」「設定」の4区分へ整理しました。
- 数千件規模の記事でも公開済み投稿を探しやすいよう、ページングと検索を追加しました。
- 従来の編集用Markdown検索、手動取り下げ、ゴミ箱を高度な操作へ整理しました。
- 標準テーマの記事ページでdescriptionが本文上部へ重複表示されないようにしました。

### 互換性・安全性

- Markdownを正本とする既存仕様は変更しません。
- Tomos Postに本文編集機能やDBは追加していません。
- 公開済み投稿の取り下げは既存の取り下げ処理を利用し、Markdownは `trash/content/` へ移動します。
- 他の記事から参照されている画像は削除しない既存仕様を維持します。
- 既存の編集用Markdown取得等の内部処理は維持します。

## v0.1.0-alpha.15 - 2026-08-12

### 追加

- 外部MarkdownクライアントからTomos Inboxへ送信できるHTTPS APIを追加しました。
- Tomos Postから外部投稿用トークンを発行できるようにしました。
- `draft` の指定に応じて、Inbox保持または自動公開するフローを追加しました。

### 改善

- Inboxから手動公開するとき、`draft: true` を公開状態へ変更するようにしました。
- 初回公開時に `date` が空欄または未指定の場合、サイトのタイムゾーンに基づく公開日を補完するようにしました。
- 通常投稿、Inbox、外部投稿が共通の投稿処理を利用するよう内部構造を整理しました。

### 互換性

- 既存記事の `date` と `published` は変更しません。
- `draft: true` の原稿を自動公開しません。
- Obsidian連携はTomos本体の受信機能を利用する別配布のプラグインです。

## v0.1.0-alpha.14 - 2026-08-09

### 改善

- 同じ日に複数の記事を投稿した場合、実際の投稿順に新しい記事が上へ表示されるようにしました。
- 初回公開時刻を `published` として記録し、同一公開日内の記事順を安定して判定できるようにしました。
- 記事一覧の並び順を、公開日、初回公開時刻、パスによる共通ルールへ統一しました。
- folder一覧、トップページ、テーマの記事一覧、タグ一覧、RSSで同じ記事順を使用するようにしました。

### 互換性・信頼性

- 既存記事には `published` を一括追加せず、これまでの記事をそのまま表示できる後方互換を維持しました。
- 公開済み記事を編集しても、初回公開時刻は変更せず、同日内の記事順が入れ替わらないようにしました。
- 不正または空の `published` は未設定相当として扱い、記事一覧全体の生成を停止しないようにしました。
- `filemtime` および `updated` は投稿順の判定には使用しません。

## v0.1.0-alpha.13.1 - 2026-08-08

v0.1.0-alpha.13の修正版です。

### 修正

- Tomos Postのセキュリティ画面とパスキー管理画面で、未認証時に管理用合言葉で認証した後も同じ画面に留まり、そのままパスキーの追加・管理へ進めるようにしました。
- 通常のセキュリティUIからRP IDの表示を削除しました。
- パスキー環境の判定時にWebAuthn runtimeを必要に応じて読み込み、個別画面でruntimeが未読込の場合でも正しく判定できるようにしました。

## v0.1.0-alpha.13 - 2026-08-08

### 追加

- Tomos PostにWebAuthnパスキー認証を追加しました。管理用合言葉認証は従来どおり利用できます。
- 複数のパスキーを登録・名称変更・個別削除できる「セキュリティ」画面を追加しました。
- 登録済みパスキーで本人確認し、管理用合言葉を再設定できるようにしました。
- パスキー未登録かつ管理用合言葉を忘れた場合に、サーバー所有確認から最初のパスキーを登録して復旧できる経路を追加しました。

### 改善

- Tomos Postのナビゲーションに「セキュリティ」を追加し、パスキー認証と合言葉復旧の入口を整理しました。
- パスキー機能はPHP 8.0以上、OpenSSL、mbstring、HTTPS等の利用条件を満たす場合だけ有効化し、条件を満たさない環境では管理用合言葉認証へフォールバックします。
- パスキーcredentialを `storage/security/passkeys/` に永続保存し、Tomos Updateの更新対象から分離しました。

### 配布・互換性

- `lbuchs/WebAuthn` v2.2.0をWebAuthn runtimeとして採用しました。
- 利用者がComposerを実行しなくても利用できるよう、WebAuthn runtimeをTomosの通常配布ZIPとUpdate ZIPへ同梱する構成にしました。
- PHP 7.4では既存Tomos Postを維持し、PHP 8.0 / 8.2ではパスキー機能を含む互換CIを追加しました。
- Update後の必須ファイル検査へパスキー実装とWebAuthn runtimeを追加しました。

### セキュリティ

- WebAuthn challengeを短時間・一回限りでセッション管理し、RP IDとOriginを厳密に検証します。
- パスキー登録、管理、合言葉再設定、サーバー復旧の状態変更操作をCSRF保護します。
- 管理用合言葉の再設定時に、既存の記憶認証トークンをすべて失効するようにしました。
- サーバー所有確認用の復旧ファイルはランダム名とし、照合成功後に削除できない場合は復旧を継続しません。

## v0.1.0-alpha.12 - 2026-08-07

### 追加

- Tomos Postのテーマ管理画面から、外部配布テーマのZIPを新しいテーマとして追加できるようにしました。
- ZIPの検査結果を確認してから確定追加する二段階フローを追加しました。

### 改善

- Finderで作成したZIPに含まれる `__MACOSX`、`.DS_Store`、`._*` を、テーマ内容へ展開せず安全に無視するようにしました。
- テーマとして動作するための必須ファイルを5件に整理し、`preview.png`、`README.md`、`LICENSE` は推奨ファイルとして扱うようにしました。
- テーマ追加後は自動的に有効化せず、既存のテーマ切り替え確認画面から選択する流れを維持しました。

### セキュリティ

- テーマZIPを展開前に検査し、危険なパス、PHP、JavaScript、シンボリックリンク、特殊ファイル、未許可ファイルを拒否します。
- 検査済みファイルだけを一時領域へ個別展開し、hidden stagingで再検証してから新規テーマとして配置します。
- 既存テーマと同じテーマIDは上書きせず拒否し、失敗時は既存テーマを変更しません。

## v0.1.0-alpha.11 - 2026-08-06

### 追加

- Tomos Postの管理用合言葉を、合言葉そのものを保存せず、このブラウザで30日間省略できる記憶認証を追加しました。
- PHPセッション、記憶Cookie、対応するサーバー側トークンを削除する「このブラウザの認証を解除」を追加しました。
- 投稿操作ごとの `submission_id` とサーバー側完了記録を使い、同一投稿の二重保存を防止するようにしました。

### 改善

- 同一IPの全投稿に適用していた30秒間隔制限を廃止し、異なる記事を続けて投稿できるようにしました。
- 完了した投稿の `submission_id` は24時間再利用を拒否し、保持期間の終了後に完了記録とロックファイルを整理するようにしました。
- 合言葉の認証状態を、Tomos Post内の投稿、サイト設定、テーマ設定、Update関連画面などで共有するようにしました。
- 合言葉失敗の制限と投稿の二重実行防止を、それぞれ `PostRateLimiter` と `PostSubmissionGuard` に分離しました。
- 投稿ボタンを押した後は処理完了までボタンを無効化し、投稿中であることを表示するようにしました。

### セキュリティ

- 記憶認証ではブラウザに生のランダムトークンだけを保存し、サーバーの `cache/security/post-auth/` にはSHA-256ハッシュと作成・期限時刻だけを保存します。
- 合言葉の再発行時に、既存の記憶認証トークンをすべて無効化するようにしました。
- 10分間に合言葉を5回誤ると15分間停止するIP単位の制限と、合言葉再発行の連続実行制限は維持しています。

## v0.1.0-alpha.10 - 2026-08-02

### 追加

- 署名済みUpdate ZIPで受け取った `update/index.php` を、通常Update完了後にTomos Postの専用画面から明示的に反映できるようにしました。

### 改善

- 仮想フォルダーの記事一覧でも、テーマの `list.html` を使用するようにしました。
- テーマ選択画面にテーマの表示名と検証警告を表示するようにしました。
- テーマで必要な画像が不足している場合は、標準テーマの画像を使用して表示を継続するようにしました。
- 同梱テーマの表示名を統一しました。

### 修正

- Tomos Postの投稿・更新完了画面で、ダウンロード操作の表示が崩れる問題を修正しました。

### 信頼性

- 通常配布とUpdate ZIPの生成時に、必要なファイルが揃っていることを共通の必須ファイル一覧で検査するようにしました。
- Update適用後に必須ファイルの欠落を検出した場合は、更新前の状態へ自動復元するようにしました。
- `update/index.php` の自己更新時に、署名対象の待機ファイルとメタ情報、専用バックアップ、置換後検証、失敗時の復元、成功記録を使用するようにしました。

## v0.1.0-alpha.9 - 2026-07-29

### 修正

- 既存原稿をTomos Writeで開いた際に、相対指定された既存画像を正しくプレビューできない問題を修正しました。

## v0.1.0-alpha.8 - 2026-07-28

### 追加

- Tomos Postの記事管理から、公開記事、下書き、固定ページを検索できるようになりました。
- 既存原稿をTomos Writeで編集するためのMarkdownダウンロードに対応しました。
- 編集済みMarkdownを元の原稿へ再投稿できるようになりました。
- 公開記事の更新、下書き保存、下書きからの公開に対応しました。
- 元原稿のSHA-256を使った競合確認に対応しました。

### 改善

- Tomos Writeで、既存原稿の相対画像をプレビューできるようになりました。
- 保存先を変更した場合は、元原稿を残して新しい記事として投稿するようにしました。
- 下書き保存後の画面には、公開URLと公開ページ確認ボタンを表示しないようにしました。

## v0.1.0-alpha.7 - 2026-07-26

### 追加

- Tomos Postに「サイト設定」画面を追加しました。
- サイト名、サイト説明、タイムゾーンをブラウザから変更できるようになりました。
- RSSの有効・無効と対象パスを変更できるようになりました。
- Sitemapの有効・無効を変更できるようになりました。
- サイト設定画面からGA4設定とテーマ変更へ移動できるようになりました。

### 改善

- RSSを無効にした場合、標準テーマのRSSリンクとRSS discovery linkを表示しないようにしました。
- サイト設定の保存時に、対象外の `config.php` 設定を維持するようにしました。

## v0.1.0-alpha.6 - 2026-07-26

### 変更

- Tomos Updateを今後も安定して提供するため、署名確認に使用する信頼点を更新しました。
- 既存環境では、alpha.6への更新のみ手動操作が必要です。
- alpha.6への移行後は、新しい信頼点を使ってTomos Updateを利用できます。

### ドキュメント

- 既存利用者向けの手動移行手順を追加しました。
- 新しい公開鍵のフィンガープリント確認方法を追加しました。

## v0.1.0-alpha.5 - 2026-07-24

### 修正

- 初期サンプルの「このサイトについて」リンクが、存在する `content/about.md` ではなく `/about/` を指して404になる問題を修正。

### ドキュメント

- 単体ページとフォルダページで、URL末尾のスラッシュの有無に意味があることを記事作成ガイドへ追記。

## v0.1.0-alpha.4 - 2026-07-23

### 追加

- 管理画面から署名済み更新ZIPをアップロードし、Tomos本体を更新する「Tomos Update」を追加。
- 更新前後のバージョン、更新対象ファイル、標準テーマ変更の有無を実行前に確認できる画面を追加。
- 更新対象ファイルだけを保存するバックアップ、更新結果ログ、更新失敗時の自動復元を追加。

### セキュリティ

- manifest署名、各ファイルのSHA-256、製品名、バージョン条件、許可パス、書き込み権限を更新前に検証。
- Zip Slip、シンボリックリンク、重複パス、過大なファイル数・展開容量、manifestにないファイルを拒否。
- `config.php`、記事、画像、キャッシュ、運用データ、独自テーマを更新対象から除外。
- 更新中の多重実行をロックし、Tomos Postの変更処理も更新完了まで停止。

### ドキュメント

- Tomos Updateの利用方法と、`v0.1.0-alpha.3` からの一度限りの手動導入手順を追加。

## v0.1.0-alpha.3 - 2026-07-22

### 追加

- Tomos Postから、公開中の `index.md` と `about.md` の最新版を管理用合言葉で認証してダウンロードできる機能を追加。
- 既存のMarkdown・画像投稿機能を使い、FTP/SFTPを使わずにトップページとAboutページを継続更新できる導線を追加。

### 変更

- Tomos Postの上部操作を「投稿」「記事管理」「サイト設定」に整理。
- `index.md` と `about.md` は保存先指定にかかわらず `content/` 直下へ保存し、別名投稿、取り下げ、完全削除の対象外に変更。

### 修正

- 画像を6枚以上選択した場合に、最大5枚であることを明示するメッセージを表示。

## v0.1.0-alpha.2 - 2026-07-22

### 追加

- フォルダー直下の公開記事一覧を1ページ30件に分け、前後・ページ番号で移動できるページング機能をcoreに追加。
- 全同梱テーマでフォルダー記事一覧とページングの表示に対応。
- 大量記事時のHTML量を抑えるため、ナビゲーション内の子ページを1フォルダー30件までに制限し、ページング一覧への「すべて見る」導線を追加。

### 修正

- 数字だけの第1階層フォルダー名があると、PHP 8でプライマリナビゲーション生成時に型エラーになる不具合を修正。
- サブディレクトリ設置時、Tomos Postの投稿完了画面に表示する公開URLへ設置パスが二重に付加される不具合を修正。
- 日本語の濁点表現だけがNFC/NFD形式で異なる同名ファイルをTomos Postが別ファイルとして扱い、更新確認なしで二重公開する不具合を修正。
- 公開サイト用・試作用テーマと草稿ドキュメントが配布Zipへ混入しないよう、配布ビルドの除外確認を追加。

## v0.1.0-alpha.1 - 2026-07-20

### 修正

- Tomos Postで既存ページの競合を検出した直後でも、更新または別名投稿の確認操作を続行できるように修正。合言葉の連続失敗による一時停止は維持。

### ドキュメント

- 既存設置環境のデータを保持する、バージョン別の差分更新ガイドを追加。

## v0.1.0-alpha

初期アルファ版のリリース候補。

### 追加

- Google Analytics 4の測定IDを任意設定できる機能
- GA4未設定時はGoogleタグを出力しない公開ページ共通処理
- 全同梱テーマでのGA4共通出力

- Tomos Postで画像を1枚ずつ順番にアップロードするセッション処理（再試行、キャンセル、環境に応じた上限、全画像の完了後に公開）
- サーバーの現在のリクエスト上限を超える画像に対応する、チャンクサイズ自動調整アップロード
- Markdown段落内の単一改行を保持
- 画像の拡張子と実際のデータ形式が一致しない場合の明確な検証メッセージ
- JPEGのEXIF Orientation 1〜8を補正（IFD0フォールバック、反転を伴う回転方向の修正、診断情報を含む）
- 既存設置環境を安全に更新する案内を含む、Phase 9の配布物・ドキュメント監査
- Phase 9の実環境統合・回帰確認を完了し、リリース可能との最終判断を実施
- フォルダーの`index.md`がなくても公開中の子記事がある場合に、仮想フォルダーの索引ページを生成
- tomos-minimalのページテンプレートにフォルダー内の記事一覧を追加

- Markdownファイルをもとにしたページ表示
- フロントマター対応
- 下書きを公開対象から除外
- メタデータ索引の生成
- ナビゲーションとパンくずリスト
- フォルダーとページの一覧
- Wikiリンク
- 画像の埋め込み
- タグ
- 検索
- RSSとサイトマップ
- セットアップ画面
- セットアップ保護
- テーマ検証
- faviconとOGP対応
- インストール手順書
- 配布用buildフォルダーの生成

### 既知の制限

- HTMLキャッシュは未実装。
- Web上でのMarkdown編集は未実装。
- 画像アップロードは未実装。
- ログイン画面と管理画面は未実装。
- 配布用ZIPの生成は未実装。

### 修正

- テーマ表示後も仮想フォルダーのページ種別とcontent基準のフォルダーパスを保持し、フォルダー内の記事一覧が表示されるように修正
- フォルダー内の記事一覧を直下の記事だけに限定
- tomos-90sのタグ一覧から意図しない箇条書き記号を除去し、スタイルシートURLを更新
