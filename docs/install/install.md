# インストール

Tomosの詳しい手順はルートの `../../INSTALL.md` にまとめています。このページでは、設置時に迷いやすい点を整理します。

既存環境を更新する場合は、[既存環境の更新](update.md) を確認してください。

## 推奨はZipアップロード方式

Zipアップロード方式は、`.htaccess` のアップロード漏れやファイル転送漏れを防ぎやすいため推奨です。

1. 配布された `tomos-<VERSION>.zip` をサーバーへアップロードします。
2. サーバー上でZipを展開します。
3. 展開後、設置ディレクトリ直下に `index.php` があることを確認します。
4. 同じ階層に `.htaccess` があることを確認します。
5. `config.php` が存在しないことを確認します。
6. `/setup/` にアクセスします。

正しい例:

```text
tomos-install-test/index.php
tomos-install-test/.htaccess
tomos-install-test/core/
tomos-install-test/setup/
tomos-install-test/themes/
tomos-install-test/content/
tomos-install-test/cache/
tomos-install-test/cache/html/
```

誤った例:

```text
tomos-install-test/Tomos/index.php
tomos-install-test/Tomos/.htaccess
tomos-install-test/Tomos/core/
```

一段深いフォルダに入っている場合は、その中身を設置ディレクトリ直下へ移動してください。

## FTPアップロード方式

Zip展開できないホストではFTP方式を使います。

FTP方式では、必ず不可視ファイルを表示し、`.htaccess` が見えていることを確認してください。

`config.php` はアップロードしません。`config.sample.php` はアップロードします。

## .htaccess

`.htaccess` はTomosの下層URLに必要です。

`.htaccess` がない場合、トップページは表示されても以下が Not Found になることがあります。

```text
/about
/search/
/tags/
/feed.xml
/sitemap.xml
```

サブディレクトリ設置でルーティングできない場合は、`.htaccess` の `RewriteBase` を設置パスに合わせてください。

## setup

初回設置では `config.php` をアップロードしません。`config.sample.php` が存在していても、`config.php` が存在しなければ setup は初回設置としてフォームを表示します。

setup完了後、`config.php` が生成され、`setup_completed` が `true` になります。

公開環境では、setup完了後に `setup/` ディレクトリを削除またはリネームしてください。

既存の `config.php` が `setup_completed` を定義していない場合、Tomosは安全側に倒してsetupを無効化します。再setupする場合は、明示的に `setup_completed => false` と `security.disable_setup_after_install => false` を設定してください。

HTMLキャッシュは通常有効のままで構いません。`cache/html/` に書き込めない場合でもページ表示は継続しますが、表示速度改善のためには書き込み可能にしてください。

## URLの自動設定

初回setupでは、setup画面を開いたURLから `site.url` を自動取得し、そのpathから `base_path` を自動生成します。通常ユーザーがこれらを入力する必要はありません。

独自ドメイン直下では `base_path` が空になり、サブディレクトリ設置では設置パスが保存されます。

## 設定例

### 独自ドメイン直下

```text
site.url: https://example.com
base_path:
public_base_path:
```

### 独自ドメインのサブディレクトリ

```text
site.url: https://example.com/tomos
base_path: /tomos
public_base_path:
```

通常ホストでは `public_base_path` は空で構いません。特殊なプロキシ構成などで、Tomosが内部的に受け取るURLパスとブラウザへ出力したいURLパスが異なる場合だけ指定します。

`base_path` と `public_base_path` はURL上のパスです。サーバー内の実パスは入力しません。

## 初期確認

setup後、以下を確認します。

- トップページ
- `/about`
- `/search/`
- `/tags/`
- `/feed.xml`
- `/sitemap.xml`
- `/setup/` で設定フォームが出ないこと
