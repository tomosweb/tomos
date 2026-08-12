# 検索

Tomos の検索は、DBを使わず `cache/index/pages.json` を基礎データにした簡易検索です。JavaScriptは必須ではありません。

## 検索ページ

```text
/search/
```

## 検索結果

```text
/search/?q=キーワード
```

## 検索対象

検索対象は公開ページのみです。

- `title`
- `description`
- `excerpt`
- `tags`
- `url`
- `path`
- `search_text`

`search_text` は Markdown本文からfrontmatterを除き、Markdown記法を軽く取り除いた検索用テキストです。

## draftページ

`draft: true` のページは検索対象外です。

## 検索方式

MVPでは簡易的な部分一致検索です。複数語が入力された場合は、すべての語を含むページを検索します。

## 注意

- DBは使いません
- JavaScriptは必須ではありません
- 検索語は最大100文字に制限されます
- 検索語はHTMLエスケープして表示されます
