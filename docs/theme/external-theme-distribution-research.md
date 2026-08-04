# 外部配布テーマの現行構造調査

## 調査対象

【事実】この文書は、`tomosweb/tomos-dev` の `main` を同期した次のコミットを対象にしたコード調査である。

```text
95a9050a68313992a70477379bcaa7eca5e710e7
```

主に次のファイルとディレクトリを確認した。

- `themes/*/`
- `core/ThemeValidator.php`
- `core/ThemeRepository.php`
- `core/ThemeConfigWriter.php`
- `core/TemplateRenderer.php`
- `core/App.php`
- `setup/index.php`
- `post/theme/index.php`
- `post/theme/confirm/index.php`
- `config.sample.php`
- `tools/build-distribution.sh`
- `tools/required-distribution-files.txt`
- `tools/build-update-package.php`
- `core/UpdateService.php`
- `core/InstalledIntegrityVerifier.php`
- `core/required-installed-files.txt`
- `docs/theme/theme-authoring.md`
- `docs/theme/theme-validation.md`
- `docs/features/themes.md`
- `docs/project/distribution.md`
- `docs/install/tomos-update.md`
- `LICENSE`
- `NOTICE`
- `TRADEMARKS.md`

調査ではテーマ、PHP、ビルドスクリプト、通常配布ZIP、Update ZIP、公式サイトを変更していない。テーマZIPの追加やブラウザ表示など、設置状態を変更する実動作確認も行っていない。

## 現行テーマ構造

### 保存場所とテーマID

【事実】テーマはTomos設置ルートの `themes/<テーマID>/` に置く。既定の保存場所は `config.sample.php` の `paths.theme_dir` で `__DIR__ . '/themes'` とされ、使用中のテーマIDは `config.php` の `theme.name` に保持される。

テーマIDは次の3か所で一致する必要がある。

1. `themes/` 直下のディレクトリ名
2. `theme.json` の `name`
3. 選択後に `config.php` の `theme.name` に保存される値

`ThemeValidator` と `ThemeConfigWriter` が受け付けるテーマIDは、正規表現 `\A[A-Za-z0-9_-]+\z` に一致する英数字、ハイフン、アンダースコアだけである。大文字もコード上は許可されるが、現行テーマはすべて小文字の `tomos-...` 形式である。IDの長さ上限や `tomos-` 接頭辞の必須規則はない。

### 同梱テーマ一覧

【事実】`themes/` に存在するテーマは6件で、すべて現行 `ThemeValidator` で valid、エラー0、警告0だった。

| テーマID | 表示名 | バージョン | 主な差分 |
| --- | --- | --- | --- |
| `tomos-minimal` | Tomos Minimal | `0.1.1` | 標準の最小テーマ。サイドナビゲーションを持つ |
| `tomos-journal` | Tomos Journal | `0.1.1` | 日記・エッセイ向けの配色と組版 |
| `tomos-dark` | Tomos Dark | `0.1.1` | ダーク配色 |
| `tomos-90s` | Tomos 90s | `0.1.1` | 90年代テキストサイト風の罫線、リンク、等幅書体 |
| `tomos-note` | Tomos Note | `0.1.1` | 研究・読書・技術メモ向け。セクションナビゲーションを使う |
| `tomos-blog` | Tomos Blog | `1.1.1` | 個人ブログ向け。専用トップ、プロフィール画像、ヘッダー画像、カード型一覧を持つ |

`docs/features/themes.md` と `docs/theme/theme-authoring.md` の「同梱テーマ」記述は3件だけであり、現行ディレクトリの6件とは一致していない。この文書ではコードと実在ディレクトリを現行事実として扱う。

### 表示名とメタデータ

【事実】表示名は各テーマ直下の `theme.json` にある `display_name` が保持する。Tomos Postとsetupは `ThemeRepository` を通して次の情報を読む。

- `name`: テーマID。ディレクトリ名との一致が必須
- `display_name`: 表示名。空はエラー
- `version`: テーマバージョン。空はエラー
- `description`: 説明。空でも検証エラーにはならない
- `author`: 作者。空でも検証エラーにはならない
- `supports`: 任意のオブジェクト。機能の目安であり、機能を有効化する処理には使われない

