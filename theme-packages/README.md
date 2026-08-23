# Theme Packages

このフォルダは、Tomos本体に標準同梱しない配布用テーマの正本を管理します。

## Folder responsibility

- `themes/`: Tomos本体に標準同梱するruntimeテーマ。Tomos distribution ZIPへ含める。
- `theme-packages/`: ダウンロード配布、商用提供、個別導入を想定するテーマ。Tomos本体distribution ZIPへ含めない。

`theme-packages/<theme-id>/` はTheme ZIPを生成するためのパッケージソースです。利用者サイトへはTomos Postの「テーマZIPを追加・更新」から導入します。

## Rules

1. 配布用・商用テーマを`themes/`へ置かない。
2. `theme-packages/`をTomos本体distributionへコピーしない。
3. Theme Packageは`theme-settings.php`、`theme-assets/`、`content/`、`config.php`を含めない。
4. Theme Package内にPHPを含めない。
5. Theme versionはTomos core versionと独立して管理する。
6. 制作中の同version再投入と、公開後のversion更新はいずれもブラウザのTheme ZIP deploymentを使う。

現在の商用検証テーマ:

- `tomos-lab/`
