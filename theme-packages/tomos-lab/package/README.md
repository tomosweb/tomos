# Tomos Lab package

研究室・研究グループ向けの商用Tomos Theme packageです。

この`package/`だけが配布Theme ZIPの正本です。`sample-site/`は開発・制作・QA用であり、Theme ZIPには含めません。

- Research / Members / Publications / About / Accessは通常Markdown
- Home Newsは既存の`home.*` APIを利用
- Hero / logo / key colorはサイト側の`theme-settings.php`と`theme-assets/`で設定
- テーマ内PHPなし
- Markdown解析、公開判定、URL生成、ページ探索はTomos Coreへ委譲

制作中は同じtheme ID (`tomos-lab`) のZIPをTomos Postから繰り返し投入できます。同versionの再投入も制作調整用途として許容されます。公開後のテーマ更新も同じ経路を使います。

Theme ZIP更新ではサイト固有の`theme-settings.php`、`theme-assets/`、`content/`、`config.php`を上書きしません。
