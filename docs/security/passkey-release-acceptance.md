# Tomos Post パスキー リリース受入確認

この文書は、パスキー機能を含むTomosの通常配布ZIPおよびTomos Update ZIPを作成する前に確認する手順を定める。

## 1. 前提

利用者へComposerの導入を要求しない。Composerは開発・配布生成時だけ使用する。

WebAuthn runtimeは `core/webauthn/composer.lock` に従って生成し、通常配布ZIPとTomos Update ZIPの双方へ `core/webauthn/vendor/` を同梱する。

## 2. WebAuthn runtime生成

PHP 8.0以上、OpenSSL、mbstring、Composer 2系を利用できるビルド環境で次を実行する。

```bash
composer install \
  --working-dir=core/webauthn \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --classmap-authoritative
```

生成後、次を確認する。

```bash
test -f core/webauthn/vendor/autoload.php
test -f core/webauthn/vendor/lbuchs/webauthn/src/WebAuthn.php
php tests/passkey_distribution_check.php
```

`composer.phar`、Composer本体、Composer cacheは配布ZIPへ含めない。

## 3. PHP互換確認

CIではPHP 7.4、8.0、8.2を確認する。

PHP 7.4ではWebAuthn runtimeを読み込まず、次を満たすこと。

- Tomos本体とパスキー関連PHPがPHP 7.4構文として解析できる
- `PasskeyEnvironment` がパスキー利用不可と判定する
- 管理用合言葉認証のフォールバックを維持する

PHP 8.0 / 8.2ではComposerからruntimeを生成し、次を満たすこと。

- `lbuchs/WebAuthn` v2.2.0をautoloadできる
- パスキー関連自動テストが成功する
- 配布必須ファイル検査が成功する

ローカル確認:

```bash
php tests/passkey_php_compatibility_check.php
```

## 4. 通常配布ZIP

通常配布ZIPには、Tomos本体に加えて最低限次を含める。

```text
core/webauthn/composer.json
core/webauthn/composer.lock
core/webauthn/vendor/autoload.php
core/webauthn/vendor/lbuchs/webauthn/src/WebAuthn.php
post/assets/tomos-post-security.css
post/security/index.php
post/passkey/login/index.php
post/passkey/manage/index.php
post/passkey/register/index.php
post/passkey/password-reset/index.php
post/passkey/recovery/index.php
```

`core/webauthn/vendor/` はディレクトリ全体を含める。上記は欠落検査の代表ファイルであり、この一覧だけを抜き出して配布してはならない。

## 5. Tomos Update ZIP

パスキー機能を初めて導入するUpdate ZIPでは、既存環境にWebAuthn runtimeが存在しないため、`core/webauthn/vendor/` を必ず新規追加対象へ含める。

Update適用後、`core/required-installed-files.txt` に定義したWebAuthn runtimeまたはパスキー実装の必須ファイルが欠落している場合は更新成功扱いにしない。

`storage/security/passkeys/` は利用者データであり、Update ZIPへ含めず、上書き・削除しない。

## 6. 実機受入

PHP 8.x・HTTPSのdev環境で次を確認する。

1. 管理用合言葉でTomos Postへ入れる
2. 管理用合言葉の再入力後にパスキーを追加できる
3. Mac Chromeでパスキー認証できる
4. Mac Safariでパスキー認証できる
5. iPhone Safariでパスキー認証できる
6. パスキー認証後にTomos Postへ認証済みで入れる
7. パスキーの名称変更・削除ができる
8. パスキーで管理用合言葉を再設定できる
9. 合言葉再設定後、旧合言葉が利用できない
10. 合言葉再設定後、既存の記憶認証が失効する

## 7. パスキー未登録時の復旧受入

検証用環境で現在のRP IDに対するパスキーを0件にしてから確認する。

1. 復旧ファイルを準備する
2. ZIPをダウンロードする
3. ZIPを展開し、生成された `tomos-recovery-*.txt` を確認する
4. ZIPではなくTXTだけを `config.php` と同じTomos設置ルートへアップロードする
5. サーバー所有確認が成功する
6. アップロードしたTXTが自動削除される
7. 最初のパスキーを登録できる
8. そのパスキーで再認証できる
9. 新しい管理用合言葉を設定できる
10. 新しい合言葉でTomos Postへ入れる

## 8. Update回帰

登録済みパスキーがある検証環境でUpdateを適用し、次を確認する。

- Update前後で登録済みパスキー数が変化しない
- Update前のcredentialで認証できる
- 管理用合言葉でも認証できる
- `storage/security/passkeys/` が保持される
- WebAuthn runtimeが配布物から欠落していない

## 9. リリース判定

次のすべてを満たした場合だけ、パスキー対応リリースを配布可能とする。

- PHP 7.4 / 8.0 / 8.2互換CI成功
- パスキー関連自動テスト成功
- `passkey_distribution_check` 成功
- 通常配布ZIPにWebAuthn runtime同梱
- Update ZIPにWebAuthn runtime同梱
- dev実機回帰成功
- 復旧フロー実機回帰成功
- Update後のcredential維持確認成功
