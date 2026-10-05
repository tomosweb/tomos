# Tomos v1.1.9 Golden Regression 基準

策定日: 2026-10-05  
基準: Tomos v1.1.9  
状態: Phase 2 設計。テスト実装は未着手。

## 1. 目的

GitHub版の開発に伴って共通Publishing Coreを整理する際、Core版の既存公開仕様を意図せず変更しないため、Tomos v1.1.9の公開結果をGolden Regressionの基準とする。

既存の個別テストは継続利用し、その上に「1つの代表サイトをTomosとして公開した結果全体」を比較する回帰Gateを追加する。

Phase 2では基準と比較方法だけを固定し、Core実装やGitHub版実装は変更しない。

## 2. 基本方針

### 2.1 v1.1.9を基準にする

Goldenデータはv1.1.9 tagの実装から生成する。

後続のCore整理後は、同一fixture・同一config・同一Themeを入力し、v1.1.9 Goldenと比較する。

### 2.2 既存テストを置き換えない

既存の以下のような個別回帰テストは、そのまま価値がある。

- SEO Foundation
- 日本語URL
- 多言語
- Wiki / Related Items
- TOC
- Virtual Folder
- Theme pagination
- Theme package / compatibility
- Update regression

Golden Regressionはこれらの代替ではなく、公開処理全体の統合回帰Gateとする。

### 2.3 比較対象を「意味のある公開出力」に限定する

時刻、nonce、一時path、cache状態など、実行ごとに変動する値をGolden比較へ直接含めない。

公開仕様として意味があるものを比較する。

## 3. Canonical Fixture Site

テスト専用の小規模サイトを1つ用意する。

名称例:

```text
tests/fixtures/publishing-core-v1/
```

この名称は実装時に確定する。

### 3.1 必須コンテンツ

#### Home

`content/index.md`

確認対象:

- home判定
- title fallback
- home.html選択
- Home News
- latest pages
- SEO

#### 固定ページ

`content/about.md`

確認対象:

- fixed_page
- Navigation
- Breadcrumbs
- canonical

#### 通常記事

複数の記事を用意する。

確認対象:

- Front Matter
- date / published / updated
- description
- tags
- OGP image
- Markdown
- TOC
- related items

#### 日本語path

例:

```text
content/読書/認識について.md
```

確認対象:

- UTF-8 path
- canonical encoding
- internal link
- sitemap
- RSS

#### 英語記事

`language: en` を持つ記事を含める。

確認対象:

- page.language
- html language
- SEO language
- site.languageとのfallback

#### draft

公開記事と同じフォルダーにdraft記事を含める。

確認対象:

- Page Catalogからの公開除外
- Navigation除外
- Tag集計除外
- RSS / Sitemap除外
- Wiki Link解決時の非公開扱い

#### Wiki Link

次を含める。

- 正常なWiki Link
- alias
- 存在しないWiki Link
- draftへのWiki Link
- 画像Wiki記法

#### Markdown画像

次を含める。

- 同一directory相対画像
- subdirectory画像
- Front Matter image
- 外部画像URL

#### Tags

複数tagを重複させ、countとtag pageを確認する。

#### Virtual Folder

公開 `index.md` を持たないfolderに複数記事を置く。

確認対象:

- Virtual Folder成立
- title
- list template
- Navigation
- Sitemap

#### 通常Folder Index

別folderには公開 `index.md` を置き、Virtual Folderとの違いを確認する。

#### 31件以上のfolder

folder直下に31件以上の記事を置く。

目的:

- v1.1.9の30件paginationをGoldenとして固定
- `?page=2` を明示的に記録
- GitHub版で別方式へ変更する場合の差分を可視化

このfixtureはPhase 4前のpagination方式決定にも使う。

### 3.2 External URL fixture

可能ならネットワーク結果そのものをGoldenに固定しない。

YouTube等の純粋なURL変換部分は含めてもよいが、外部HTTPレスポンスに依存するカードはmock / fixed resolverを利用する設計とする。

Golden Regressionを外部サービスの可用性で失敗させない。

## 4. Theme Fixture

Golden Regression専用Themeを1つ用意する。

目的は見た目の評価ではなく、Theme Contextを最大限観測できること。

例:

```text
tests/fixtures/publishing-core-v1/theme/
  theme.json
  templates/
    layout.html
    home.html
    page.html
    list.html
  assets/
    style.css
```

### Themeが表示する値

最低限次をHTMLへ明示的に出す。

- site.name
- site.description
- site.language
- site.url
- site.public_base_path
- page.title
- page.description
- page.url
- page.absolute_url
- page.language
- page.date
- page.updated
- page.content
- page.toc
- page.tags_html
- page.folder_pages_html
- page.related_items
- nav.tree
- nav.mobile_tree
- nav.breadcrumbs
- nav.primary_items
- nav.all_url
- tag.items
- list.pages
- list.latest_pages
- home.has_news
- home.news_items
- home.news_url
- theme.asset_url
- theme.asset_version
- SEO head

これによりTheme Contextの欠落をHTML差分として検出できる。

## 5. Golden Outputs

v1.1.9から次を保存する。

### 5.1 Page HTML

代表routeごとのHTML。

例:

- `/`
- `/about`
- 通常記事
- 日本語path記事
- 英語記事
- folder index
- Virtual Folder
- tag一覧
- 個別tag
- `/all/`
- search初期画面
- 404

### 5.2 Page Model

公開Page Catalogを正規化JSONとして保存する。