上記以外の `theme.json` 項目は、現行 `ThemeValidator` の返却値に取り込まれず、Tomos Postのテーマ一覧にも表示されない。

### 必須ファイル

【事実】現行コード上の必須ファイルは次の5つである。

```text
<テーマID>/
  theme.json
  templates/
    layout.html
    page.html
    list.html
  assets/
    style.css
```

- `layout.html`: HTML文書全体を構成し、レンダリング済みの `page.body` を受け取る
- `page.html`: 通常の記事、固定ページ、トップページの既定テンプレート、タグ、検索、404の表示に使われる
- `list.html`: `index.md` がない仮想フォルダー一覧に使われる
- `style.css`: `theme.asset_url` を基準にテンプレートから参照するCSS入口

### 任意・推奨ファイル

【事実】次は任意または推奨である。

- `templates/home.html`: 存在する場合だけ `/` で使用。なければ `page.html` にフォールバック
- `assets/favicon.svg` または `assets/favicon.png`: ない場合は警告。`tomos-minimal` の同名アセットへフォールバック可能
- `assets/apple-touch-icon.png`: ない場合は警告。`tomos-minimal` へフォールバック可能
- `assets/ogp.png`: ない場合は警告。`tomos-minimal` へフォールバック可能
- `README.md`: コードは検査・表示しない
- `LICENSE`: コードは検査・表示しない
- `preview.png`: オーサリング文書では推奨されるが、コードは検査・表示しない
- その他の画像、CSS、JavaScript等: 必須ファイルではなく、テーマ固有アセットとして置ける

`templates/not-found.html` は認識されない。404は `page.html` と `layout.html` で表示される。

### CSS、画像、PHP、テンプレート

【事実】テーマの責務はHTML/CSS中心の表示層である。

- CSSは通常 `assets/style.css` に置き、テンプレートでは `{{ theme.asset_url }}/style.css` として参照する。
- テーマ画像も `assets/` に置き、`{{ theme.asset_url }}/画像名` として参照できる。
- favicon、apple touch icon、OGPはcoreがURLを生成し、`theme.favicon_url`、`theme.apple_touch_icon_url`、`site.ogp_url` として渡す。
- 通常の `{{ variable }}` はHTMLエスケープされる。
- 三重波括弧のHTML出力は、coreの許可リストにある変数だけが出力される。
- `.php`、`.phtml`、`.phar`、`.php5`、`.php7` はテーマ内のどの階層でもエラーになる。
- HTML内のPHPタグ、`javascript:`、`data:text/html` はエラーになる。
- 一部の外部script記述と `onerror`、`onload`、`onclick` は警告になる。
- SVG内の `script` とHTTP(S)外部参照はエラーになる。

【事実】現行コードには、アセット全体の拡張子許可リスト、個別ファイルサイズ上限、テーマ全体の容量上限、ファイル数上限はない。JavaScriptファイル自体を一律禁止する検査もない。これらは「許可が明示されている」という意味ではなく、現行 `ThemeValidator` が検査していないという事実である。

### テーマごとの差分

【事実】6テーマの共通部分は `theme.json`、3つの必須テンプレート、`assets/style.css`、favicon、apple touch icon、OGPである。主な構造差は次のとおり。

- `tomos-minimal`、`tomos-journal`、`tomos-dark` は、サイドナビゲーションとモバイルメニューを持つ近いテンプレート構成で、主な差は配色・余白・組版である。
- `tomos-90s` は `nav.primary_links` を用いるヘッダー構成で、テキストサイト風の見た目に大きく変えている。
- `tomos-note` は `nav.sections` を使い、ノートのセクション導線をヘッダーへ出す。
- `tomos-blog` だけが `templates/home.html` を持ち、`header.png` と `profile.png` を直接参照する。トップに最新12件を出し、記事一覧をカード表示する。
- `tomos-blog` にはテーマ直下の `LICENSE` がある。他のテーマは本体ルートのライセンス・NOTICEに依存している。
- すべてのテーマが記事一覧ページング用のCSSを持つ。

