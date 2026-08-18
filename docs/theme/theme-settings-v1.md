# Tomos サイト固有テーマ設定 v1

対象: Phase 2 / Gate 2

## 目的

テーマ本体と、サイトごとに異なる低頻度設定を分離する。

- theme package: `themes/<theme-id>/`
- site-specific settings: `theme-settings.php`
- site-specific assets: `theme-assets/`
- daily content: `content/` Markdown

`theme-settings.php` と `theme-assets/` はtheme ZIPへ含めない。通常のTomos Updateでサイト固有値を上書きしない。

## 想定運用

一般利用者がTomos Postから編集する設定ではない。初期構築やデザイン調整を行う制作者・保守担当者がFTP/SFTP等で配置する。

```text
Tomos/
├── theme-settings.php
├── theme-assets/
│   ├── hero.jpg
│   └── logo.svg
├── themes/
└── content/
```

## 設定例

```php
<?php
return [
    'hero' => [
        'enabled' => true,
        'image' => 'hero.jpg',
        'title' => 'Exploring the Future of Materials',
        'subtitle' => 'Example Research Group',
        'button_label' => 'Our Research',
        'button_url' => '/research/',
    ],
    'news' => [
        'enabled' => true,
        'path' => '/news/',
        'limit' => 5,
        'heading' => 'NEWS',
        'more_label' => 'View all',
    ],
    'design' => [
        'logo' => 'logo.svg',
        'key_color' => '#174467',
    ],
];
```

## v1で認識するキー

### hero

- `enabled`: bool。未指定時false
- `image`: `theme-assets/`からの相対パス
- `title`: text
- `subtitle`: text
- `button_label`: text
- `button_url`: Tomos内のURL pathのみ

### news

Phase 3の構造化News APIが使用する。

- `enabled`: bool。未指定時true
- `path`: News source path。未指定・不正時 `/news/`
- `limit`: 1〜10。未指定・不正時5
- `heading`: 未指定時 `NEWS`
- `more_label`: 未指定時 `View all`

`path` と `limit` は取得条件であり、template公開変数にはしない。

### design

- `logo`: `theme-assets/`からの相対パス
- `key_color`: `#RRGGBB` のみ

## template API

Phase 2で次の通常変数を追加する。

```text
theme.hero_enabled
theme.hero_image_url
theme.hero_title
theme.hero_subtitle
theme.hero_button_enabled
theme.hero_button_label
theme.hero_button_url
theme.logo_url
theme.key_color
theme.news_enabled
theme.news_heading
theme.news_more_label
```

これらは `{{ variable }}` で利用する。新しいraw HTML変数は追加しない。

URL系は既存のTemplateRenderer URL安全化を通す。

## asset規則

`theme-assets/`で許可する初期対象:

- `.png`
- `.jpg`
- `.jpeg`
- `.webp`
- `.svg`

相対パスのみ許可し、`../`、絶対パス、危険文字を拒否する。実ファイルが`theme-assets/`内に存在することを確認する。

SVGは直接公開されるため、script、event handler、外部HTTP参照、`javascript:`、`data:text/html`参照を含むものを採用しない。

## theme-settings.php の安全境界

`theme-settings.php` は管理者が配置するPHP設定ファイルであり、theme packageではない。theme内PHP禁止ルールの例外ではなく、Tomosルートのサイト設定として扱う。

`.htaccess`でHTTP直接取得を拒否する。

読み込み時は次を行う。

- fileなし: defaults
- 読み込み不能: defaults
- PHP parse/runtime error: defaults
- array以外をreturn: defaults
- 未知group/key:無視
- 型不正:安全なdefault

なおPHPファイルであるため、サーバーへ書き込める管理者が任意コードを記述できる点は`config.php`等と同じ信頼境界である。第三者theme ZIPからこのファイルを書き込ませない。

## Update境界

`theme-settings.php` と `theme-assets/` はTomos配布物へ同梱しない。

Tomos Updateで更新するのはPhase 2を実行するcoreと`.htaccess`であり、サイト固有設定・画像は保持する。

## 後方互換

- `theme-settings.php`なしで従来通り動作
- `theme-assets/`なしで従来通り動作
- 新APIを使わない既存themeは変更不要
- 既存themeのHTML/CSSを自動変更しない
- theme ZIP仕様を緩和しない
- theme内PHP禁止を維持

## Phase 3への引き継ぎ

Phase 3は `ThemeSettings::settings()` の `news` groupから `enabled` / `path` / `limit` を取得し、MetadataIndexを用いて `home.news_items` 等を構築する。
