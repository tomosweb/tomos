# Tomos インストール手順

Tomos は、Zipアップロード方式またはFTPアップロード方式で設置できます。

インストール対象バージョン: `v0.1.0-alpha.15`

> この手順は `v0.1.0-alpha.15` の限定テスト版を設置するためのものです。
> 正式リリース前の確認版のため、既存サイトへ上書きせず、空のテスト用ディレクトリに設置してください。

本番環境で利用する前に、十分な動作確認とバックアップを行い、`DISCLAIMER.md` を確認してください。

すでにTomosを設置している場合は、Zipを既存ルートへ上書き展開せず、[既存環境の更新](docs/install/update.md) に従ってください。

推奨は Zipアップロード方式です。`.htaccess` のアップロード漏れやファイル転送漏れを防ぎやすいためです。

## 事前確認

- 初期設置時点では `config.php` が存在しない状態にします。`config.php` はsetup完了時にサーバー上で生成されます。
- `config.sample.php` はアップロードします。
- `LICENSE`、`NOTICE`、`TRADEMARKS.md` が配布物に含まれていることを確認します。
- `.htaccess` は必ずアップロードします。
- `cache/`、`cache/index/`、`cache/html/` が存在することを確認します。
- `cache/index/pages.json` は配布物に含めません。必要に応じてサーバー上で生成されます。
- `cache/html/*.html` と `cache/html/*.json` は配布物に含めません。通常ページ表示時にサーバー上で生成されます。
- `storage/update-backups/`、`storage/update-logs/`、`storage/update-tmp/` が存在し、PHPから書き込めることを確認します。
- Apache環境では `storage/.htaccess` が存在し、`storage/` へWebから直接アクセスできないことを確認します。
- setup 完了後は `setup/` を削除またはリネームします。
- setup 完了時に管理用合言葉が一度だけ表示されます。あとから画面では確認できないため、安全な場所に控えてください。

## パスキーを利用する場合

Tomos本体はPHP 7.4以上で利用できます。

Tomos Postのパスキー機能を利用する場合は、次の条件が必要です。

- PHP 8.0以上
- OpenSSL
- mbstring
- HTTPS
- WebAuthn対応ブラウザ

これらの条件を満たさない場合も、従来の管理用合言葉認証は利用できます。

WebAuthn runtimeはTomosの配布Zipに同梱されています。利用者がComposerをインストールしたり、サーバー上でComposerを実行したりする必要はありません。

## A. Zipアップロード方式

1. 配布された `tomos-0.1.0-alpha.15.zip` を受け取ります。
2. ホストサービスのファイルマネージャを開きます。
3. 設置したいディレクトリを作成します。
4. Zipファイルをアップロードします。
5. サーバー上でZipを展開します。
6. 展開後、設置ディレクトリ直下に `index.php` があることを確認します。
7. 同じ階層に `.htaccess` があることを確認します。
8. `config.php` が存在しないことを確認します。
9. ブラウザでトップページまたは `/setup/` にアクセスします。`config.php` がない場合、トップページから相対URL `setup/` へ自動的に移動します。
10. サイト名、URL、テーマなどを入力して setup を完了します。
11. `config.php` が生成されたことを確認します。
12. setup完了画面に表示される管理用合言葉を控えます。
13. `cache/html/` にPHPから書き込めることを確認します。
14. `storage/update-backups/`、`storage/update-logs/`、`storage/update-tmp/` にPHPから書き込めることを確認します。
15. `/storage/` へWebから直接アクセスできないことを確認します。
16. `setup/` ディレクトリを削除またはリネームします。

配布Zip例:

```text
tomos-0.1.0-alpha.15.zip
```

正しい配置例:

```text
tomos-install-test/index.php
tomos-install-test/.htaccess
tomos-install-test/core/
tomos-install-test/setup/
tomos-install-test/post/
tomos-install-test/update/
tomos-install-test/storage/
tomos-install-test/themes/
tomos-install-test/content/
tomos-install-test/cache/
```

誤った配置例:

```text
tomos-install-test/Tomos/index.php
tomos-install-test/Tomos/.htaccess
tomos-install-test/Tomos/core/
```

Zipを展開したあと、`index.php` が設置ディレクトリ直下に見えることを確認してください。一段深いフォルダに入っている場合は、その中身を設置ディレクトリ直下へ移動してください。

Zipを展開したあと、設置ディレクトリ直下に `index.php` と `.htaccess` があることを必ず確認してください。

## B. FTPアップロード方式

Zip展開できないホストでは、FTP方式を使います。

1. Tomos 配布Zipを手元のPCで展開します。
2. FTPソフトで不可視ファイルを表示する設定にします。
3. `.htaccess` が見えていることを確認します。
4. 設置したいディレクトリへ全ファイルをアップロードします。
5. `index.php`、`.htaccess`、`core/`、`setup/`、`post/`、`themes/`、`content/`、`cache/` が同じ階層にあることを確認します。
6. `config.php` はアップロードしません。
7. ブラウザでトップページまたは `/setup/` にアクセスします。`config.php` がない場合、トップページから相対URL `setup/` へ自動的に移動します。
8. setup を完了します。
9. `config.php` が生成されたことを確認します。
10. setup完了画面に表示される管理用合言葉を控えます。
11. `cache/html/` にPHPから書き込めることを確認します。
12. `setup/` ディレクトリを削除またはリネームします。

