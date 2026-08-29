# Tomos テーマ基盤拡張 Phase 0 監査

対象: Tomos v0.1.0-beta.1 / main
監査日: 2026-08-18
開発計画: `docs/theme-platform-development-plan.md`

## 現在位置

- Phase 0 / Gate 0
- 目的: 現行テーマ基盤を監査し、今後の拡張境界とテーマAPI v1の契約を固定する
- 非対象: ThemeSettings実装、News API実装、公式サイト改修、研究室向けテーマ制作

## 結論

現行Tomosのテーマ基盤は、今回の拡張を追加する土台として利用できる。大きな作り直しは不要である。

維持する責務:

- Markdown変換、公開判定、URL生成、安全化はcoreが担当する
- themeはHTML/CSSによる表示を担当する
- theme内PHPは禁止する
- `theme.json` はtheme package自体のmanifestとする
- theme追加は新規IDのみとし、既存themeを上書きしない
- 標準themeと独自themeを同一`themes/`配下で共存させる
- 通常のTomos Updateは公式標準themeだけを更新対象にできる
- 利用者が追加した独自themeは通常Update対象にしない

今回の拡張はこの境界を壊さず、coreからthemeへ渡せる安全なデータを増やす。

## 現行テーマ構成

標準themeは次の6件。

- `tomos-minimal`
- `tomos-journal`
- `tomos-dark`
- `tomos-note`
- `tomos-blog`
- `tomos-90s`

runtime必須ファイル:

- `theme.json`
- `templates/layout.html`
- `templates/page.html`
- `templates/list.html`
- `assets/style.css`

`templates/home.html` は任意。現行標準themeでは `tomos-blog` のみが持つ。

`home.html` が存在する場合、サイトトップ `/` だけで自動使用される。存在しない場合は `page.html` へフォールバックする。よってトップ専用template機構の新設は不要。

## TemplateRendererの現行契約

現行Rendererは次を備える。

- 通常変数のHTML escape
- allowlist済みraw HTML変数
- section構文
- 配列section反復
- URL変数の安全化
- `/` での `home.html` 自動選択

構造化News APIは既存の配列sectionを利用する。News HTMLをcore側で完成させて渡す方式は採用しない。

## theme package境界

現行ThemePackagePolicyの主な制約:

- ZIP 10 MiB
- 展開後合計 30 MiB
- 1ファイル 5 MiB
- 最大200 entry
- 最大directory深度4
- templateは `layout.html` / `page.html` / `list.html` / `home.html` を許可
- PHP、JavaScript、危険path、symlink、許可外ファイルを拒否

この安全境界はPhase 0では変更しない。

## theme.jsonの現行責務

現行metadata:

- `name`
- `display_name`
- `version`
- `description`
- `author`
- `supports`

`theme.json.name` はtheme directory名と一致する必要がある。外部配布theme ZIPのversionは3要素SemVer。

`theme.json` はtheme packageのmanifestであり、サイトごとのHeroコピー、Hero画像、ロゴ、News表示件数等は入れない。

互換性metadata `requires_tomos` 等の採否はPhase 1で決定する。

## theme追加・切替

Tomos Postからtheme ZIPを新規追加できる。確定時にも再検証する。同一theme IDの上書き、theme ZIPによる既存theme更新、削除、自動有効化は行わない。

theme切替はThemeValidatorを通した後、`config.php`の`theme.name`だけを変更する。

標準themeを派生する場合は、標準themeを直接恒久改造するより、コピーして別theme IDへ変更する方式を正式な推奨とする。

## Tomos Updateとの境界

現行UpdateServiceがtheme pathとして書換可能にしているのは公式6themeだけである。

したがって `themes/my-company-theme/` 等の利用者独自themeは通常Updateの書換対象にならない。この境界を維持する。

標準themeそのものを直接改変した場合は将来Updateで変更される可能性があるため、独自改修は別IDへの派生を推奨する。

## サイト固有theme設定 v1

Phase 2で実装する名称を固定する。

- `theme-settings.php`: サイト固有かつ低頻度のtheme設定を一箇所に集約
- `theme-assets/`: Hero画像、logo等のサイト固有asset

設定group:

- `hero`
- `news`
- `design`

主な設定:

- Hero: enabled / image / title / subtitle / button_label / button_url
- News: enabled / path / limit / heading / more_label
- Design: logo / key_color