## テーマ追加処理

### 結論

【事実】現行Tomos Postには、テーマZIPのアップロード、展開、設置、上書き、削除を行う処理が存在しない。`post/theme/` は、すでに `themes/` 配下にあるディレクトリを列挙・検証し、使用テーマを切り替える画面である。画面自体にも「テーマの追加や編集はこの画面ではできません」と表示される。

したがって、調査項目にある「ZIPアップロードから設置まで」の現行フローは存在しない。現行手順は次のとおりである。

1. 利用者がTomos外部でZIPを展開する。
2. FTPまたはサーバーのファイルマネージャーで `<テーマID>/` を `themes/` 直下へ配置する。
3. `/post/theme/` を開く。
4. `ThemeRepository` が `themes/` 直下の非hiddenディレクトリを列挙する。
5. `ThemeValidator` が各テーマを検証する。
6. validのテーマだけを選択し、確認画面へ進む。
7. 適用時に再検証し、`config.php` の `theme.name` だけを更新する。
8. HTMLキャッシュを削除する。削除に失敗した場合はテーマ変更を成功扱いとしつつ警告する。

### ZIP内に期待する階層

【事実】テーマZIPを読むコードがないため、ZIP内のルート階層は現行コードでは定義されていない。Tomosが実際に期待する設置後の階層は `themes/<テーマID>/theme.json` であり、`themes/<テーマID>/<テーマID>/theme.json` の二重階層では認識されない。

### テーマIDの決定

【事実】ZIP名や `theme.json` だけから新しいIDを生成する処理はない。設置後の `themes/` 直下ディレクトリ名がIDとなり、`theme.json.name` はそのIDと完全一致する必要がある。

### 必須ファイル、ファイル名、パスの検査

【事実】設置済みディレクトリに対して、前節の5必須ファイル、テーマID、`theme.json`、PHP系拡張子、HTMLとSVGの一部危険記述を検査する。テーマディレクトリが `themes/` の実パス配下にあることも確認する。

一方、テーマZIPを対象とする次の検査は存在しない。

- Zip Slip対策
- ZIP内の絶対パス・`..`・バックスラッシュ・制御文字の検査
- 重複ZIPエントリの検査
- シンボリックリンクの拒否
- 展開前後の容量・ファイル数制限
- 展開先の排他制御

Tomos Updateには同種の検査があるが、これは署名済み本体Update ZIP専用であり、外部テーマZIPの追加には利用できない。

### 上書き、重複、削除、退避

【事実】Tomos Postにはテーマファイルの上書き、重複解決、削除、退避、復元処理がない。FTP等で同じテーマIDへ転送した場合の挙動は、利用した転送手段とサーバーのファイル操作に依存する。

異なるディレクトリ名のテーマは別テーマとして列挙される。同じIDの複数テーマを同時に保持する仕組みはない。`theme.json.version` を比較して上書きを許可・拒否する処理もない。

【事実】使用中テーマのディレクトリが手動削除されるなどして無効になった場合、公開画面の `TemplateRenderer` は validな `tomos-minimal` があればそれへフォールバックする。ただし、`config.php` の `theme.name` を自動修復する処理ではない。

### エラー時

【事実】設置済みテーマがinvalidの場合はテーマ一覧にエラーを表示し、ラジオボタンを出さない。テーマ適用時にもIDとvalid状態を再確認する。`config.php` を書けない場合は切替を失敗させる。テーマ切替前のテーマディレクトリや `config.php` の自動バックアップは作らない。

### 対応可能なZIPサイズ

【事実】テーマZIPアップロードがないため、対応ZIPサイズは定義されていない。`UpdateService::MAX_ZIP_BYTES` の50 MiBは本体Update ZIP専用であり、外部テーマZIPの上限として流用できる事実はない。

### プレビュー表示

【事実】Tomos Postとsetupは、表示名、バージョン、説明、作者、検証結果を文字で表示するが、テーマのスクリーンショットや実サイトのプレビューは表示しない。`preview.png` はオーサリング文書に記載があるだけで、ファイル名、画像形式、寸法、表示場所はコード化されていない。

## 通常配布・Updateとの関係

