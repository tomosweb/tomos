# Tomosでの記事の書き方

Tomosでは、Markdownファイルを `content/` フォルダに置くとWebページとして公開できます。

Tomosでは、次の流れで記事を公開します。

1. [Tomos Write](https://tomoswords.org/write/)でMarkdownを書く
2. `.md` ファイルとして保存する
3. Tomos Postで投稿する
4. Tomosで公開ページを確認する

Tomos Writeは、ブラウザ上でMarkdownの記事を作成するための補助ツールです。Tomosでは、ほかのMarkdownエディタで作成したファイルも利用できます。Tomos本体に文章を直接保存するものではありません。作成したファイルは `.md` として保存し、Tomos Postから投稿します。

→ [Tomos Writeを開く](https://tomoswords.org/write/)

## 記事ファイルの置き場所

記事は `content/` フォルダに `.md` ファイルとして置きます。

例:

```text
content/index.md
content/about.md
content/diary/2026-07-06.md
```

URLはおおむねファイルの位置に対応します。

```text
content/index.md
=> /

content/about.md
=> /about

content/diary/2026-07-06.md
=> /diary/2026-07-06
```

`index.md` と `about.md` は、Tomos Postから現在の最新版をダウンロードできます。編集後もそれぞれのファイル名を変更せず、通常の投稿フォームから再投稿します。この2ファイルは保存先フォルダを指定しても `content/` 直下へ保存され、取り下げや完全削除はできません。

`content/` 内にはフォルダを作れます。`2026/` や `2026/07/` のような数字だけのフォルダ名も通常のフォルダ名として扱います。空フォルダがあってもページとしては扱われません。

画像ファイルや `.DS_Store` など、Markdown以外のファイルが `content/` 内にあっても、Tomosはページとして扱いません。ページになるのは `.md` ファイルです。

## 基本形

```markdown
---
title: 記事タイトル
date: 2026-07-06
description: 記事の短い説明です。
tags:
  - 日記
  - Tomos
draft: false
---

# 記事タイトル

ここに本文を書きます。
```

frontmatterはファイルの先頭に書き、`---` で囲みます。本文はfrontmatterの後に書きます。

本文の最初のH1見出しと `title` が同じ場合、Tomosは重複表示を避けるため、本文側のH1を表示本文から外します。

## frontmatter

記事の先頭に `---` で囲んだ設定を書けます。

```markdown
---
title: 記事タイトル
date: 2026-07-06
updated: 2026-07-07
description: 一覧やOGPで使われる短い説明です。
folder: diary
tags:
  - Web制作
  - Markdown
draft: false
---
```

### title

記事タイトルです。ページの見出し、一覧、検索結果、OGPなどで使われます。

### date

公開日として扱う日付です。記事ページではタイトル下に表示されます。日記や一覧の並び順にも使われます。

### updated

更新日です。必要な場合だけ書きます。`updated` が `date` と異なる場合だけ、記事ページのタイトル下に更新日として表示されます。

### description

一覧、検索、OGPなどで使う短い説明です。

### folder

Tomos Postで投稿する時の保存先フォルダです。Tomos Writeで保存先フォルダを指定すると、Markdown内に `folder` として保存されます。

Tomos Postでファイルを選ぶと、`folder` の値が保存先フォルダ欄に自動で反映されます。最終的な保存先はTomos Post画面の入力欄が優先されるため、投稿前に確認・変更できます。

空の場合や書かない場合は、Tomos Postでは `content/` 直下への投稿として扱います。

### tags

記事に付けるタグです。タグ一覧やタグ別ページで使われます。

### draft

`true` にすると公開されません。

## タグ

タグはfrontmatter内に書きます。1行ずつ `-` で並べる書き方を推奨します。

```markdown
tags:
  - 日記
  - 読書
  - Web制作
```

タグを入れない場合は、`tags` 自体を書かなくても構いません。

タグ名は表記を揃えてください。たとえば、次のような表記は別タグとして扱われる可能性があります。

```text
Web制作
web制作
WEB制作
```

## 下書き

下書きにしたい記事は `draft: true` にします。

```markdown
---
title: 下書き記事
draft: true
---
```

`draft: true` のページは公開されません。一覧、タグ、検索、RSS、sitemapの対象外になります。

公開する場合は `draft: false` にするか、`draft` 行を削除します。

### language

ページの言語を指定する場合は `language` を使います。省略した場合はサイトの既定言語が使われます。

```markdown
---
title: What is Tomos?
language: en
---
```

Tomosはこの情報を使って公開上の言語を扱いますが、翻訳本文や翻訳ページ間の関係は管理しません。

## 見出し

Markdownでは `#` を使って見出しを書きます。

```markdown
# 大見出し
## 中見出し
### 小見出し
```

## 本文

本文はfrontmatterの後に通常のMarkdownとして書きます。

段落は空行で区切ります。段落内の単一改行も、公開ページで改行として表示します。

```markdown
これは本文です。

これは次の段落です。
```

基本的なMarkdown記法として、太字、斜体、引用、箇条書き、番号リスト、インラインコード、コードブロック、水平線を使えます。

```markdown
これは **太字** です。
これは *斜体* です。

> 引用です。

- 箇条書き
1. 番号リスト

`インラインコード`

---
```

コードブロックも使えます。

````markdown
```text
コードブロックです
```
````

## リンク

通常のMarkdownリンクは次のように書きます。

```markdown
[このサイトについて](about)
```

単体ページのURLには末尾の `/` を付けません。フォルダの記事一覧や、フォルダ内の `index.md` へリンクする場合は末尾に `/` を付けます。

```text
content/about.md
=> /about

content/diary/index.md
=> /diary/
```

末尾スラッシュの有無には意味があります。たとえば `/about/` は `content/about.md` ではなく、`content/about/index.md` に対応するURLです。

外部URLへリンクする場合は通常のMarkdownリンクを使ってください。

```markdown
[外部サイト](https://example.com/)
```

## テーブル

GFM形式のテーブルを書けます。

```markdown
| 名前 | 役割 | 状態 |
|---|---|---|
| Tomos | 公開する | 動作中 |
| Tomos Write | 書く | 動作中 |
| Tomos Post | 投稿する | 動作中 |
```

スマホ幅では、表だけ横スクロールして読めるように表示されます。

## Wikiリンク

Tomos内のページへリンクする場合はWikiリンクも使えます。

```markdown
[[about]]
[[about|このサイトについて]]
[[diary/2026-07-06]]
[[2026-01-03-2025年心に残った本10冊]]
[[2026-01-03-2025年心に残った本10冊|2025年の読書まとめ]]
[[2026/読書メモ]]
[[2026-01-03-2025年心に残った本10冊#番外編]]
```

WikiリンクはTomos内のページへの内部リンクとして扱います。存在しないページはリンクにせず、未解決リンクとして文字列を表示します。

Obsidian形式の `[[ページ名]]` リンクにも対応しています。ファイル名とfrontmatterの `title` が異なる場合でも、Tomosが可能な範囲でリンク先を探します。

後からリンク先Markdownを追加した場合は、インデックスとHTMLキャッシュが再生成されるとリンク化されます。FTPでMarkdownを追加して表示が古い場合は、キャッシュの再生成が必要です。

同じ名前のファイルが複数ある場合は、Tomosが勝手にどちらか一方へリンクしないよう、未解決リンクとして扱います。その場合は、次のようにフォルダ名を含めて指定してください。

```markdown
[[2026/読書メモ]]
```

## 画像

画像は `content/` 配下に置きます。

例:

```text
content/images/photo.jpg
```

通常Markdown画像:

```markdown
![画像の説明](images/photo.jpg)
```

外部画像URLも通常Markdown画像として書けます。

```markdown
![画像の説明](https://example.com/photo.jpg)
```

Obsidian風画像:

```markdown
![[images/photo.jpg]]
![[images/photo.jpg|画像の説明]]
```

使える画像形式は `jpg`, `jpeg`, `png`, `gif`, `webp` です。

Tomos Writeで入れた画像は、Tomos PostでMarkdownと元画像を一緒に選ぶと投稿できます。画像は最大5点、1点10MBまでです。

公開を1回操作すると、画像を1点ずつ順番に送信し、すべて揃った後にページを公開します。送信に失敗した場合は未完了のページを公開せず、もう一度投稿できます。サーバーの設定変更は必要ありません。

JPEG、PNG、WebPは、公開先で長辺2048px以内へ調整されます。GIFはアニメーション維持のため元のまま保存されます。

同じ画像を複数のページで使っている間は、その画像を使うページを1つ更新または取り下げても画像は残ります。どの公開ページからも使われなくなった画像だけが削除されます。

SVG、PHP、HTMLなどは画像として表示しません。ローカル画像は `content/` 配下に置いてください。

## ファイル名とURL

ファイル名とURLの関係は次のようになります。

```text
content/about.md
=> /about

content/diary/index.md
=> /diary/

content/diary/2026-07-06.md
=> /diary/2026-07-06

content/2026-01-03-2025年心に残った本10冊【254本目】.md
=> /2026-01-03-2025年心に残った本10冊【254本目】
```

新しいフォルダへ記事を置くと、そのフォルダの記事一覧ページも表示されます。フォルダ専用の `index.md` は必須ではありません。

```text
content/test/article.md
=> /test/article

フォルダの記事一覧
=> /test/
```

`content/test/index.md` を後から追加すると、`/test/` にはその本文と記事一覧が表示されます。`index.md` を削除しても公開記事が残っていれば、記事一覧ページは引き続き表示されます。公開記事が1件もない空フォルダは公開されません。

日本語のファイル名も使えます。`【】`、`（）`、半角括弧、空白など、一般的なファイル名に含まれる記号も扱えるようにしています。

ただし、`/` や `\` などパスとして危険な文字は使えません。Tomos Postから投稿する場合、危険な文字は安全なファイル名へ自動的に変更され、投稿結果画面に変更前後が表示されます。

## Tomos Writeで書く

Tomos Writeを使う場合の基本の流れは次の通りです。

1. Tomos Writeを開く
2. テンプレートを選ぶ
3. タイトル、日付、タグなどを入力する
4. Markdown本文を書く
5. `.md` ファイルとして保存する

Tomos Writeの保存先フォルダはMarkdown内の `folder` に保存され、Tomos Postでファイルを選んだ時に保存先フォルダ欄へ自動反映されます。最終的な保存先はTomos Postの画面で確認してください。

## Tomos Postで投稿する

Tomos Postは、Tomos Writeなどで作成したMarkdownファイルをTomosに投稿するための画面です。

基本の流れ:

1. Tomos Writeで記事を作る
2. `.md` として保存する
3. Tomos Postの `/post/` を開く
4. setup完了時に控えた管理用合言葉を入力する
5. Tomos Writeで保存した `.md` ファイルを選ぶ
6. Markdownに画像がある場合は元画像を選ぶ
7. 不足画像がある場合は、追加で選ぶか掲載をやめるかを指定する
8. 保存先フォルダとファイル名を確認する
9. 投稿する
10. 表示された公開URLを開いて確認する

Tomos Postで投稿できるファイルは `.md`、`.markdown`、`.txt` です。`.markdown` と `.txt` は `.md` として保存されます。

Markdown内に `folder` がある場合は、保存先フォルダ欄へ自動で反映されます。必要に応じて、投稿前にTomos Post画面で変更できます。存在しないフォルダは `content/` 配下に作成されます。

画像の掲載をやめる指定をしても、端末に保存されている元のMarkdownは変更されません。投稿先へ保存するMarkdownだけから該当する画像記述を外します。

同じ名前のファイルがある場合は、現在のページを同じURLで更新するか、別のファイル名で新しいページとして投稿するかを確認画面で選びます。

## 投稿を取り下げる

間違ったページや古くなったページをWeb上から外したい場合は、Tomos Postで投稿を取り下げます。

基本の流れ:

1. Tomos Postの `/post/` を開く
2. 「投稿を取り下げる」で公開URL、または `content/` 内のパスを入力する
3. 表示された対象タイトル、日付、保存先を確認する
4. 別タブ確認リンクで実際のページを確認する
5. 管理用合言葉を入力して取り下げる

取り下げたファイルは完全削除されず、設置ディレクトリ直下の `trash/content/` に移動します。

```text
content/diary/2026-07-07.md
=> trash/content/diary/2026-07-07.md
```

`trashを空にする` を実行すると、取り下げ済みファイルは完全削除され、元に戻せません。

## 投稿後に確認する

投稿に成功すると、Tomos Postに保存先と公開URLが表示されます。

1. 公開URLを開く
2. ページが表示されるか確認する
3. `date` と `updated` が意図通り表示されるか確認する
4. タグ、検索、RSS、sitemapに反映されるか確認する

`date` は公開日として記事タイトル下に表示されます。`updated` は更新日として使われます。`updated` が `date` と同じ場合、更新日は表示されません。

## 予約パス

次のURLはTomosの機能で使うため、記事ファイルのURLとして使わないでください。

```text
/setup/
/post/
/post/reset/
/post/theme/
/post/theme/confirm/
/search/
/tags/
/feed.xml
/rss.xml
/sitemap.xml
```

たとえば `content/post.md` や `content/post/index.md` は避けてください。

## 書くときの注意

- 生HTMLは標準では使えません。
- JavaScriptは記事本文に書かないでください。
- 画像は許可拡張子を使ってください。
- タグの表記を揃えてください。
- 公開前に `draft: true` が残っていないか確認してください。
- 大事な記事はバックアップを取ってください。
