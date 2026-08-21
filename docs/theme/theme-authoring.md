# Tomos Theme Authoring

Tomos のテーマは、HTML テンプレート、CSS、theme.json で構成します。
`themes/tomos-minimal` は Tomos の標準テーマです。別テーマを作る場合も core を変更せず、`themes/` 配下に新しいテーマディレクトリを追加し、`config.php` の `theme.name` を変更して切り替えられる構造を維持します。

この文書を制作者向けの入口とします。詳細な契約は次の文書を参照してください。

- [theme-contract-v1.md](theme-contract-v1.md): `theme.json`、派生テーマ、標準テーマセットの契約
- [theme-settings-v1.md](theme-settings-v1.md): サイト固有の `theme-settings.php` と `theme.*` 設定
- [home-news-api-v1.md](home-news-api-v1.md): トップページの構造化News API
- [multilingual-v1.md](multilingual-v1.md): ページ言語と多言語ページの公開契約
- [theme-validation.md](theme-validation.md): ThemeValidatorとテーマZIP検査

テーマは、サイトの見た目を決めるファイル一式です。setup画面では、検証に通ったテーマだけを選べます。

この文書は、HTML/CSSが少し分かる人、AIにテーマ作成を依頼したい人、FTP等で `themes/` にテーマを置ける人向けです。

## 同梱サンプルテーマ

- `tomos-minimal`: 標準の最小テーマ
- `tomos-journal`: 日記・エッセイ・個人の記録向けの文章中心テーマ
- `tomos-dark`: 暗い背景で文章を静かに読めるダークテーマ
- `tomos-note`: ノート・研究メモ・読書メモ向けのテーマ
- `tomos-90s`: 90年代テキストサイト風のテーマ
- `tomos-blog`: 専用トップとプロフィールを持つブログ向けの応用テーマ

### 6テーマの比較

| テーマ | 主な用途 | `home.html` | ナビゲーション・表示の特徴 | 独自画像 | `list.latest_pages` | 位置づけ |
| --- | --- | --- | --- | --- | --- | --- |
| `tomos-minimal` | 最小構成の標準サイト | なし | 標準的なサイドナビゲーション | なし | なし | 基準テーマ |
| `tomos-note` | 研究・読書・技術メモ | なし | セクション導線と読みやすいノート組版 | なし | なし | 参照テーマ候補 |
| `tomos-90s` | テキストサイト風サイト | なし | `nav.primary_links`を使う独自ヘッダー | なし | なし | デザイン応用例 |
| `tomos-dark` | 暗い背景の読み物サイト | なし | 標準ナビゲーションのダーク配色 | なし | なし | デザイン応用例 |
| `tomos-journal` | 日記・エッセイ | なし | 標準ナビゲーションと文章中心の組版 | なし | なし | デザイン応用例 |
| `tomos-blog` | 個人ブログ | あり | プロフィール、専用トップ、最新記事カード | `header.png`、`profile.png` | あり | 高度な応用例 |

6テーマは同じ機能・同じテンプレート構造である必要はありません。`tomos-note`を標準的な読みやすい参照例、`tomos-blog`を専用トップを持つ応用例として扱います。

## 基本方針

- テーマに PHP は書かない
- テーマ内に `.php` ファイルを置かない
- Markdown 変換は core が行う
- URL 解決やファイル読み込みは core が行う
- 通常変数 `{{ variable }}` は HTML エスケープされる
- HTML 出力 `{{{ variable }}}` は core が許可した変数だけ使える
- 外部 script は標準で読み込まない
- 未定義変数は使わない

## テーマを置く場所

テーマは `themes/` フォルダの下に置きます。

例:

```text
themes/
  tomos-minimal/
  tomos-journal/
  tomos-dark/
  tomos-note/
```

テーマディレクトリ名は、英数字、ハイフン、アンダースコアだけを推奨します。

```text
良い例: tomos-journal
避ける例: journal theme
避ける例: ../journal
```

## 標準ディレクトリ構成

Tomosテーマの最小構成は次の通りです。

```text
themes/
  my-theme/
    theme.json
    templates/
      layout.html
      home.html
      page.html
      list.html
    assets/
      style.css
```

`templates/home.html` は任意です。配置するとサイトのトップページ（`/`）だけで使用されます。配置しない場合は、トップページでも従来どおり `templates/page.html` が使用されます。下層ページは `home.html` の有無にかかわらず `page.html` を使用します。

推奨アセット:

