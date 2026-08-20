# Tomos Theme Contract v1

対象: Tomos v0.2.0以降
策定日: 2026-08-18
## 現在位置

- Phase 1 / Gate 1
- 目的: 独自テーマを安全に配布・派生・将来公開するための互換性契約を固定する
- 非対象: サイト固有ThemeSettings、News API、公式サイト適用、研究室向けテーマ制作

## 基本契約

Tomosテーマは表示層である。

coreが担当するもの:

- Markdown変換
- 公開判定
- URL解決
- navigation / index生成
- セキュリティ
- template context生成

テーマが担当するもの:

- HTML構造
- CSS
- 静的asset
- `theme.json`によるtheme package metadata

テーマ内PHPは禁止する。テーマがcoreの責務を再実装しない。

## theme.json v1

最小例:

```json
{
  "name": "my-theme",
  "display_name": "My Theme",
  "version": "1.0.0",
  "description": "Example theme for Tomos.",
  "author": "Example Author",
  "supports": {
    "navigation": true,
    "responsive": true
  }
}
```

新しいTomos APIを必須とするテーマは、最低Tomosバージョンを追加する。

```json
{
  "name": "my-theme",
  "display_name": "My Theme",
  "version": "1.0.0",
  "description": "Example theme for Tomos.",
  "author": "Example Author",
  "requires_tomos": "0.2.0",
  "supports": {
    "navigation": true,
    "responsive": true
  }
}
```

## metadata

### name

テーマID。`themes/<name>/`のdirectory名と完全一致させる。

許可形式:

```text
[A-Za-z0-9_-]+
```

標準テーマを派生する場合は必ず別の`name`へ変更する。

### display_name

利用者向け表示名。空文字不可。

### version

テーマ自身のversion。外部配布ZIPでは3要素SemVerを使用する。

例:

```text
1.0.0
1.2.3
```

Tomos本体versionとは独立して管理する。

### description

テーマの用途を説明する短い文章。

### author

テーマ作者・組織名。

### requires_tomos

任意。テーマが動作するために必要な最低Tomosバージョン。

v1ではversion range式を採用しない。

許可例:

```text
0.1.0-beta.1
0.1.0-beta.2
0.2.0
1.0.0
```

不許可例:

```text
>=0.1.0-beta.2
^0.1.0
0.1.x
```

理由:

- FTP/SFTPで扱う設定を人が読んで理解しやすい
- PHP標準の`version_compare()`で一意に判定できる
- dependency solverをTomosへ持ち込まない
- 将来Theme Directoryで「必要なTomos」を明示しやすい

`requires_tomos`がない既存テーマは互換扱いとする。

現在のTomosが`requires_tomos`未満の場合、テーマは検証不合格となり、setup・テーマ切替・テーマZIP追加の通常経路で利用可能なテーマとして扱わない。

### supports

任意のmetadata object。

`supports`は「テーマがその表示に対応していることを作者が宣言する情報」であり、Tomos機能をON/OFFする設定ではない。

例:

```json
"supports": {
  "navigation": true,
  "breadcrumbs": true,
  "tags": true,
  "search": true,
  "rss": true,
  "favicon": true,
  "ogp": true,
  "responsive": true
}
```

原則:

- coreのfeature flagを変更しない
- runtime capability判定の代用にしない
- 未知のkeyがあってもcore機能を自動実行しない
- Theme Directory等で表示用metadataとして利用できる

Phase 2以降の新しいtheme context APIを必須利用する場合、互換性は`supports`ではなく`requires_tomos`で宣言する。

## 互換性判定

判定式:

```text
current_tomos >= requires_tomos
```

`requires_tomos`未指定:

```text
compatible
```

`requires_tomos`指定時にTomos本体versionを確認できない場合:

```text
incompatible / fail closed
```

互換性エラーでは必要versionと現在versionを利用者へ示す。

## VERSION探索

通常環境ではテーマdirectoryの親側にあるTomos本体`VERSION`を用いる。

テーマZIP検査は`storage/theme-upload-tmp/.../extracted/`で行われるため、ThemeValidatorは検査directoryから親方向へTomos本体`VERSION`を探索する。

これにより同じThemeValidator契約を次で共有する。

- setup
- Tomos Postテーマ一覧
- テーマ切替
- テーマZIP検査

