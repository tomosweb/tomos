# Theme Platform v1 多言語ページ契約

Tomosは翻訳本文を生成しません。Markdownに言語情報を付けて、同じサイト内で複数言語のページを公開するための最小契約を提供します。

## サイトの既定言語

`config.php` の `site.language` はサイトの既定言語です。指定しないページの言語に使われます。既定値は `ja` で、既存サイトとの後方互換を維持します。

言語値はBCP 47に沿った短い言語タグを使います。たとえば `ja`、`en`、`en-US`、`pt-BR`、`zh-Hans` を指定できます。制御文字、空白、引用符、HTMLやパスとして解釈できる値は受け付けません。

## ページの言語

Markdownのfrontmatterに `language` を指定できます。

```yaml
---
title: What is Tomos?
language: en
---
```

ページのeffective languageは、ページの `language`、未指定なら `site.language` の順で決まります。テーマはfallbackを実装せず、coreが渡す `page.language` を使います。

既存Markdownに `language` がなくても、従来どおりサイトの既定言語で公開されます。

## URLと複数言語ページ

Tomosは言語によるURL prefixを強制しません。`/about/` と `/en/about/` のどちらも利用できます。言語はURLから推測せず、frontmatterとサイト設定を正本にします。

同じサイト内に異なる言語のMarkdownを置けます。たとえば `/about/` を `ja`、`/en/about/` を `en` として公開できます。Tomosは両ページが翻訳関係にあるか、どのページへ言語切替するかを管理しません。必要なリンクは通常のnavigation、Markdown、またはテーマで用意してください。

ページ間のtranslation relation contractを持たないため、自動的なhreflangは提供しません。

## HTML lang

標準テーマは `<html lang="{{ page.language }}">` を使います。`page.language` はcoreが常にeffective languageとして提供するため、テーマ側でsite設定へのfallbackを実装する必要はありません。

## SitemapとRSS

公開されている各言語ページは通常のページとしてSitemapに収録されます。たとえば `/about/` と `/en/about/` がどちらも公開ページなら、両方が対象です。language-specific Sitemapやalternate要素は作りません。

RSSの言語はサイト単位の既定言語を維持します。記事ごとの言語属性をRSSへ追加することや、RSSで翻訳を生成することは行いません。

## 非対象

Tomosは自動翻訳、翻訳API、翻訳管理UI、翻訳関係の管理、翻訳状態の同期、翻訳レビューworkflowを提供しません。Tomosが提供するのは、ページのlanguage metadataと、それに基づく正しい公開表示です。

管理画面全体の翻訳や、Setup・Post・Updateの完全なi18nもこの契約の対象外です。SetupとPost settingsではサイトの既定言語を設定できます。
