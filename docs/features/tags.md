# タグ

Tomos は frontmatter の `tags` を使って、ページ下部のタグ表示、タグ一覧、タグ別ページ一覧を生成します。

## frontmatterでの書き方

```yaml
tags:
  - diary
  - memo
```

```yaml
tags: [diary, memo]
```

```yaml
tags: diary, memo
```

## Obsidianの本文タグ

Tomos Publisher / Inbox APIなど外部投稿経路では、Obsidian形式の本文タグも投稿用コピーから検出します。

```markdown
今日は #京都 を #自転車 で走った。
#生活/自転車
```

検出したタグは、既存のfrontmatter `tags` と重複を除いて統合し、Tomosへ保存するMarkdownの `tags` に反映します。ObsidianやWorkspace側の元Markdownは変更しません。

コードブロック、インラインコード、URL内の `#fragment`、エスケープした `\#tag`、数値だけの `#123` はタグとして扱いません。

Tomosの正規タグ情報は引き続きfrontmatterの `tags` です。本文中の `#tag` を公開表示Coreが直接タグとして解釈する仕様にはしません。

## ページでの表示

ページ下部にタグが表示されます。タグリンクは `/tags/{tag}` へつながります。

## タグ一覧

```text
/tags/
```

タグ名とページ件数を表示します。

## タグ別ページ一覧

```text
/tags/diary
```

対象タグを持つ公開ページを表示します。

## draftページ

`draft: true` のページはタグ一覧とタグ別ページ一覧に出ません。

## 日本語タグ

日本語タグはURLエンコードされたURLになる場合があります。画面上の表示は元のタグ名です。