### 通常配布ZIP

【事実】`tools/build-distribution.sh` は `copy_item "themes"` により `themes/` 全体を再帰コピーする。現行の除外は、汎用的な「外部テーマ」判定ではなく、次の2ディレクトリ名だけである。

- `tomos-creator`
- `tomos-radical-poster`

したがって、新しいテーマを `themes/<新テーマID>/` に追加すると、除外規則を追加しない限り通常配布フォルダーと通常配布ZIPへ自動的に入る。

### `tools/required-distribution-files.txt`

【事実】`tools/required-distribution-files.txt` は、ビルド後に存在しなければエラーとする必須ファイル一覧である。個別テーマは1件も列挙されていない。この一覧にないファイルも `copy_item` の対象なら配布物へ入るため、「一覧にテーマを書かなければ同梱されない」という動作ではない。

同ファイルは外部テーマ混入を検出する拒否リストでもない。通常配布への収録可否を決めているのは、実質的に `build-distribution.sh` のコピー元と除外規則である。

### Update ZIP

【事実】Update ZIPは通常配布ZIPとは別形式で、`manifest.json`、`manifest.sig`、`files/<相対パス>` を持つ署名済みZIPである。更新対象はビルド時に `--file` で明示したファイルだけであり、ディレクトリ全体の同期はしない。

テーマの更新対象として許可されるIDは、`tools/build-update-package.php` と `core/UpdateService.php` の双方で次の6件に固定されている。

- `tomos-90s`
- `tomos-blog`
- `tomos-dark`
- `tomos-journal`
- `tomos-minimal`
- `tomos-note`

上記以外の `themes/<外部テーマID>/...` はUpdate ZIPの許可パスではないため、Update ZIPを作成する側でも適用する側でも拒否される。

【事実】Update適用はmanifestにある個別ファイルだけをバックアップして置換する。manifestにないファイルやディレクトリを削除・同期する処理はない。このため、既存6テーマと重複しないIDの外部テーマは、現行Updateによって上書きも削除もされない。

ただし、外部配布物が既存6テーマと同じIDを使うと、そのテーマ配下の同名ファイルは将来のTomos Update対象になり得る。外部テーマは既存同梱テーマと重複しないIDにする必要がある。

### リポジトリ内で開発し、通常配布へ含めない方法

【事実】現行コードには、新しい外部テーマを `themes/` 配下で開発しながら、テーマの属性や `theme.json` の値によって通常配布から自動除外する仕組みはない。

現行ビルドを変更せずに配布対象外となるのは、次のいずれかである。

- `build-distribution.sh` がコピーしない場所で開発する。ただし、そのままではTomosのテーマとして認識・切替できない。
- 既存の固定除外名 `tomos-creator` または `tomos-radical-poster` を使う。新規テーマの一般的な運用方法にはならない。

`themes/<新テーマID>/` で通常どおり動作確認し、かつ通常配布へ含めないには、少なくともそのIDをビルド時に除外し、完成ZIPにも存在しないことを検査する変更が必要になる。今回はその変更を実装していない。

## 外部テーマ配布でそのまま利用できる部分

【事実】外部テーマを利用者が手動展開・配置する運用であれば、次の現行機能をそのまま利用できる。

- `themes/<テーマID>/` という設置場所
- `theme.json` のID、表示名、バージョン、説明、作者、supports
- 5つの必須ファイル
- HTML/CSSとテーマローカル画像による表示
- `ThemeValidator` による設置後検査
- setupとTomos Postでのテーマ列挙
- invalidテーマの選択防止
- 確認画面を通したテーマ切替
- `config.php` の `theme.name` だけを変更する処理
- 切替後のHTMLキャッシュ削除
- 無効な使用中テーマから `tomos-minimal` への表示時フォールバック
- coreが生成する記事、固定ページ、一覧、タグ、検索、RSS、Sitemap、画像URL
- 外部テーマIDを対象にしないTomos Updateの許可パス制限

【判断】「公式サイトからテーマ単位のZIPをダウンロードし、利用者が展開してFTP等で配置する」方式なら、テーマの実行・検証・切替は現行機能だけで可能である。

