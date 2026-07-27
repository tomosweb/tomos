# 最初のページ編集

Tomosのページは `content/` のMarkdownファイルです。

## トップページ

```text
content/index.md
```

このファイルを編集するとトップページが変わります。

## このサイトについて

```text
content/about.md
```

URLは `/about` です。

## 日記ページ

```text
content/diary/index.md
content/diary/2026-07-05.md
```

`content/diary/index.md` は `/diary/`、`content/diary/2026-07-05.md` は `/diary/2026-07-05` として表示されます。

## 下書き

frontmatter に `draft: true` を書くと公開されません。

```markdown
---
title: 下書き
draft: true
---
```

詳しい記事の書き方は `writing.md` を参照してください。
