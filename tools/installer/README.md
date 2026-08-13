# Installer Phase 1 tooling

このディレクトリは、通常配布ZIPを初回installerが信頼できる形へ変換するPhase 1のtoolingとtest vector定義を保持する。正式な`install.php`、downloader、UI、配置処理は含まない。

## コマンド

```bash
php tools/build-install-manifest.php \
  --zip=build/tomos-0.1.0-alpha.15.zip \
  --asset-url=https://example.invalid/download/install/v0.1.0-alpha.15/tomos-0.1.0-alpha.15.zip \
  --output=build/install-manifest.json

php tools/sign-install-manifest.php \
  --manifest=build/install-manifest.json \
  --private-key=/secure/path/private-key.pem \
  --public-key=update/public-key.pem \
  --output=build/install-manifest.sig

php tools/verify-install-package.php \
  --manifest=build/install-manifest.json \
  --signature=build/install-manifest.sig \
  --zip=build/tomos-0.1.0-alpha.15.zip \
  --public-key=update/public-key.pem

php tools/build-install-latest-pointer.php \
  --version=0.1.0-alpha.15 \
  --manifest-url=https://example.invalid/download/install/v0.1.0-alpha.15/install-manifest.json \
  --signature-url=https://example.invalid/download/install/v0.1.0-alpha.15/install-manifest.sig \
  --output=build/latest.json
```

`--asset-url`はmanifest署名前に確定したversioned HTTPS URLを明示する。toolingはURLからZIPを取得せず、任意URLを信用しない。

## Schemaと信頼順序

manifestは生バイトのままRSA/SHA-256で署名する。検証は署名 → schema → ZIP size／SHA-256 → ZIP全entry／path／limits → file size／SHA-256 → required filesの順で行う。directory entryはmanifestに含めず、`.htaccess`等のhidden fileは通常fileとして含める。

latest pointerはJSONであり、署名しない。pointerはversioned manifestとsignatureのURLを示すだけで、信頼の起点ではない。Phase 2ではpointer取得後にmanifest signatureを必ず検証する。

## Release順序

1. `tools/build-distribution.sh`で通常ZIPを生成
2. manifest生成
3. manifest署名
4. package verifier実行
5. versioned ZIP／manifest／signatureを公開
6. 公開URLから3点を再取得して再検証
7. 最後にlatest pointerを切替

Phase 1はGitHub公開やCI本番署名を行わない。private keyはproject外の安全な場所から明示的に渡し、ログ・Git・fixtureへ保存しない。testでは実行時に一時RSA keypairを生成する。

## Test vector

`tests/installer_phase1_check.php`が次を一時ZIPとして生成し、期待error codeを確認する。

- normal、manifest byte再現、signature、normal package
- manifest signature改変、ZIP SHA-256改変、file hash改変
- traversal、absolute path、Windows drive path、NUL、symlink
- entry count超過
- pointer正常、pointer schema不正、versioned URL構造不正

Phase 2以降で、missing entry、重複entry、file size超過、総展開容量超過、unknown schema、VERSION mismatch、truncated ZIPを追加fixtureとして固定する。

## Phase 2 core

`InstallerCore`は、固定latest pointer、versioned manifest／signature、署名済みassetをfixtureまたはHTTPS transportから取得し、`.tomos-installer/work-<random>/staging/`へ完全検証済み状態を作る。`InstallerCore::prepare()`は`verified_staging_path`とmanifest metadataを返すが、target rootへ配置しない。

ローカル統合テストは次で実行する。

```bash
php tests/installer_phase2_check.php
```

本番では`InstallerPublicKey::PEM`を最終1ファイルinstallerへ内蔵する。GitHub Release CDNのredirect hostは未確定のため、Phase 2のproduction allowlistへ追加していない。