## 外部配布に不足している部分

【事実】次は現行コードでは扱えない、または規則が確定していない。

- Tomos PostからのテーマZIPアップロードと展開
- ZIPルート階層の検査
- テーマZIPのファイル数・圧縮前後容量上限
- ZIP内パス、重複、シンボリックリンクの検査
- 既存テーマとの重複時の扱い
- 上書き前の退避と失敗時の復元
- Tomos Postからのテーマ削除と削除前確認
- 使用中テーマの削除防止
- テーマの画像プレビュー
- テーマZIPファイル名の規則
- 対応Tomosバージョンを保持・判定するメタデータ
- ライセンスを保持・表示・検査するメタデータ
- 外部テーマであることを示す属性
- `themes/` 配下の新規外部テーマを通常配布から一般的に除外する規則
- 外部テーマZIPを作る専用のパッケージング・検証処理

`theme.json.supports` は対応機能の目安を保持できるが、値のキーや真偽を検証せず、Tomosバージョン互換性の判定にも使わない。

## 最小限必要な変更候補

この節だけは【候補】であり、実装済みの事実ではない。将来のテーマ管理、テーマストア、自動更新は対象にしない。

### 手動配置を前提に外部配布する場合

【候補】Tomos本体のPHPを変更せずに始める最小範囲は次のとおり。

1. 外部テーマZIPのファイル名、単一ルートディレクトリ、必須同梱物を配布仕様として確定する。
2. テーマごとに `README.md` と `LICENSE` を同梱し、対応TomosバージョンをREADMEに明記する。
3. 外部テーマを `themes/` 配下で開発する場合は、通常配布ビルドから対象IDを除外し、フォルダーとZIPの双方で不在を検査する。
4. テーマ単位ZIPについて、展開後に現行 `ThemeValidator` を通ることを公開前に確認する。
5. 公式サイトで配布するZIPとプレビュー画像について、Tomos本体外の公開手順を定める。

この範囲では、利用者による展開とFTP等での配置が必要なままである。

### Tomos PostからZIP追加を必須にする場合

【候補】現行機能だけでは実現できず、ZIP受領、検査、安全な展開、重複時の扱い、失敗時の復元、削除、上限値の決定が少なくとも必要になる。これはPHP変更を伴う別工程であり、本調査では詳細設計しない。

## 外部テーマZIP仕様案

以下は、現行コードで強制できる事実と、配布時に別途確定が必要な候補を分けた暫定整理である。

| 項目 | 現行コードで扱える事実 | 仕様候補・未確定点 |
| --- | --- | --- |
| ZIPファイル名 | 読み取る処理がなく、規則なし | 【候補】`tomos-theme-<テーマID>-<テーマバージョン>.zip`。コードでは検証されない |
| ZIPルート構造 | ZIP自体は扱わない。設置後は `themes/<テーマID>/theme.json` が必要 | 【候補】ZIP直下に単一の `<テーマID>/` を置く。利用者はそのフォルダーを `themes/` へ配置する |
| テーマID | `themes/` 直下名と `theme.json.name` が一致し、`[A-Za-z0-9_-]+` | 既存6IDと重複させない。小文字の `tomos-...` を継続するか判断が必要 |
| 表示名 | `theme.json.display_name`。空はinvalid | そのまま利用可能 |
| テーマバージョン | `theme.json.version`。空はinvalid | 形式・大小比較・更新判定は未実装。配布規則として形式を決める必要がある |
| 対応Tomosバージョン | 専用項目なし。`tomos-blog` はREADME本文に記載 | 【候補】当面READMEに明記。`theme.json` へ追加しても現行コードは読み取らない |
| ライセンス | 専用項目なし。任意の `LICENSE` ファイルは配置可能 | 【候補】テーマZIP直下のテーマディレクトリに `LICENSE` を必須同梱。Tomosのブランド画像は本体MITと別扱い |
| プレビュー画像 | `preview.png` の文書記載のみ。表示・検査なし | 【候補】公式サイト掲載用ファイルとして用意する。ZIPへの同梱、形式、寸法は判断が必要 |
| 必須ファイル | `theme.json`、`templates/layout.html`、`templates/page.html`、`templates/list.html`、`assets/style.css` | そのまま利用可能 |
| 任意ファイル | `templates/home.html`、favicon、apple touch icon、OGP、README、LICENSE、preview、テーマ固有アセット | favicon、apple touch icon、OGPは推奨。その他の許容範囲は配布審査で確認が必要 |