```text
assets/favicon.svg または assets/favicon.png
assets/apple-touch-icon.png
assets/ogp.png
README.md
preview.png
```

`templates/not-found.html` は現在の必須ファイルではありません。404ページも通常レイアウトで表示します。

## core と theme の責務

以下は core 側の責務です。テーマ側では実装しません。

- Markdown 変換
- frontmatter 解析
- ページ解決
- draft 除外
- `pages.json` 生成
- `nav.tree` 生成
- `nav.breadcrumbs` 生成
- `public_base_path` を使ったURL生成
- セキュリティ処理
- HTML エスケープ
- テンプレート変数の安全な差し込み

theme 側の責務は表示に限定します。

- `layout.html`
- `home.html`（任意。トップページ専用）
- `page.html`
- `list.html`
- `style.css`
- 必要最小限の `script.js`
- `theme.json`
- preview画像
- README

テーマ内に PHP ファイルを置いてはいけません。テーマ側でファイル読み込み、URL解決、Markdown変換、`pages.json` 処理、draft 判定を行ってはいけません。

## テンプレートの安全ルール

`{{ variable }}` はテキスト出力用です。出力時に `&`, `<`, `>`, `"`, `'` が自動的に HTML エスケープされます。ページタイトルやサイト名に HTML のような文字列が含まれても、HTML として実行されません。

`{{{ variable }}}` は HTML 出力用ですが、任意の変数には使えません。Tomos core が許可した変数だけが HTML として出力されます。MVP で許可する HTML 変数は以下です。

- `page.content`
- `page.meta_html`
- `page.body`
- `page.tags_html`
- `page.folder_pages_html`
- `nav.tree`
- `nav.mobile_tree`
- `nav.breadcrumbs`
- `list.pages`
- `list.latest_pages`
- `tag.list`
- `search.results`

現時点で標準テーマが使う主な HTML 変数は `page.content`, `page.body`, `page.meta_html`, `page.tags_html`, `nav.tree`, `nav.mobile_tree`, `nav.breadcrumbs` です。`{{{ site.name }}}` や `{{{ page.title }}}` のように許可されていない変数を三重波括弧で出力しても、公開画面では空文字になります。

URL 属性に入る `page.url` と `theme.asset_url` は core 側で安全化されます。Tomos のテンプレート変数としては相対URLまたは `/` から始まるルート基準URLを使い、`javascript:`, `data:`, `vbscript:`, `//example.com` や改行を含む値はそのまま出力されません。

AI にテーマを作らせる場合も、PHP、外部 script、未定義変数、許可されていない三重波括弧を使わないようにしてください。

## 初期テンプレート変数

以下は現在のTheme Platform v1でテーマから利用できる主な変数です。変数名は実装と一致させ、未定義の変数を前提にしないでください。

### `site.*`

サイト設定とcoreが生成するURL・表示情報です。

- `site.name`, `site.description`, `site.language`
- `site.url`, `site.base_path`, `site.public_base_path`
- `site.home_url`, `site.about_url`, `site.feed_url`, `site.sitemap_url`
- `site.ogp_url`
- `site.analytics_html`（coreが許可したHTML。通常はlayoutから出力）

### `page.*`

現在表示中のページです。通常ページとVirtual Folderで共通して使える表示用項目に加え、Virtual Folderでは`page.page_type`が`virtual_folder_index`になります。

- `page.title`, `page.description`, `page.date`, `page.updated`, `page.show_updated`
- `page.url`, `page.absolute_url`
- `page.language`
- `page.body`, `page.content`, `page.meta_html`, `page.tags_html`
- `page.folder_pages_html`

`page.body`や`page.content`などのHTMLは、coreが生成・検査した値だけを三重波括弧で出力できます。

### `nav.*`

NavigationBuilderが生成する値です。HTML値はcoreが生成するため、テーマ側でページ探索やURL生成を行いません。

- `nav.tree`, `nav.mobile_tree`, `nav.sections`
- `nav.primary_links`, `nav.breadcrumbs`
- `nav.home_url`, `nav.about_url`
- `nav.primary_items`（各itemは`label`、`url`、`type`）

### `list.*`

- `list.pages`: 現在の一覧ページのHTML
- `list.latest_pages`: トップページ向けの最新12件のHTML

Virtual Folderでは、`list.html`が選択され、公開されたフォルダー直下記事の一覧が`list.pages`に渡されます。テーマ作者はVirtualFolderIndexや`pages.json`を直接扱いません。

### `theme.*`

`theme-settings.php`から正規化されたサイト固有表示設定と、テーマアセットのURLです。

