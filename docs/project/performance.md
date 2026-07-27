# パフォーマンス計測

この文書はプロジェクト管理者向けの内部確認メモです。

Tomos はDBを使わないため、通常表示では `cache/index/pages.json` と `cache/index/link-aliases.json` を利用します。これらが存在する場合、通常記事表示のたびに `content/` 全体を走査しません。

## 通常表示の方針

- `pages.json` と `link-aliases.json` がある場合は、それらを読み込みます。
- 通常記事のHTMLキャッシュが有効な場合、本文Markdownの読み込み、Markdown変換、Wikiリンク変換を省略します。
- HTMLキャッシュがない記事だけ、その記事のMarkdownを読み込んで本文HTMLを生成します。
- 検索、タグ、RSS、sitemapは、それぞれのURLへアクセスした時だけ生成します。

## FTP追加時の考え方

通常表示を軽く保つため、FTPでMarkdownを追加・削除・リネームしたかどうかを毎回自動走査しません。

FTPでMarkdownを追加した後、一覧、検索、タグ、Wikiリンクが古い場合は、インデックスキャッシュを再生成してください。インデックス再生成時には本文HTMLキャッシュも再生成対象になります。

Tomos Postから投稿・取り下げを行う場合は、必要なキャッシュ削除をTomos側で行います。

## 大量Markdownと画像削除判定

Tomos Postで投稿・更新・取り下げを行う場合、画像参照索引を更新するため公開中のMarkdownを走査します。画像削除、共有画像の保持判定、削除再試行では、そのリクエスト内で生成した最新の画像参照索引を共有し、同じMarkdown群を繰り返し走査しません。

画像関連の全走査は1操作につき原則1回です。削除再試行キューが空の場合は、再試行だけを目的とした追加走査を行いません。通常ページ表示では従来どおり既存インデックスを利用します。

5000件のMarkdownを使う規模テスト:

```text
php tests/image_reference_scale_check.php
```

テスト結果にはファイル数、処理秒数、PHPのピークメモリを表示します。共有画像が保持され、未参照画像が最新索引を再利用して削除されることも確認します。

## 計測ログ

`debug.performance_log` を有効にすると、簡易ログを `cache/logs/performance.log` に出力します。

ログ例:

```text
[2026-07-11 10:20:15] path=/2026/sample route_resolve_ms=0 metadata_index=load pages_json_load=1 pages_ready_ms=2 html_cache=hit html_cache_check_ms=1 markdown_render=skipped wiki_link_parse=skipped theme_render_ms=8 total_ms=12
```

主な項目:

- `metadata_index=load`: 既存のインデックスを読み込んだ
- `metadata_index=rebuild`: インデックスを再生成した
- `html_cache=hit`: 本文HTMLキャッシュを利用した
- `html_cache=miss`: 本文HTMLを生成した
- `markdown_render=skipped`: Markdown変換を省略した
- `wiki_link_parse=skipped`: Wikiリンク変換を省略した

ログには本文全文、管理用合言葉、認証情報を出しません。ログ出力に失敗してもページ表示は止めません。

## 実地確認記録

実施環境:

- さくらのレンタルサーバ スタンダード
- Tomos 第21.8.6対応後

投入件数:

- 3000件弱のMarkdown

確認結果:

- フォルダ階層
- 数字フォルダ
- 日本語・記号入りファイル名
- Obsidian Wikiリンク
- 後から追加したリンク先の再解決
- `pages.json`
- `link-aliases.json`
- HTMLキャッシュ
- 通常ページ表示

評価:

- 通常表示は実用上問題なし
- ページ遷移時にごくわずかな引っ掛かりを感じる場合はあるが、気になる水準ではない

運用整理:

- 初期移行時はFTPで一括投入
- 通常運用はTomos Postを基本とする