一般利用者向けGUIではなく、制作者・保守担当者がFTP/SFTPで扱う初期設定とする。

## theme context API v1

Phase 2で追加する公開template API:

- `theme.hero_enabled`
- `theme.hero_image_url`
- `theme.hero_title`
- `theme.hero_subtitle`
- `theme.hero_button_enabled`
- `theme.hero_button_label`
- `theme.hero_button_url`
- `theme.logo_url`
- `theme.key_color`
- `theme.news_enabled`
- `theme.news_heading`
- `theme.news_more_label`

`news.path` と `news.limit` は取得条件なので公開template APIには含めない。

## home context API v1

Phase 3で追加するNews用構造化データAPI:

- `home.has_news`
- `home.news_items`
- `home.news_url`

各 `home.news_items` item:

- `date`
- `date_display`
- `title`
- `url`

coreはNewsのHTML構造を決めない。theme側が配列を反復してHTMLを構成する。

## News source v1

- source pathはサイト固有設定で指定
- defaultは `/news/`
- default limitは5
- v1最大limitは10
- 指定path配下の公開済みMarkdownのみ対象
- draft除外
- 既存公開判定と既存published orderを再利用
- News表示のために本文全件を再Markdown解析しない
- 0件なら `home.has_news = false`
- News OFFなら表示しない

研究室固有ロジックはcoreへ持ち込まない。

## index.mdとの関係

トップページ本文は引き続き `content/index.md` を正本とする。HeroやNews APIを導入しても、Research/About等を専用fieldへ移さない。

## 標準themeと独自theme

標準themeには二つの役割を持たせる。

1. 一般利用者がそのまま使えるtheme
2. 詳しい制作者向けのsample implementation

独自theme制作をノーコード化しない。一方、技術的知見のある制作者が標準themeを別IDへ派生して自由に制作できる状態を維持する。

## 将来のTheme Directory

将来のtheme配布サイトを想定するが、Tomos本体を特定配布サイトへ密結合させない。

将来候補metadata: author / license / version / requires_tomos / supports / homepage / preview。

正式contractはPhase 1およびPhase 7で扱う。

## 非対象

- ブラウザHTML/CSS editor
- ノーコードtheme builder
- drag & drop page builder
- theme内PHP
- 研究室専用CMS機能
- Members / Research / Publications専用管理
- News category / thumbnail
- Hero slider / video Hero
- theme自動update
- Theme Directory本体

## documentation drift

`docs/features/themes.md` は現行のTomos Post theme ZIP追加を正しく記載している。一方、`docs/theme/theme-authoring.md` の一部には「テーマアップロード機能はない」「FTPで追加する」という旧仕様が残っている。

現行仕様は次。

- FTP/SFTPによるtheme配置: 利用可能
- Tomos Postからtheme ZIP追加: 利用可能
- 既存theme ID上書き: 不可
- theme ZIPによる既存theme更新: 不可
- 追加直後の自動有効化: しない

古い記述はPhase 5の開発者ドキュメント体系化で整理する。それまでは本監査文書、`docs/features/themes.md`、`docs/theme/external-theme-distribution-spec.md`を現行追加仕様の参照元とする。

## 後方互換条件

1. `theme-settings.php`なしで従来通り動作
2. `theme-assets/`なしで従来通り動作
3. 新しい`theme.*` / `home.*`を使わないthemeは変更不要
4. `home.html`なしなら従来通り`page.html`
5. 既存6標準themeは新API対応を必須にしない
6. 利用者追加themeを通常Tomos Updateで書き換えない
7. theme ZIPの安全規則を緩和しない
8. Markdown正本を維持
9. theme内PHP禁止を維持
10. theme機能のためにDBを追加しない

## Phase 1へ渡す論点

- `requires_tomos`の正式採否
- version range表現
- compatibility error / warning
- `supports`の厳密な意味
- license / homepage等をPhase 1で扱うかPhase 7まで待つか
- 標準theme派生時のID / version / authorガイド

## Gate 0判定

- G0-1 既存6標準theme無変更の前提: PASS
- G0-2 Update / theme追加 / theme切替との非衝突: PASS
- G0-3 研究室固有概念をcoreへ持ち込まない: PASS
- G0-4 API名と設定境界の固定: PASS

Gate 0: PASS

Phase 1「テーマ契約・互換性基盤」へ進める。
