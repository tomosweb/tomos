# Tomos Update

Tomos Updateは、管理画面から署名済み更新ZIPを確認し、Tomos本体を更新する機能です。GitHub接続、自動取得、自動更新には対応しません。

## v0.1.0-alpha.13への更新

すでにv0.1.0-alpha.12をご利用の場合は、Tomos Postの「Tomos Update」から、署名済みの `tomos-update-0.1.0-alpha.13.zip` を適用できます。

alpha.13のUpdate ZIPのminimum versionは `0.1.0-alpha.12` です。

### v0.1.0-alpha.12からv0.1.0-alpha.13への更新

1. 既存サイトの `config.php`、`content/`、`themes/` をバックアップします。`storage/security/passkeys/` が存在する場合は、このディレクトリもバックアップします。
2. Tomos Postの「Tomos Update」を開きます。
3. `tomos-update-0.1.0-alpha.13.zip` を選び、「更新内容を確認」を押します。
4. 現在のバージョンが `0.1.0-alpha.12`、更新後のバージョンが `0.1.0-alpha.13` と表示されていることを確認します。
5. 更新対象を確認し、更新を実行します。
6. 更新完了後、「現在のバージョン」が `0.1.0-alpha.13` と表示されていることを確認します。
7. Tomos Postに「セキュリティ」への導線が追加されていることを確認します。

alpha.13では、Tomos PostにWebAuthnパスキー認証、複数パスキー管理、パスキーによる管理用合言葉再設定、パスキー未登録時の復旧、セキュリティ画面を追加します。

Tomos本体はPHP 7.4以上で利用できます。パスキー機能を利用する場合は、PHP 8.0以上、OpenSSL、mbstring、HTTPS、WebAuthn対応ブラウザが必要です。条件を満たさない場合も、従来の管理用合言葉認証は利用できます。

WebAuthn runtimeはUpdate ZIPへ同梱されています。利用者がComposerをインストールしたり、サーバー上でComposerを実行したりする必要はありません。

`storage/security/passkeys/` はTomos Updateの更新対象ではありません。登録済みパスキーのcredentialはUpdateによって上書きまたは削除されません。

このUpdateでは、`config.php`、`content/`、利用者が追加したテーマも上書きまたは削除しません。

## v0.1.0-alpha.12への更新

すでにv0.1.0-alpha.11をご利用の場合は、Tomos Postの「Tomos Update」から、署名済みの `tomos-update-0.1.0-alpha.12.zip` を適用できます。

### v0.1.0-alpha.11からv0.1.0-alpha.12への更新

1. 既存サイトの `config.php`、`content/`、`themes/` をバックアップします。
2. Tomos Postの「Tomos Update」を開きます。
3. `tomos-update-0.1.0-alpha.12.zip` を選び、「更新内容を確認」を押します。
4. 現在のバージョンが `0.1.0-alpha.11`、更新後のバージョンが `0.1.0-alpha.12` と表示されていることを確認します。
5. 更新対象を確認し、更新を実行します。
6. 更新完了後、「現在のバージョン」が `0.1.0-alpha.12` と表示されていることを確認します。
7. Tomos Postのテーマ管理画面に「テーマZIPを追加」が表示されることを確認します。

このUpdateでは、`config.php`、`content/`、利用者が追加したテーマを上書きまたは削除しません。

## v0.1.0-alpha.11への更新

v0.1.0-alpha.5以前をご利用の場合は、最初にv0.1.0-alpha.6へ手動で更新してください。

alpha.6への移行後、署名済みUpdate ZIPを使い、v0.1.0-alpha.7、alpha.8、alpha.9、alpha.10の順に更新してください。alpha.10からは、Tomos Postの「Tomos Update」でv0.1.0-alpha.11へ更新できます。

更新順序:

```text
v0.1.0-alpha.5以前
↓
v0.1.0-alpha.6へ手動更新
↓
Tomos Updateからv0.1.0-alpha.7へ更新
↓
Tomos Updateからv0.1.0-alpha.8へ更新
↓
Tomos Updateからv0.1.0-alpha.9へ更新
↓
Tomos Updateからv0.1.0-alpha.10へ更新
↓
Tomos Updateからv0.1.0-alpha.11へ更新
```

すでにv0.1.0-alpha.10をご利用の場合は、そのままTomos Updateからalpha.11へ更新できます。

## alpha.6の信頼点移行

Tomos Updateを今後も安定して提供するため、`v0.1.0-alpha.6`で署名確認に使用する信頼点を更新します。既存環境からalpha.6への移行だけは、[既存環境の更新](update.md)に沿って`VERSION`と`update/public-key.pem`を手動で上書きしてください。alpha.6自体の署名済みUpdate ZIPは提供しません。

alpha.6への移行後は、alpha.7以降の署名済みUpdate ZIPをこの画面で確認できます。alpha.13のUpdate ZIPは、現在のバージョンが`0.1.0-alpha.12`の場合に適用できます。

新しい公開鍵のフィンガープリント:

```text
SHA-256: 228636b1c3d2c93cf320063c478c2604b892a287bb346e1f6a3adf98047247cf
```

## 利用手順

1. Tomos Postを開き、管理用合言葉で認証します。
2. Tomos Update画面（`/update/`）を開きます。
3. 正規のTomos更新ZIPを選び、「更新内容を確認」を押します。
4. 現在と更新後のバージョン、対象ファイル、テーマ変更の有無を確認します。
5. 「更新する」を押します。

alpha.10以降では、通常Update完了後にUpdater本体の明示反映が必要です。

1. Tomos PostのUpdater更新反映画面（`/post/update-finalize/`）を開きます。
2. 反映待ち状態を確認します。GETで画面を開いただけでは反映されません。
3. 管理用合言葉を入力し、「Updater更新を反映する」を押します。
4. 「Updater本体を更新しました。」と表示され、反映待ちの更新がなくなったことを確認します。

現在の`update/index.php`は置換前に専用バックアップへ保存されます。反映に失敗した場合は旧版の復元を試み、待機ファイルを残して再実行できる状態を維持します。

更新対象ファイルだけが `storage/update-backups/` へバックアップされます。`config.php`、`content/`、`cache/`、`storage/`、`trash/`、独自テーマは更新対象になりません。途中で失敗した場合は更新済みファイルを自動復元し、新規追加ファイルを削除します。

## 必要な環境

- PHP 7.4以上
- ZipArchive
- OpenSSL
- Tomos設置ディレクトリ内の対象ファイル／対象ディレクトリへの書き込み権限
- `storage/update-tmp/`、`storage/update-backups/`、`storage/update-logs/` への書き込み権限

ZipArchiveまたはOpenSSLが利用できない場合はTomos Updateを使用できません。FTPまたはサーバーのファイル管理機能で更新してください。

パスキー機能を利用する場合は、Tomos Update自体の必要環境に加えて、PHP 8.0以上、mbstring、HTTPS、WebAuthn対応ブラウザが必要です。

## 保存データ

- `storage/update-backups/`: 更新対象ファイルと `update-meta.json`
- `storage/update-logs/`: 月単位のJSON Lines結果ログ
- `storage/update-tmp/`: 確認中のZIPと展開ファイル（24時間後に削除）
- `storage/update.lock`: 更新中だけ存在する排他ロック
- `storage/security/passkeys/`: 登録済みパスキーのcredential。Tomos Updateの更新対象外です。

`storage/.htaccess` は保存データへのWebアクセスを拒否します。Apache以外では、Webサーバー側でも `storage/` へのアクセスを禁止してください。
