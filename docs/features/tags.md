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
