# テーマ検証

Tomos はテーマを core から分離し、テーマをHTML/CSS中心の表示レイヤーとして扱います。

`ThemeValidator` は、テーマが安全に読み込めるかを確認する仕組みです。setup画面ではこの結果を使い、有効テーマだけを選択できるようにします。

## 必須ファイル

MVPでは、テーマに以下のファイルが必要です。

```text
theme.json
templates/layout.html
templates/page.html
templates/list.html
assets/style.css
```

`templates/home.html` は任意です。配置するとトップページだけで使用され、配置しない場合は `templates/page.html` にフォールバックします。任意ファイルであっても、ほかのHTMLテンプレートと同じ安全性チェックを受けます。

## theme.json

`theme.json` はJSONとして妥当である必要があります。

主な項目:

- `name`: テーマディレクトリ名と一致すること
- `display_name`: 空でないこと
- `version`: 空でないこと
- `description`
- `author`
- `supports`: 任意。指定する場合はオブジェクト

外部テーマZIPでは、`theme.json.version` を `1.0.0` のような3要素形式で確認します。この形式検査は `ThemePackagePolicy` が担当し、通常の `ThemeValidator` には追加しません。

## PHPファイル禁止

テーマ内に以下の拡張子のファイルを置いてはいけません。

```text
.php
.phtml
.phar
.php5
.php7
```

テーマはPHPロジックを持たず、ページ解決、Markdown変換、URL生成、タグ、検索、RSS/sitemapは core が担当します。

## 危険記述チェック

テンプレート内で以下を検出します。

エラー:

- `<?php`
- `<?=`
- `javascript:`
- `data:text/html`

警告:

- `<script src=`
- `<script type=`
- `onerror=`
- `onload=`
- `onclick=`

## 検証結果

`ThemeValidator` は以下の形で結果を返します。

```php
[
    'valid' => true,
    'errors' => [],
    'warnings' => [],
    'theme' => [
        'name' => 'tomos-minimal',
        'display_name' => 'Tomos Minimal',
        'version' => '0.1.0',
    ],
]
```

`errors` があるテーマは利用不可です。`warnings` はsetupやテーマZIPの確認画面で注意として表示できます。

## setup画面との関係

ThemeValidator と ThemeRepository の結果は、setup画面のテーマ選択に使われます。

- エラーがあるテーマは選択できません。
- 警告だけのテーマは選択できますが、注意として表示されます。
- PHPファイル混入はエラーです。
- 必須テンプレート不足はエラーです。
- `javascript:` や `data:text/html` などの危険なテンプレート記述はエラーです。
- script要素やイベントハンドラ属性は警告です。

有効テーマが1つ以上あればsetupは続行できます。無効テーマがあっても、そのテーマは選べないだけで、他の有効テーマを使えます。

有効テーマが0の場合、setupは進められません。標準テーマ `tomos-minimal` が含まれているか確認してください。

## よくあるエラーと直し方

### theme.json がない

`themes/テーマ名/theme.json` を追加してください。

### theme.json の name とフォルダ名が違う

`theme.json` の `name` をテーマフォルダ名と一致させてください。

例:

```text
themes/tomos-journal/
theme.json の name: tomos-journal
```

### 必須テンプレートがない

次のファイルを置いてください。

```text
templates/layout.html
templates/page.html
templates/list.html
```

### assets/style.css がない

`assets/style.css` を追加してください。空に近い内容でも、テーマのCSS入口として必要です。

### PHPファイルが入っている

テーマ内から `.php`, `.phtml`, `.phar`, `.php5`, `.php7` を取り除いてください。

テーマは見た目を変えるためのものです。サーバー上でプログラムを実行するためのものではありません。

### テンプレートに危険な記述がある

`<?php`, `<?=`, `javascript:`, `data:text/html` は使わないでください。

`<script src=`, `<script type=`, `onerror=`, `onload=`, `onclick=` は警告対象です。標準的なテーマでは使わないでください。

## 推奨アセット

以下は推奨アセットです。存在しない場合、テーマは無効にはなりませんが警告対象です。

```text
assets/favicon.svg または assets/favicon.png
assets/apple-touch-icon.png
assets/ogp.png
```

SVGファイルがある場合、`<script>` や外部参照が検出されるとエラーになります。

## 外部テーマZIPの追加検査

Tomos Postから追加するテーマZIPは、通常の `ThemeValidator` に加えて `ThemePackagePolicy` と `ThemePackageInstaller` で検査します。

テーマとして動作するために必須なのは、通常の `ThemeValidator` と同じ5ファイルです。

```text
theme.json
templates/layout.html
templates/page.html
templates/list.html
assets/style.css
```

次の3件は配布時の推奨ファイルです。個人制作テーマへのアップロード必須条件にはしません。

```text
preview.png
README.md
LICENSE
```

不足時はエラーではなくwarningを返し、確認画面に表示したうえで追加を許可します。`preview.png` が存在する場合は、PNG画像として読み取れることを確認します。

Finder等で作成したZIPに含まれる次のmacOSメタデータは、ZIPの安全性検査後に無視し、展開・テーマID判定・ファイル数・展開後容量の対象にしません。

```text
__MACOSX/
.DS_Store
._*
```

無視対象はこの3種類に限定します。`../`、絶対パス、バックスラッシュ、NUL・制御文字、不正UTF-8、PHP、JavaScript、symlink、特殊ファイル、実行可能ファイル、許可外拡張子、VCSメタデータ等は拒否します。

処理は `/post/theme/add/` でのアップロード・検査と、`/post/theme/add/confirm/` での確定追加に分かれます。ZIPは `storage/theme-upload-tmp/` で検査され、確定時は `themes/` 内のdot始まりのstagingで再検証してから、新しいテーマIDへrenameされます。既存テーマの上書きや自動的な有効化は行いません。

ファイル許可リスト、ZIP上限、パス検査、一時データとlockの詳細は[外部配布テーマ仕様](external-theme-distribution-spec.md)を参照してください。
