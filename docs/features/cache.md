# HTMLキャッシュ

Tomos は通常Markdownページの本文HTMLを `cache/html/` に保存できます。

## 保存場所

```text
cache/html/
```

`cache/html/` はTomos内部用のキャッシュディレクトリです。HTTP経由で直接読む場所ではありません。

## キャッシュ対象

対象は、通常Markdownページの `page.content` に相当する本文HTMLです。

含まれる処理:

- ページ先頭の重複H1除去
- Obsidian形式画像と通常Markdown画像の安全なHTML化
- Wikiリンクの安全なHTML化
- Markdown本文のHTML化

対象外:

- `layout.html` を含むページ全体HTML
- ナビゲーション
- パンくず
- タグ一覧
- 検索結果
- RSS
- sitemap
- 404ページ
- setup画面

## 再生成

元Markdownファイルの更新日時、ファイルサイズ、キャッシュ仕様バージョンが一致する場合だけキャッシュを使います。

Markdownを更新した場合、次回アクセス時に本文HTMLを再生成します。

`cache/html/` 内の `.html` や `.json` を削除しても、次回アクセス時に再生成されます。

## 書き込みできない場合

`cache/html/` に書き込めない場合でも、ページ表示は継続します。

その場合はキャッシュなしでMarkdown変換を行います。表示速度を安定させるには、`cache/html/` にPHPから書き込めるようにしてください。

## テーマ変更時

HTMLキャッシュは本文HTMLのみを対象とし、テーマのレイアウトHTML全体はキャッシュしません。

テーマ変更時に本文HTMLの再生成が必要な場合は、`cache/html/` 内の `.html` と `.json` を削除してください。