- `theme.asset_url`
- `theme.favicon_url`, `theme.favicon_type`, `theme.apple_touch_icon_url`
- `theme.hero_enabled`, `theme.hero_image_url`, `theme.hero_title`, `theme.hero_subtitle`
- `theme.hero_button_enabled`, `theme.hero_button_label`, `theme.hero_button_url`
- `theme.logo_url`, `theme.key_color`
- `theme.news_enabled`, `theme.news_heading`, `theme.news_more_label`

Hero、logo、key color、News設定の入力契約は[theme-settings-v1.md](theme-settings-v1.md)を参照してください。

### `home.*`

トップページの構造化News APIです。詳細は[home-news-api-v1.md](home-news-api-v1.md)に集約しています。

- `home.has_news`
- `home.news_items`（`date`、`date_display`、`title`、`url`）
- `home.news_url`

既存テーマが`home.*`を使わなくても動作することは後方互換の一部です。6テーマすべてに同じAPI利用を強制しません。

三重波括弧で HTML として出力できる変数は、core のホワイトリストにあるものだけです。通常の `{{ variable }}` は必ず HTML エスケープされます。

`page.meta_html` は、記事ページのタイトル下に表示する日付HTMLです。`date` がある場合は公開日を表示し、`updated` があり `date` と異なる場合だけ更新日を表示します。

`page.folder_pages_html` は、フォルダーの `index.md` または仮想フォルダーページで、直下の公開記事を新しい順に表示します。一覧は core 側で1ページ30件に分割され、`?page=2` のようなURLで続きを表示します。テーマは `.folder-page-summary`, `.folder-pagination`, `.folder-pagination-pages`, `.folder-pagination-prev`, `.folder-pagination-next` をCSSで装飾できます。テーマ側で全件処理やURL生成を実装しないでください。

`nav.tree` と `nav.mobile_tree` は、大量記事によるHTML肥大化を防ぐため、1フォルダーあたり最大30ページと「すべて見る」を出力します。30件より後の記事を閲覧中は、その記事を制限内に残します。全記事への導線は `page.folder_pages_html` のページング一覧が担います。

## Virtual Folder

`content/<folder>/`に公開された直下Markdown記事があり、公開された`index.md`がない、または`index.md`がdraftの場合、Tomosは`/<folder>/`をVirtual Folder Indexとして解決できます。Virtual Folderは`list.html`を使用し、一覧は`list.pages`から出力します。

これはテーマ側でroutingを実装する機能ではありません。テーマは通常の`list.html`と`list.pages`を用意するだけでよく、`pages.json`、draft判定、Markdown解析、`folder_pages_html`の内部橋渡しを直接扱わないでください。

## CSSの扱い

CSSセレクターは、安定契約として文書化されたものとcore内部実装を区別します。現時点でテーマ作者が利用できる代表的なフックは、フォルダー一覧の`.folder-page-summary`、`.folder-pagination`、`.folder-pagination-pages`、`.folder-pagination-prev`、`.folder-pagination-next`、検索の`.search-summary`、`.search-results`です。その他のcore生成classは、明示的に契約化されるまで内部実装として扱ってください。

テーマの表示確認は次の4層を分けて行います。

1. TomosがHTMLを生成している
2. DOMに対象要素が存在する
3. CSSで対象要素が表示状態になっている
4. ブラウザまたはCDNが最新CSSを取得している

HTTP 200やHTML生成成功だけではブラウザ表示成功とは判定しません。CSS変更時はブラウザ・CDNキャッシュも確認します。

## テーマ追加の前提

テーマは以下のように追加できます。

- `themes/tomos-minimal/`
- `themes/tomos-paper/`
- `themes/tomos-note/`

標準テーマから派生する場合は、[theme-contract-v1.md](theme-contract-v1.md)の手順に従い、元テーマと異なる一意のIDを使用してください。標準テーマを直接改変すると、Tomos Updateで標準テーマが更新された際に独自変更と混線する可能性があります。

Tomos は、`config.php` の `theme.name` に指定されたテーマディレクトリからテンプレートとアセットを読み込みます。テーマを追加しても、ページ解決、Markdown変換、ナビゲーション生成、URL生成、セキュリティ処理は core が担当します。

テーマ追加は、FTPまたはサーバーのファイルマネージャで `themes/` 配下にテーマディレクトリを置く方式です。テーマアップロード機能はありません。

設置後のテーマ切替は、`/post/` のサイト設定から行います。変更できるのは `theme.name` だけです。テーマの追加や編集はこの画面ではできません。

