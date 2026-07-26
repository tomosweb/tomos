# RSS / sitemap

Tomos は `cache/index/pages.json` を基礎に、公開ページ向けのRSSとsitemapを生成します。

## RSS

```text
/feed.xml
```

`/rss.xml` も `/feed.xml` と同じRSSとして扱います。

標準では、下書きを除くすべての公開ページをRSSに含めます。Tomos Postの「サイト設定」でRSS対象パスを空欄にすると、すべての公開ページが対象になります。`/diary` などを指定すると、その配下の記事だけを対象にできます。

## sitemap

```text
/sitemap.xml
```

## URL生成

RSSとsitemap内のURLは `site.url` から絶対URLを生成します。

例:

```php
'url' => 'https://tomoswords.org',
```

```text
/about
=> https://tomoswords.org/about
```

HTML内のRSSリンクは、通常の画面リンクと同じく `public_base_path` を使います。対応テーマでは、ブラウザやRSSリーダーが見つけられる `<link rel="alternate">` に加えて、ナビゲーションにもRSSリンクを表示します。

## draftページ

`draft: true` のページはRSSとsitemapに含めません。

## 日付

RSSの `pubDate` は `date`、`updated`、`mtime` の順に利用します。

sitemapの `lastmod` は `updated`、`date`、`mtime` の順に利用します。

日付が解釈できない場合は、その項目を省略します。

## 設定

Tomos Postの「サイト設定」から、RSSとSitemapの有効・無効、RSS対象パスを変更できます。

RSSを無効にすると `/feed.xml` と `/rss.xml` は公開されず、標準テーマのRSSリンクとRSS discovery linkも表示されません。RSS対象パスはRSSだけに適用され、Sitemapの対象ページは変更しません。

Sitemapを無効にすると `/sitemap.xml` は公開されません。Sitemapの無効化は、検索エンジンへの登録を拒否する機能ではありません。Tomos Postには、noindexやrobots.txtを設定する機能はありません。
