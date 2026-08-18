# Tomos Update

Tomos Updateは、公式オンライン更新と手動の署名済みUpdate ZIP更新を提供します。どちらも確認画面を経て、同じ署名検証、`from_version`検証、backup、rollback、apply処理を使用します。

手動ZIP更新は恒久的な正式ルートとして残ります。オンライン更新を利用できない場合も、正式な署名済みUpdate ZIPを使った更新経路を維持します。

## 現在の更新方法

通常の更新はTomos Postの「Tomos Update」から行います。

1. Tomos Postへ認証します。
2. Tomos Update画面（`/update/`）を開きます。
3. 公式オンライン更新を利用する場合は「更新内容を確認」を押します。手動更新の場合は正式な署名済みUpdate ZIPを選びます。
4. 現在のversion、更新先version、対象ファイルを確認します。
5. 「更新する」を明示的に押した場合だけ更新を適用します。
6. 更新完了後、表示されるversionと公開ページを確認します。

`/update/`を開いただけではUpdate ZIPを適用しません。オンライン更新でも、ZIPの取得・検証と実際の適用は明示的な操作を分けています。

自動更新、バックグラウンド更新、一括多段更新は行いません。更新は常に1versionずつ進みます。

## 更新元バージョンの必須一致

Update ZIPの署名済み`manifest.json`には、適用できる唯一の現在版を示す`from_version`と、更新後の`version`が含まれます。

オンライン更新・手動ZIP更新を問わず、`from_version`が現在の`VERSION`と完全一致しないZIPは、署名が正しくても確認・適用できません。

通常Update ZIPでは旧形式の`minimum_version`を使用しません。

## v0.1.0-alpha.19からv0.1.0-beta.1への更新

alpha.19からbeta.1へは、通常のオンライン更新または手動の署名済みUpdate ZIPを使用します。

```text
from_version: 0.1.0-alpha.19
version: 0.1.0-beta.1
```

この更新では `/post/update-finalize/` の操作は必要ありません。

beta.1への更新前に、少なくとも `config.php`、`content/`、利用中テーマ、`storage/security/passkeys/` をバックアップしてください。

## Updater finalizeが必要だったlegacy移行

`/post/update-finalize/` が必要なのは、旧Updaterから新Updaterへ移行した `v0.1.0-alpha.17 -> v0.1.0-alpha.18` の一回限りのbootstrapです。

この移行では、alpha.18のlegacy bridge ZIPを適用した後に `/post/update-finalize/` を開き、Updater bundleを明示的に反映します。

alpha.18以降の通常Updateでは、このlegacy finalize手順を繰り返しません。

## 署名と検証

オンライン更新と手動ZIP更新のどちらも、最終的に次を検証します。

- `manifest.sig` と `update/public-key.pem` による署名
- `from_version` と現在の `VERSION` の完全一致
- 製品名と更新先version
- manifestに記載された各ファイルのSHA-256
- 更新対象pathのallowlist
- symlinkや危険なpathの拒否
- 更新後の必須ファイルとVERSION

更新中に失敗した場合は、更新済みファイルのrollbackを試みます。rollback自体に失敗した場合は通常の更新失敗と区別して扱います。

## 更新対象外のデータ

Tomos Updateは、利用者の運用データを通常の更新対象にしません。

主な更新対象外:

- `config.php`
- `content/`
- `cache/` の生成済みデータ
- `storage/` の運用データ
- `trash/`
- 利用者が追加した独自テーマ
- `storage/security/passkeys/` の登録済みcredential

更新前のバックアップは、Tomos Update自身のrollbackとは別に利用者側でも作成してください。

## 必要な環境

- PHP 7.4以上
- ZipArchive
- OpenSSL
- Tomos設置ディレクトリ内の更新対象への書き込み権限
- `storage/update-tmp/`、`storage/update-backups/`、`storage/update-logs/` への書き込み権限

ZipArchiveまたはOpenSSLが利用できない場合はTomos Updateを使用できません。

パスキー機能を利用する場合は、Tomos本体とは別にPHP 8.0以上、mbstring、HTTPS、WebAuthn対応ブラウザが必要です。

## 保存データ

- `storage/update-backups/`: 更新対象ファイルと更新メタ情報
- `storage/update-logs/`: 更新結果ログ
- `storage/update-tmp/`: 確認中のZIPと展開ファイル
- `storage/update.lock`: 更新中の排他ロック
- `storage/security/passkeys/`: 登録済みパスキーcredential。Tomos Updateの更新対象外

Apache環境では `storage/.htaccess` がWebからの直接アクセスを拒否します。Apache以外ではWebサーバー側でも `storage/` へのアクセスを禁止してください。

過去のalpha版固有の更新手順は、[既存環境の更新](update.md)と各release noteを参照してください。
