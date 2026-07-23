# Tomos Update

Tomos Updateは、管理画面から署名済み更新ZIPを確認し、Tomos本体を更新する機能です。GitHub接続、自動取得、自動更新には対応しません。

## 利用手順

1. Tomos Postを開き、管理用合言葉で認証します。
2. `update/` を開きます。
3. 正規のTomos更新ZIPを選び、「更新内容を確認」を押します。
4. 現在と更新後のバージョン、対象ファイル、テーマ変更の有無を確認します。
5. 「更新する」を押します。

更新対象ファイルだけが `storage/update-backups/` へバックアップされます。`config.php`、`content/`、`cache/`、`storage/`、`trash/`、独自テーマは更新対象になりません。途中で失敗した場合は更新済みファイルを自動復元し、新規追加ファイルを削除します。

## 必要な環境

- PHP 7.4以上
- ZipArchive
- OpenSSL
- Tomos設置ディレクトリ内の対象ファイル／対象ディレクトリへの書き込み権限
- `storage/update-tmp/`、`storage/update-backups/`、`storage/update-logs/` への書き込み権限

ZipArchiveまたはOpenSSLが利用できない場合はTomos Updateを使用できません。FTPまたはサーバーのファイル管理機能で更新してください。

## 保存データ

- `storage/update-backups/`: 更新対象ファイルと `update-meta.json`
- `storage/update-logs/`: 月単位のJSON Lines結果ログ
- `storage/update-tmp/`: 確認中のZIPと展開ファイル（24時間後に削除）
- `storage/update.lock`: 更新中だけ存在する排他ロック

`storage/.htaccess` は保存データへのWebアクセスを拒否します。Apache以外では、Webサーバー側でも `storage/` へのアクセスを禁止してください。