## setup 入力例

setup画面では、サイト名やURLに加えて、利用するテーマを選択できます。通常は標準テーマ `tomos-minimal` のままで構いません。

setup完了時には、管理用合言葉が一度だけ表示されます。この合言葉は、Tomos Writeなどで作成したMarkdownファイルの投稿、投稿の取り下げ、trash整理、テーマ切り替えに必要です。あとから画面では確認できないため、安全な場所に控えてください。

設置後にテーマを追加したい場合は、Tomos Postのテーマ管理画面からテーマZIPをアップロードできます。追加後はテーマ管理画面から使用テーマを切り替えます。

### 独自ドメイン直下

```text
site.url:
https://tomoswords.org

base_path:

public_base_path:
```

### 独自ドメインのサブディレクトリ

```text
site.url:
https://example.com/tomos

base_path:
/tomos

public_base_path:
```

通常ホストでは `public_base_path` は空で構いません。特殊なプロキシ構成などで、HTMLに出力するURLパスだけを補正したい場合に指定します。

`base_path` と `public_base_path` はURL上のパスです。サーバー内の実パスは入力しません。

## 初期セットアップ後にサイト情報を変更する

GA4測定IDは、Tomos Postの「GA4設定」から管理用合言葉を使って変更・削除できます。空欄で保存するとGoogleタグの出力を停止します。詳細は [Google Analytics 4の設定](docs/user/analytics.md) を参照してください。

初期セットアップ完了後は、Tomos Postの「サイト設定」（`/post/settings/`）から、サイト名、サイト説明、タイムゾーン、RSSの有効・無効と対象パス、Sitemapの有効・無効を変更できます。

GA4測定IDと使用テーマは、サイト設定画面から移動できる既存の専用画面で変更します。

`config.php` の `site` 設定を確認してください。

```php
'site' => [
    'name' => 'Tomos Site',
    'description' => 'Markdown で自分の Web をともす',
    'url' => 'https://example.com',
    'base_path' => '',
    'public_base_path' => '',
    'language' => 'ja',
    'timezone' => 'Asia/Tokyo',
],
```

### サイト名を変更する

`name` を変更します。

```php
'name' => '新しいサイト名',
```

例:

```php
'name' => '吾郎の日記',
```

### サイトの説明文（コピー）を変更する

`description` を変更します。

```php
'description' => '新しいサイトの説明文',
```

例:

```php
'description' => '京都で暮らし、考えたこと。',
```

テーマによっては、サイトタイトル下のコピーやHTMLのメタ情報として利用されます。

### サイトURLを変更する

設置先URLを変更した場合は、`url` も合わせて変更してください。

```php
'url' => 'https://example.com',
```

### 言語・タイムゾーンを変更する

必要に応じて、以下の設定も変更できます。

```php
'language' => 'ja',
'timezone' => 'Asia/Tokyo',
```

### 編集時の注意

- 文字列はシングルクォート `'` で囲んでください。
- 行末のカンマ `,` は削除しないでください。
- `config.php` は UTF-8 のまま保存してください。
- PHPの配列構造や、変更対象以外の設定は変更しないでください。
- 編集前に `config.php` のバックアップを保存することをおすすめします。

変更後はWebブラウザでサイトを再読み込みして反映を確認してください。

表示が更新されない場合は、キャッシュを削除または再生成してください。

## HTMLキャッシュ

setup の「HTMLキャッシュ」は通常有効のままで構いません。

Markdown変換後の本文HTMLを `cache/html/` に保存します。`cache/html/` に書き込めない場合でもページ表示は継続されますが、表示速度改善のためには書き込み可能にしてください。

## 設置後に記事を書く

設置後は、Tomos WriteでMarkdownを書き、Tomos Postで投稿し、Tomosで公開ページを確認します。

Tomos Writeなどで作成した `.md` / `.markdown` / `.txt` ファイルは、Tomos Postの `/post/` から投稿できます。詳しくは `docs/user/writing.md` を参照してください。

## .htaccess の注意

`.htaccess` は不可視ファイルとして扱われる場合があります。FTPソフトやファイルマネージャの設定で「不可視ファイルを表示」を有効にし、必ずアップロードしてください。

`.htaccess` がない場合、トップページは表示されても、以下が Not Found になることがあります。

```text
/about
/search/
/tags/
/feed.xml
/sitemap.xml
```

## setup完了後の確認URL

- トップページ
- `/about`
- `/search/`
- `/tags/`
- `/feed.xml`
- `/sitemap.xml`
- `/post/`

最後に、`/setup/` にアクセスして設定フォームが表示されないことを確認し、`setup/` ディレクトリを削除またはリネームしてください。

テーマを変更した場合は、公開ページを開いて見た目が切り替わっていることを確認してください。
