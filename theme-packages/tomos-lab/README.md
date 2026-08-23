# Tomos Lab

研究室・研究グループ向けの商用Theme packageと、開発・制作・QA用sample siteを同じ単位で管理します。

```text
tomos-lab/
├── package/       # 配布Theme ZIPの正本
└── sample-site/   # 人間判断・改修・回帰確認用の基準サイト
```

## package

`package/`だけをTheme ZIP化します。sample-siteは配布ZIPへ含めません。

## sample-site

`sample-site/`はテーマ改修時の基準fixtureです。Hero、News、Research、Members、Publications、About、Contact、日本語/英語ページを含みます。

制作時はsample-siteをTomosサイトのルートへ配置して初期状態を作り、研究室固有の情報へ差し替えます。

人間判断の主な場所は次の通りです。

- Theme制作者: `package/templates/` と `package/assets/`
- サイト構築担当: `sample-site/theme-settings.php`、`sample-site/theme-assets/`、初期Markdown
- 研究室の日常更新: `content/` Markdown / Tomos Post

sample-siteは販売デモだけではなく、レスポンシブ、Home News、長文、一覧、画像、日本語/英語を改修後に確認するための基準状態として維持します。
