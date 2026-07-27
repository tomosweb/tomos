# ホスティング環境の考え方

Tomosは特定のホスティングサービス専用ではありません。一般的なPHP共用レンタルサーバーにFTPまたはZipアップロードで設置することを想定しています。

## 推奨条件

- PHP 7.4以上
- Apache + `mod_rewrite`
- `.htaccess` が使えること
- 初期setup時に `config.php` を生成できること
- `cache/index/` に書き込めること
- HTMLキャッシュを使う場合は `cache/html/` に書き込めること

## A. 独自ドメイン直下

例:

```text
https://tomoswords.org/
```

設定:

```text
site.url: https://tomoswords.org
base_path:
public_base_path:
```

## B. 独自ドメインのサブディレクトリ

例:

```text
https://example.com/tomos/
```

設定:

```text
site.url: https://example.com/tomos
base_path: /tomos
public_base_path:
```

多くの通常ホストでは `public_base_path` は空で構いません。

## C. 特殊なプロキシ構成

通常は使いません。Tomosが内部的に受け取るURLパスと、ブラウザへ出力したいURLパスが異なる場合だけ `public_base_path` を指定します。

`base_path` も `public_base_path` もURL上のパスです。サーバー内の実パスは設定しません。

## ZipとFTP

Zip展開できるホストではZip方式を推奨します。Zip展開できない場合はFTP方式を使います。FTP方式では、`.htaccess` のアップロード漏れに注意してください。
