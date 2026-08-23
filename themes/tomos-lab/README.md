# Tomos Lab

研究室・研究グループ向けの商用サイト制作を想定したTomos Theme Platform用テーマです。

## 位置づけ

- Theme Platform Phase 6の商用検証テーマ
- Research / Members / Publications / About / Accessは通常Markdown
- Home Newsは既存の`home.*` APIを利用
- Hero / logo / key colorは`theme-settings.php`と`theme-assets/`でサイト固有化
- テーマ内PHPなし
- Markdown解析、公開判定、URL生成、ページ探索はTomos coreへ委譲

## 想定サイト構成

```text
Home
Research
Members
Publications
News
About
Access / Contact
```

Homeは次の構成を基本とします。

```text
Hero
index.md本文
主要ページ導線
News
```

主要ページ導線は`nav.primary_items`を使うため、テーマ側に研究室固有URLを固定しません。

## Site-specific settings

このテーマZIPには`theme-settings.php`や`theme-assets/`を含めません。制作者はサイト側でHero、logo、key color、News設定を構成してください。

## Commercial workflow

制作中は同じtheme ID (`tomos-lab`) のZIPをTomos Postから繰り返し投入できます。同versionの再投入も制作調整用途として許容されます。公開後のテーマ更新も同じ経路を使います。

Theme ZIP更新でサイト固有の`theme-settings.php`、`theme-assets/`、`content/`、`config.php`を上書きしないことが前提です。

## Language

`<html lang="{{ page.language }}">`を使用します。日本語・英語ページを同じテーマで共存できますが、翻訳関係やhreflangはテーマ側で推測しません。