【事実】テーマZIPは署名済みTomos Update ZIPとは別物である。外部テーマZIPに `manifest.json`、`manifest.sig`、`files/` を持たせても、現行Tomos Postのテーマ追加には使えない。

## 検証項目

公開前には、テーマ単体、設置済みTomos、通常配布、Tomos Updateを分けて確認する。

### ZIPと設置

- [ ] ZIPファイル名が確定仕様どおりである
- [ ] ZIP直下が単一の `<テーマID>/` である
- [ ] 二重ディレクトリになっていない
- [ ] ZIPを展開し、`themes/<テーマID>/` へ配置できる
- [ ] 5つの必須ファイルがある
- [ ] `theme.json.name` とディレクトリ名が一致する
- [ ] `display_name` と `version` が空でない
- [ ] PHP系ファイルが含まれていない
- [ ] HTMLとSVGが現行 `ThemeValidator` を通る
- [ ] 配布対象外ファイル、個人情報、開発用ファイル、`.DS_Store` がない
- [ ] README、ライセンス、対応Tomosバージョンの表記を確認する

### テーマ切替と公開画面

- [ ] Tomos Postのテーマ一覧に表示される
- [ ] 表示名、テーマID、バージョン、説明、作者が正しい
- [ ] invalidの警告・エラーがない
- [ ] 確認画面を経てテーマを切り替えられる
- [ ] `config.php` の他の設定を変えず `theme.name` だけが切り替わる
- [ ] 切替後に公開画面へ反映される
- [ ] HTMLキャッシュが残って見た目が混在しない
- [ ] トップページが表示される
- [ ] 記事一覧が表示される
- [ ] 31件以上のフォルダーで一覧ページングが表示・遷移できる
- [ ] 記事詳細が表示される
- [ ] `about.md` 等の固定ページが表示される
- [ ] タグ一覧が表示される
- [ ] タグ別一覧が表示される
- [ ] 存在しないタグが404として表示される
- [ ] 検索フォーム、検索結果、結果なしが表示される
- [ ] 404が通常レイアウトで表示される
- [ ] RSSが有効な場合、`feed.xml` が正しいXMLで取得できる
- [ ] RSSがテーマHTMLに依存せず、記事URLと内容が正しい
- [ ] Sitemapが有効な場合、`sitemap.xml` が正しいXMLで取得できる
- [ ] SitemapがテーマHTMLに依存せず、公開URLが正しい
- [ ] 画像あり記事で本文画像が表示される
- [ ] 画像なし記事で不要な空枠やレイアウト崩れがない
- [ ] 存在しない画像のフォールバック表示が崩れない
- [ ] favicon、apple touch icon、OGP画像のURLが正しい
- [ ] テーマ固有画像のURLがドメイン直下設置で正しい
- [ ] テーマ固有画像のURLがサブディレクトリ設置で正しい

### 表示幅とアクセシビリティ

- [ ] 390px前後のモバイル表示で横スクロールがない
- [ ] 768px前後のタブレット表示が崩れない
- [ ] 1440px前後のデスクトップ表示が崩れない
- [ ] 長いタイトル、長いURL、長いコード、表、引用、リストが読める
- [ ] メニュー、リンク、ページングをキーボードで操作できる
- [ ] フォーカス表示、文字色、背景色のコントラストを確認する
- [ ] 画像の代替テキストと装飾画像の空altが適切である

### 削除と再追加

- [ ] 別テーマへ切り替えてから、FTP等で外部テーマを削除できる
- [ ] 削除後にTomos Postのテーマ一覧から消える
- [ ] 使用中テーマを手動削除した場合に `tomos-minimal` 表示へフォールバックすることを隔離環境で確認する
- [ ] 使用中テーマ削除後も `config.php` のIDは自動修復されないことを確認する
- [ ] 同じテーマIDを再追加すると再び一覧へ出る
- [ ] 再追加後に切替できる
- [ ] 旧ファイルが残らず、ZIP内容と設置内容が一致する