比較対象:

- path
- url
- page_type
- title
- description
- excerpt
- date
- published
- updated
- image
- tags
- draft
- language
- search_text

mtime、size、content_sha256等は目的別に扱う。

content fixtureが固定されるためhash比較は可能だが、Publishing Coreの意味論比較とfilesystem freshness比較を混同しない。

### 5.3 Theme Context

代表pageについてRendererへ渡るContextを正規化JSONで保存できる設計とする。

特に、

- site
- page
- nav
- tag
- list
- home
- theme

を確認する。

### 5.4 RSS

`feed.xml` の内容。

### 5.5 Sitemap

`sitemap.xml` の内容。

### 5.6 robots.txt

公開内容を保存する。

### 5.7 Search Data

SearchIndexの入力となる公開検索データを正規化して保存する。

GitHub版では検索実行方式が変わるため、検索UI HTMLよりも検索対象データの一致を重視する。

## 6. HTML比較の正規化

HTMLを単純byte-for-byte比較するとRuntime固有値で誤差が出る可能性がある。

比較前に、公開仕様ではない値だけを明示的に正規化する。

候補:

- CSP / analytics nonce
- temporary filesystem path
- 実行時生成のrequest固有値

ただし、次は正規化して消してはいけない。

- URL
- canonical
- title / description
- OGP
- language
- Theme asset URL
- page本文
- heading id
- TOC
- tag link
- Navigation
- Breadcrumbs
- pagination link

「差分が面倒だから削る」という正規化は禁止する。

正規化項目はallowlist方式で管理する。

## 7. PASS / FAIL規則

### PASS

- 正規化後HTMLがGoldenと一致
- Page Modelが一致
- Theme Contextが一致
- RSSが一致
- Sitemapが一致
- robots.txtが一致
- 検索対象データが一致
- 既存個別テストもPASS

### FAIL

1つでも説明できない差分があればFAILとする。

Core整理で意図的に仕様変更する場合は、

1. 差分内容を文書化
2. Core版への影響確認
3. Theme Contractへの影響確認
4. ユーザー承認
5. Golden更新

の順とする。

単に新実装の結果へGoldenを更新してPASSにしない。

## 8. Core版とGitHub版の比較Gate

GitHub版のLocal Static Buildができた段階で、同じfixtureから次を比較する。

### 完全一致を原則とする

- Page Model
- Markdown本文HTML
- heading id / TOC
- Wiki Link
- related items
- Tag data
- Navigation data
- SEO metadata
- RSS
- Sitemap
- Theme Context

### Runtime差を許容する

- HTTP statusの返し方
- HTTP header
- CSP nonce
- cache file
- filesystem layout
- deploy metadata
- 404のHosting処理
- Search実行方式
- pagination URL方式（仕様決定後に明示）

Runtime差は「何が違ってよいか」を個別にallowlistする。

## 9. Pagination専用基準

v1.1.9 Core版については次をGoldenとして保持する。

- 1ページ30件
- `?page=2` 形式
- summary表示
- prev / next
- page number link
- Home latest listにはfolder paginationを出さない

GitHub版の方式が決まっても、このGoldenは「v1.1.9 Core版互換基準」として残す。

GitHub版との差分をCore不具合と誤判定しないよう、paginationだけはRuntime Contractを別に持つ。

## 10. 多言語基準

Stable版のグローバル志向を踏まえ、fixtureは最初から日本語とEnglishを含める。

最低限確認する。

- site.language = ja
- page.language = ja
- page.language = en
- language未指定pageのfallback
- 日本語title
- English title
- 日本語tag
- English tag
- UTF-8 URL
- canonical
- RSS
- Sitemap

管理UIの日英切替はPublishing Goldenとは別テスト群とする。

## 11. 既存テストとの役割分担

### 個別テスト

原因箇所を早く特定する。

例:

- `seo_foundation_check.php`
- `seo_url_regression_check.php`
- `multilingual_foundation_check.php`
- `core_wiki_data_check.php`
- `page_toc_app_check.php`
- `virtual_folder_app_e2e_check.php`
- Theme pagination関連test

### Golden Regression

Tomosの公開結果全体がv1.1.9から変わっていないことを確認する。

両方をFormal Gateへ組み込むことをPhase 3以降で検討する。

## 12. 実装予定物

Phase 2の次工程で実装する場合、概ね次を想定する。

```text
tests/
  fixtures/
    publishing-core-v1/
      content/
      theme/
      assets/
      config/
  golden/
    v1.1.9/
      pages/
      page-model.json
      contexts/
      feed.xml
      sitemap.xml
      robots.txt
      search-data.json

  publishing_core_v1_regression_check.php
```

正確なdirectory名は既存test構造に合わせて実装時に決める。

## 13. Phase 2完了条件

設計上は次を満たした時点でPhase 2完了とする。

1. v1.1.9をGolden基準とすることが明記されている。
2. fixtureに必要なコンテンツ種別が定義されている。
3. Theme Contextの観測方法が定義されている。
4. HTML / Page Model / RSS / Sitemap / Search Dataの比較対象が定義されている。
5. volatile値の正規化規則が定義されている。
6. PASS / FAIL規則が定義されている。
7. Core版とGitHub版の許容差分が定義されている。
8. paginationを専用差分として扱うことが定義されている。
9. 日本語 / Englishを最初からfixtureへ含める。
10. 既存個別テストを維持する。

このPhaseでは既存Core、Theme、Runtimeの実装変更は行わない。