setup画面では、検証に通ったテーマだけを選択できます。setupで選べるテーマにするには、次の条件を満たしてください。

- `themes/` 配下にテーマディレクトリを置く
- `theme.json` を置く
- `theme.json` の `name` をディレクトリ名と一致させる
- `display_name`, `version`, `description` を書く
- 必須テンプレートを置く
- `assets/style.css` を置く
- PHPファイルを含めない

AI向けテーマ作成プロンプトの本格整備は今後の作業で扱います。

AIにテーマ作成を依頼する場合は、`ai-theme-prompt.md` も参照してください。作成後は `theme-checklist.md` で確認してください。

## Wikiリンクの装飾

Wikiリンクは core 側で安全なHTMLとして生成され、本文HTMLに含まれます。テーマ側ではCSSで装飾できます。

- `.wiki-link`: 存在するページへのWikiリンク
- `.tomos-missing-link`: 存在しないページ、draftページ、危険なターゲット、または衝突したaliasへの未解決Wikiリンク

テーマ側でWikiリンクの存在判定やURL生成を行ってはいけません。

## 画像の装飾

Markdown画像は core 側で安全なHTMLとして生成され、本文HTMLに含まれます。テーマ側ではCSSで装飾できます。

- `.page-content img`: 本文内画像
- `.image-missing`: 存在しない画像、許可されない拡張子、危険なパスのフォールバック表示

テーマ側で画像パス解決、存在確認、URL生成を行ってはいけません。

## タグの装飾

タグ表示とタグ一覧は core 側で安全なHTMLとして生成されます。テーマ側ではCSSで装飾できます。

- `.page-tags`: ページ下部のタグ一覧
- `.tag-link`: タグリンク
- `.tag-index`: `/tags/` のタグ一覧
- `.tag-page`: `/tags/{tag}` のタグ別ページ
- `.tag-page-list`: タグ別ページ一覧

テーマ側でタグ集計、タグURL生成、`pages.json` 処理を行ってはいけません。

## 検索の装飾

検索ページは core 側で安全なHTMLとして生成されます。テーマ側ではCSSで装飾できます。

- `.search-page`: 検索ページ全体
- `.search-form`: 検索フォーム
- `.search-summary`: 検索件数や空検索メッセージ
- `.search-results`: 検索結果一覧

テーマ側で検索処理、検索語の解釈、`pages.json` 読み込みを行ってはいけません。

## theme.json

テーマには `theme.json` を置きます。

```json
{
  "name": "tomos-journal",
  "display_name": "Tomos Journal",
  "version": "0.1.0",
  "description": "A calm writing-focused theme for Tomos.",
  "author": "Tomos Project",
  "supports": {
    "navigation": true,
    "tags": true,
    "search": true,
    "ogp": true,
    "responsive": true
  }
}
```

`theme.json` の主な項目:

- `name`: テーマディレクトリ名と一致させます。
- `display_name`: setup画面に表示される名前です。
- `version`: テーマのバージョンです。
- `description`: 素人にも分かる短い説明にします。
- `author`: 作成者名です。
- `supports`: 対応機能の目安です。実際の機能処理はcore側が行います。

`display_name` と `version` は空にしないでください。

## テーマ検証

テーマ検証では、必須ファイル、`theme.json`、PHPファイル混入、テンプレート内の危険記述を確認します。

必須ファイル:

- `theme.json`
- `templates/layout.html`
- `templates/page.html`
- `templates/list.html`
- `assets/style.css`

禁止事項:

- テーマ内PHPファイル
- テンプレート内PHPタグ
- `javascript:` URL
- `data:text/html`
- 外部scriptやイベントハンドラ属性

`public_base_path` はHTML内リンク用です。RSS/sitemap内URLは `site.url` から絶対URLを生成します。この違いをテーマ側で処理せず、core が渡す変数を使ってください。

## favicon / OGP

テーマは favicon / apple-touch-icon / OGP画像を持つことができます。

標準テーマでは以下を利用します。

- `assets/favicon.svg` または `assets/favicon.png`
- `assets/apple-touch-icon.png`
- `assets/ogp.png`

favicon と apple-touch-icon は `public_base_path` 基準のURLでHTMLに出力されます。

OGP画像と `og:url` は `site.url` 基準の絶対URLで出力されます。

代表的な変数:

- `theme.favicon_url`
- `theme.apple_touch_icon_url`
- `site.ogp_url`
- `page.absolute_url`
