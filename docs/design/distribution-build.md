# 通常配布ZIPの再現ビルド手順

## 目的

通常配布ZIPは、Tomos利用者へComposerを要求せず、Release build環境でWebAuthn runtimeを生成してから作成する。`core/webauthn/vendor/`はGit管理対象外の生成物であり、clean checkoutへ暗黙に存在するものではない。

依存versionは`core/webauthn/composer.lock`で固定する。現在は`lbuchs/webauthn v2.2.0`である。Composer packageは公開GitHub/Packagist配布物で、repository secretは不要である。

## clean checkoutからの手順

PHP 8.0以上、OpenSSL、mbstring、ZipArchive、Composer 2、rsync、zipを用意する。

```bash
git clone https://github.com/tomosweb/tomos-dev.git
cd tomos-dev

bash tools/prepare-distribution-dependencies.sh
bash tools/build-distribution.sh
```

依存準備scriptは次を行う。

1. `composer.json`と`composer.lock`の存在を確認
2. `composer validate --no-check-publish`を実行（license未指定等の警告は表示されるが、依存定義の検証は継続する）
3. `composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --classmap-authoritative`を実行
4. `vendor/autoload.php`とWebAuthn runtime代表fileを確認

`--no-scripts`は、このprojectの`composer.json`にscriptsがなく、依存packageのinstall scriptsを実行する必要もないため採用する。Composer、cache、`composer.phar`は配布物へコピーしない。

## 通常配布ZIPの検査

```bash
version="$(tr -d '[:space:]' < VERSION)"
test -f "build/tomos-${version}.zip"
unzip -t "build/tomos-${version}.zip"
test -f build/tomos/core/webauthn/vendor/autoload.php
test -f build/tomos/core/webauthn/vendor/lbuchs/webauthn/src/WebAuthn.php
unzip -Z1 "build/tomos-${version}.zip" | grep -Fx 'core/webauthn/vendor/autoload.php'
unzip -Z1 "build/tomos-${version}.zip" | grep -Fx 'core/webauthn/vendor/lbuchs/webauthn/src/WebAuthn.php'
php tests/passkey_distribution_check.php
```

`tools/build-distribution.sh`自身も、build directoryを消去する前にWebAuthn runtimeの準備状態を検査する。未準備なら、生成済みvendorを暗黙利用せず明確に停止する。

## Installer manifestへの引き渡し

通常ZIPの生成・検査後にPhase 1 toolingを実行する。

```bash
version="$(tr -d '[:space:]' < VERSION)"
php tools/build-install-manifest.php \
  --zip="build/tomos-${version}.zip" \
  --asset-url="https://example.invalid/download/install/v${version}/tomos-${version}.zip" \
  --output=build/install-manifest.json

php tools/verify-install-package.php \
  --manifest=build/install-manifest.json \
  --signature=build/install-manifest.sig \
  --zip="build/tomos-${version}.zip" \
  --public-key=update/public-key.pem
```

署名工程ではRelease専用の秘密鍵をproject外から明示的に渡す。秘密鍵をrepositoryやZIPへ置かない。testでは本番鍵を使わず、Phase 1 testが生成するtest keyを使う。

## CIへ引き渡すcommand

CIではPHP 8.2、Composer 2、OpenSSL、mbstring、ZipArchiveをsetupし、次の順序を非対話で実行する。

```bash
bash tools/prepare-distribution-dependencies.sh
bash tools/build-distribution.sh
php tests/passkey_distribution_check.php
```

その後、ZIP検査、manifest生成、manifest署名、公開済みversioned asset再取得・再検証、latest pointer切替をRelease workflowへ接続する。秘密鍵のCI登録、署名、公開はPhase 5で行う。

## 既知の境界

- `core/webauthn/vendor/`はtrackedではなく、clean checkoutには存在しない。
- Composer CLIがない環境では依存準備は成功しない。利用者のTomos実行環境へComposerを要求するものではない。
- PHP 7.4互換チェックではWebAuthn runtimeを生成せず、既存のfallback検査を行う。
- WebAuthn runtimeの仕様、authentication flow、Update仕様はこの手順では変更しない。