### Tomos Update後の維持

- [ ] 外部テーマIDが既存6テーマIDと重複していない
- [ ] 更新前に外部テーマのファイル一覧とSHA-256を記録する
- [ ] Tomos Updateの確認画面で外部テーマが対象ファイルに含まれていない
- [ ] Update後も外部テーマディレクトリが存在する
- [ ] Update前後で外部テーマのファイル一覧とSHA-256が一致する
- [ ] Update後もテーマ一覧へ表示される
- [ ] Update後も外部テーマへ切替できる
- [ ] Update後の公開画面、記事一覧、記事詳細、固定ページ、タグ、検索を再確認する
- [ ] Tomos側のテンプレート変数変更があった場合、未定義変数や表示欠落がない

### 通常配布ZIPへの非混入

- [ ] `build/tomos/themes/<外部テーマID>/` が存在しない
- [ ] `unzip -Z1 build/tomos-<VERSION>.zip` に `themes/<外部テーマID>/` がない
- [ ] 外部テーマ名、固有画像名、ライセンス文面の断片でも通常配布物を検索する
- [ ] 通常配布ZIPに同梱する6テーマは欠落していない
- [ ] 通常配布ビルドの必須ファイル検査が通る

## 未確認事項

- 実サーバーのPHP設定、FTP／ファイルマネージャーの挙動、ファイル権限
- 外部テーマZIPの実ダウンロード、展開、配置
- テーマ切替のブラウザ実動作
- 公開画面の各ルートとレスポンシブ表示
- 使用中テーマの手動削除と `tomos-minimal` フォールバックの実動作
- 外部テーマを置いた状態での実Tomos Update
- 通常配布ビルドを実行した成果物の内容。本調査では既存ビルドを消去・再生成していない
- ZIPファイル名、プレビュー画像、ライセンス、対応Tomosバージョンの確定仕様
- テーマアセットの総容量、ファイル数、許容拡張子の公開基準
- シンボリックリンクを含む手動配置テーマに対する `ThemeValidator` の詳細挙動
- 現行ドキュメントの同梱テーマ一覧が3件のままである点を、別工程で6件へ合わせるか
- `NOTICE` の同梱テーマ例が3件のままである点と、外部配布テーマへ本体ライセンスをどう適用するか

## 次の判断が必要な点

1. 外部テーマの導入を「利用者がZIPを展開しFTP等で配置する方式」として開始するか、Tomos PostからのZIP追加を必須にするか。
2. 外部テーマをこのリポジトリのどこで開発するか。`themes/` 配下なら通常配布ビルドでの除外が必要になる。
3. 通常配布からの除外をテーマIDごとの明示列挙にするか。現行コードからは一般的な外部テーマ属性を利用できない。
4. 外部テーマIDで `tomos-` 接頭辞と小文字を配布規則として必須にするか。
5. テーマバージョンの形式をどう定めるか。現行コードは空でないことしか検査しない。
6. 対応Tomosバージョンを当面READMEだけに記載するか。現行コードは互換性メタデータを扱えない。
7. テーマ単位の `LICENSE` を必須にするか。Tomosの名称、アイコン、OGP等のブランド素材はMIT Licenseの対象外である。
8. `preview.png` をZIPにも含めるか、公式サイト掲載用だけにするか。また形式と寸法をどうするか。
9. ZIPファイル名と単一ルートディレクトリ構造を上記候補で確定するか。
10. Photoテーマへ進む前に、通常配布への非混入方法と外部テーマZIPの公開前検証方法をどこまで自動化するか。

本調査の結論は、手動展開・手動配置を許容すれば、外部配布テーマの表示、検証、切替、Update後の維持は現行Tomosの仕組みを利用できる、というものである。一方、Tomos PostからのZIP追加、プレビュー、削除、重複処理は現行機能では不可能であり、外部テーマを `themes/` 配下で開発すると通常配布ZIPへ自動混入する。