互換性判定専用の別経路を増やさない。

## 既存テーマとの後方互換

既存の標準6テーマには`requires_tomos`を追加しない。

したがってPhase 1導入時点では標準テーマのtheme packageを書き換えず、従来theme.jsonもそのまま有効とする。

既存の外部テーマも`requires_tomos`を持たない限り従来と同じ検証を通る。

## 標準テーマの派生

標準テーマは一般利用者向け完成品であると同時に、技術者向けのsample implementationでもある。

標準テーマを独自改修して継続利用する場合の正式な推奨手順:

1. `themes/tomos-minimal/`等をコピーする
2. directoryを独自IDへ変更する
3. `theme.json.name`を同じ独自IDへ変更する
4. `display_name`、`version`、`description`、`author`を派生テーマの内容へ変更する
5. Tomosの新APIを必須利用する場合だけ`requires_tomos`を指定する
6. HTML/CSS/assetsを改修する
7. ThemeValidatorまたはTomos PostのテーマZIP検査を通す
8. 元の標準テーマとは別テーマとして共存させる

例:

```text
themes/
├── tomos-minimal/
└── planuk-lab/
```

標準テーマIDをそのまま直接恒久改造することは推奨しない。通常Tomos Updateでは公式標準テーマIDが更新対象となるためである。

独自IDへ派生したテーマは通常Tomos Updateの標準テーマ更新対象に含めない。

## theme versionとTomos version

二つを混同しない。

```text
theme.json.version = テーマ自身のrelease version
theme.json.requires_tomos = 必要なTomos本体の最低version
```

テーマ側にTomosと同じversion番号を機械的に付ける必要はない。

## 標準テーマセットのversion方針

Tomos本体に同梱する次の6テーマは、原則として一つの標準テーマセットとして管理します。

- `tomos-minimal`
- `tomos-note`
- `tomos-90s`
- `tomos-dark`
- `tomos-journal`
- `tomos-blog`

Phase 5-Cで確定した標準テーマセットのversionは次のとおりです。

```text
tomos-minimal   1.2.0
tomos-note      1.2.0
tomos-90s       1.2.0
tomos-dark      1.2.0
tomos-journal   1.2.0
tomos-blog      1.2.0
```

`1.2.0`は標準テーマセットの世代を表す共通baselineです。各テーマのデザイン、用途、使用APIが同一になることを意味しません。

今後、標準テーマセットに共通する変更をリリースする場合も、原則として6テーマへ同じversionを付与します。個別テーマだけに独立した重大リリースが必要になった場合は、その時点で別途判断します。

`theme.json.version`と`requires_tomos`は独立して管理します。`requires_tomos`はTomos本体の最低必要versionであり、標準テーマセットのversionを代用しません。今後も標準テーマセットの共通変更では、原則として6テーマへ同じversionを付与します。

## license / homepage / preview等

`author`以外の配布サイト向けmetadataとして、将来次を検討できる。

- license
- homepage
- repository
- preview

ただしPhase 1ではruntime契約へ追加しない。Theme Directory実装前のPhase 7で、実際の配布要件に基づき確定する。

metadataを先回りして増やしすぎない。

## ThemeSettingsとの境界

次はtheme package metadataではないため`theme.json`へ入れない。

- Hero画像
- Heroコピー
- ロゴ
- key color
- News表示ON/OFF
- News path
- News件数

これらはPhase 2のサイト固有`theme-settings.php`で扱う。

`theme.json`は配布されるテーマそのものの契約、`theme-settings.php`は設置サイト固有の初期設定という境界を維持する。

## Gate 1受け入れ条件

1. `requires_tomos`なしの既存テーマが従来通り有効
2. 現在version以下を要求するテーマが有効
3. 将来versionを要求するテーマが明確な理由付きで無効
4. range式等の曖昧な`requires_tomos`を拒否
5. theme ZIP検査でも同じThemeValidator契約を使う
6. 標準6テーマを変更しない
7. `supports`をfeature switchとして扱わない
8. 標準テーマ派生時は別theme IDを正式推奨とする
9. 独自theme IDは通常Updateの標準テーマ更新と混線しない

これらの自動テストと既存回帰が成功した時点でGate 1をPASSとする。
