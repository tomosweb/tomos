# setup

Tomos はブラウザから初期設定を行うための `setup/` 画面を持ちます。

## アクセス

```text
/setup/
```

初回設置では `config.php` をアップロードしません。`config.sample.php` はアップロードして構いません。

`config.sample.php` が存在していても、`config.php` が存在しなければ setup は初回設置としてフォームを表示します。setup 無効化判定で見るのは `config.php` の実在です。

`config.php` が存在しない場合は setup 画面を表示できます。

`config.php` が存在しない状態でトップページへアクセスした場合、Tomos は通常ページを表示せず相対URL `setup/` へ自動的に移動します。`site.url`、`base_path`、`public_base_path` はsetup前なので使いません。`/setup/` 自体ではリダイレクトループしません。

既存の `config.php` が存在し、`setup_completed` が未定義の場合、Tomos は安全側に倒して setup を無効化します。

`setup_completed` が `true` の場合も setup 画面は無効になります。`security.disable_setup_after_install` が `true` の場合は、`setup_completed` の値にかかわらず setup を無効化します。

setupを再実行したい場合は、明示的に `setup_completed => false` を設定し、`security.disable_setup_after_install` を `false` にしてください。公開環境では、setup完了後に `setup/` ディレクトリを削除してください。

設置後にテーマだけを変更する場合、`setup/` は使いません。`/post/` のサイト設定から有効テーマを選びます。テーマ変更にも管理用合言葉を使います。

## 設定できる項目

- `site.name`
- `site.description`
- `site.url`（setupを開いたURLから自動設定）
- `site.base_path`（`site.url`から自動生成）
- `site.public_base_path`（通常は空。特殊なproxy構成でconfig.phpを直接編集する場合のみ使用）
- `site.language`
- `site.timezone`
- `theme.name`
- `features.search`
- `features.tags`
- `features.rss`
- `features.sitemap`
- `features.html_cache`
- `features.post`

`allow_raw_html` などの危険な設定は setup 画面では変更できません。

## 自動設定されるURL

### サイトURL

setupは、setup画面を開いたURLから公開URLを自動取得します。たとえば `/tomos/setup/` から開始した場合は `https://example.com/tomos` が保存されます。通常のsetupでは入力する必要はありません。

### base_path

`base_path` は自動取得したサイトURLのpathから生成されます。独自ドメイン直下では空になり、`https://example.com/tomos/` では `/tomos` になります。通常のsetup画面には表示されません。

これはサーバー内の実パスではありません。特殊なproxy構成で上書きが必要な場合だけ、生成後の `config.php` を確認してください。

### public_base_path

通常のsetupでは空で保存されます。特殊なproxy構成などで、HTMLに出力するURLパスだけを補正したい場合に限り、生成後の `config.php` で指定します。よく分からない場合は空のままにしてください。

### .htaccess

Tomosで下層ページや検索を表示するために必要なファイルです。不可視ファイルとしてアップロードされない場合があります。

### テーマ

テーマは、サイトの見た目を決めるファイル一式です。

setup画面では、`themes/` フォルダ内のテーマを検証し、有効なテーマだけを選択できます。通常は標準テーマ `tomos-minimal` のままで使えます。

無効なテーマは選択できません。`theme.json` がない、必須テンプレートが足りない、PHPファイルが含まれているなどの理由がsetup画面に表示されます。

テーマは `themes/` フォルダに置きます。テーマアップロード機能は現時点ではありません。

### HTMLキャッシュ

通常は有効のままで構いません。Markdown変換後の本文HTMLを `cache/html/` に保存して表示を軽くします。

`cache/html/` に書き込みできない場合でも、ページ表示自体は継続されます。ただし、表示速度改善のためには書き込み可能にしてください。

### Tomos Post

Tomos Postは、Tomos Writeなどで作成したMarkdownファイルを `/post/` から投稿するための最小機能です。

Tomos Postを有効にすると、setup完了時に管理用合言葉が一度だけ表示されます。この合言葉はあとから画面では確認できません。安全な場所に控えてください。

## setup完了後

保存に成功すると `config.php` が生成または更新され、`setup_completed` が `true` になります。

Tomos Postを有効にした場合は、完了画面に表示される管理用合言葉を控えてください。合言葉を忘れた場合は、既存の合言葉を復元せず、`post-reset.enable` を使って新しい合言葉を再発行します。

安全のため、セットアップ完了後は `setup/` ディレクトリを削除してください。

テーマだけを変更したい場合は、`setup/` を再有効化せず、`/post/` のサイト設定を使います。変更できるのは `theme.name` だけです。

記事ファイルを投稿する場合は、setupを再有効化せず `/post/` を使います。Tomos Writeで書き、Tomos Postで投稿し、Tomosで公開ページを確認する流れです。

## 環境チェック

setup画面では、PHPバージョン、`config.php` の書き込み可否、`content/`、`cache/`、`cache/index/`、`cache/html/`、`themes/`、テーマ検証、標準テーマ、`.htaccess` の存在を確認します。

`.htaccess` が見つからない場合は注意として表示します。setup自体は続行できますが、トップページ以外のURLが Not Found になる可能性があります。
