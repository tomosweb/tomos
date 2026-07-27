# Wikiリンク

Tomos は Markdown 本文内の Wikiリンクを、公開ページへのHTMLリンクに変換します。

## 基本形

```markdown
[[about]]
```

リンク先ページが存在する場合、リンクテキストにはリンク先ページの `title` を使います。

## 画像埋め込みとの違い

```markdown
[[about]]
```

は Wikiリンクとして処理します。

一方、以下は画像埋め込みとして処理し、Wikiリンクとしては処理しません。

```markdown
![[images/sample.png]]
![[images/sample.png|代替テキスト]]
```

Tomos は Obsidian形式画像を Wikiリンクより先に解釈します。`WikiLinkParser` は、直前に `!` がある `[[...]]` を対象外にします。

## パス指定

```markdown
[[diary/2026-07-05]]
```

`content/diary/2026-07-05.md` に対応する公開URLへリンクします。

## 別名指定

```markdown
[[diary/2026-07-05|日記を見る]]
```

別名を指定した場合、リンクテキストにはページタイトルではなく別名を使います。

## 存在しないページ

存在しないページはリンクにせず、未解決リンクとして表示します。存在しないURLへのリンクにはしません。

```html
<span class="tomos-missing-link" data-link-target="存在しないページ">存在しないページ</span>
```

## Obsidian由来のリンク

Obsidian形式の `[[ページ名]]` リンクにも対応しています。ファイル名とfrontmatterの `title` が異なる場合でも、Tomosは `cache/index/link-aliases.json` のalias辞書を使ってリンク先を探します。

後からリンク先Markdownを追加し、`pages.json` と `link-aliases.json` が再生成されると、既存記事内の未解決Wikiリンクもリンク化されます。インデックス再生成時には本文HTMLキャッシュも再生成対象になります。

同じ名前のファイルが複数ある場合、basenameだけではリンク先を決めません。必要に応じて `[[2026/読書メモ]]` のようにフォルダ名を含めて指定してください。

## 注意

- 外部URLは Wikiリンクでは扱いません
- `draft: true` のページは存在しないものとして扱います
- `../config` や `javascript:alert(1)` のような危険なターゲットは有効リンクにしません
- Wikiリンクは `pages.json` と `link-aliases.json` の公開ページ一覧を使って存在判定します
