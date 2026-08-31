# SEO Foundation 1.0

Tomosは、Markdownから生成した公開ページを検索エンジン、SNS、RSSリーダーなどが認識しやすいWeb文書として出力します。SEOの値はCoreが正規化し、テーマは `{{{ page.seo_head_html }}}` を `<head>` 内へ配置します。

## titleとdescription

記事・固定ページのtitleは、Front Matterや既存のTomos metadataからCoreが決め、通常は `ページタイトル - サイト名` として出力します。Homeは明示されたFront Matter titleを維持し、titleがない場合はサイト名を使います。

descriptionは次の順で決まります。

1. 空でないFront Matter `description`
2. Markdown本文から生成したexcerpt
3. 空でないサイト説明
4. どれもなければdescriptionタグを出力しない

excerptはMarkdown記法やHTMLを含まないplain textです。

## canonicalとSNS metadata

公開ページにはCoreが生成した絶対URLのcanonicalを出力します。query parameter、fragment、`index.php`などの内部表現はcanonicalへ含めません。

Open Graphは `og:title`、`og:description`、`og:type`、`og:url`、`og:site_name`、`og:image` を出力します。記事の `og:type` は `article`、Home・固定ページ・virtual folderは `website` です。X/Twitter Cardは `summary_large_image` を使い、OGPと同じCore metadataを参照します。

Front Matterの `image` は、文書位置基準またはサイトルート基準のローカル画像、または安全なHTTP/HTTPS画像URLとして利用できます。危険なschemeや存在しないローカル画像は採用せず、テーマの `assets/ogp.png` へfallbackします。

## sitemap、robots、RSS

- `/sitemap.xml` はHome、公開記事、固定ページ、virtual folder indexを含み、draftを除外します。
- sitemapの `lastmod` は `updated`、`date`、`published` の順で、metadataがない場合は省略します。
- `/robots.txt` は基本的な `Allow: /` を返し、sitemapが利用可能なら絶対URLの `Sitemap:` 行を追加します。
- `/feed.xml` と `/rss.xml` はRSS 2.0として維持されます。draftは含めません。

## 見出しと画像

Front Matter titleをページタイトルとして使う場合、本文はH2以降から始めることを推奨します。Tomosは既存互換のため本文H1を自動的にH2へ変更したり削除したりしません。Markdown画像のaltはそのまま保持します。

JSON-LD、Atom、keyword meta、SEOスコア、automatic alt生成、redirect managerなどはSEO Foundation 1.0の対象外です。
