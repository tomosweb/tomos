# Tomos Post パスキー リリース条件

この文書は、パスキー機能を含むTomosの通常配布ZIPおよびTomos Update ZIPが満たす条件を定める。

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

生成後、次の検査が成功すること。

```bash
test -f core/webauthn/vendor/autoload.php
test -f core/webauthn/vendor/lbuchs/webauthn/src/WebAuthn.php
php tests/passkey_distribution_check.php
```

`composer.phar`、Composer本体、Composer cacheは配布ZIPへ含めない。

## 3. PHP互換条件

CIではPHP 7.4、8.0、8.2を対象とする。

PHP 7.4ではWebAuthn runtimeを読み込まず、次を満たすこと。

- Tomos本体とパスキー関連PHPがPHP 7.4構文として解析できる
- `PasskeyEnvironment` がパスキー利用不可と判定する
- 管理用合言葉認証のフォールバックを維持する
- Tomos Postにパスキー専用処理を必須化しない

PHP 8.0 / 8.2ではComposerからruntimeを生成し、次を満たすこと。

- `lbuchs/WebAuthn` v2.2.0をautoloadできる
- パスキー関連自動テストが成功する
- 配布必須ファイル検査が成功する

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

## 6. 認証・復旧の必須条件

パスキー対応リリースは、次の動作条件を満たすこと。

- 管理用合言葉認証を維持する
- PHP 8.0以上かつ必要条件を満たす環境だけでパスキーを有効化する
- パスキー登録には管理用合言葉の再認証を要求する
- 登録済みパスキーでTomos Postへ認証できる
- パスキーの名称変更・追加・削除ができる
- 登録済みパスキーで管理用合言葉を再設定できる
- 合言葉再設定時に既存の記憶認証を失効する
- パスキー未登録かつ合言葉を忘れた場合は、サーバー所有確認後に最初のパスキー1件だけを登録できる
- 復旧用TXTは照合成功後に削除できなければ復旧を継続しない
- 現在のRP IDに登録済みパスキーがある場合はサーバー所有確認による初回復旧を利用できない

## 7. Updateで保持する不変条件

Updateでは次を保持すること。

- `storage/security/passkeys/` を更新対象にしない
- 既存credentialの保存形式を破壊しない
- 管理用合言葉認証を維持する
- WebAuthn runtimeを欠落させない
- `core/required-installed-files.txt` によるインストール整合性検査を通過する

## 8. リリース判定

次のすべてを満たした場合だけ、パスキー対応リリースを配布可能とする。

- PHP 7.4 / 8.0 / 8.2互換CI成功
- パスキー関連自動テスト成功
- `passkey_distribution_check` 成功
- 通常配布ZIPにWebAuthn runtime同梱
- Update ZIPにWebAuthn runtime同梱
- `core/required-installed-files.txt` の必須ファイルを満たす
- `storage/security/passkeys/` がUpdate対象外である
