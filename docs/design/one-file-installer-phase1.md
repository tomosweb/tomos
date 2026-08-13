# 1ファイルインストーラー Phase 1 実装仕様

## 対象

Phase 1は、通常配布ZIPをinstall manifestへ変換し、RSA/SHA-256署名、公開鍵検証、ZIP inventory検証、versioned assetとlatest pointerのローカルRelease手順を確認する。正式`install.php`、downloader、UI、環境診断、A/B配置、journal、rollback、CI本番署名、GitHub公開は対象外である。

## 確定仕様

- `schema_version`は1、`product`は`Tomos`。
- `asset`はZIP name、size、SHA-256、versioned HTTPS URLを持つ。
- ZIP内の全実fileをpath順で列挙し、sizeとSHA-256を記録する。directory entryは除外する。
- `.htaccess`等のhidden fileは除外しない。
- 上限はentry 500、1 file 10 MiB、展開総量100 MiB。
- ZIP filename、project `VERSION`、ZIP内`VERSION`、manifest versionは一致必須。
- `tools/required-distribution-files.txt`の全fileをZIP内で確認する。manifestへrequired flagは追加しない。
- JSONはUTF-8、pretty print、slash／Unicode非escape、末尾LFで固定する。署名対象は生成した生バイトである。
- latest pointerはJSONで、version、versioned manifest URL、versioned signature URLを示す。pointer自体は署名しない。最終信頼対象は署名済みmanifestである。

## Release用command

通常ZIP生成後に、次を順に実行する。

```bash
php tools/build-install-manifest.php --zip=build/tomos-0.1.0-alpha.15.zip --asset-url=https://example.invalid/download/install/v0.1.0-alpha.15/tomos-0.1.0-alpha.15.zip --output=build/install-manifest.json
php tools/sign-install-manifest.php --manifest=build/install-manifest.json --private-key=/secure/private-key.pem --public-key=update/public-key.pem --output=build/install-manifest.sig
php tools/verify-install-package.php --manifest=build/install-manifest.json --signature=build/install-manifest.sig --zip=build/tomos-0.1.0-alpha.15.zip --public-key=update/public-key.pem
php tools/build-install-latest-pointer.php --version=0.1.0-alpha.15 --manifest-url=https://example.invalid/download/install/v0.1.0-alpha.15/install-manifest.json --signature-url=https://example.invalid/download/install/v0.1.0-alpha.15/install-manifest.sig --output=build/latest.json
```

`example.invalid`は説明用placeholderであり、実Releaseでは確定した公式versioned URLを使用する。manifest生成toolはZIPを指定入力として受け、URLから取得しない。

## versioned公開とpointer切替

論理的な公開構造は次のとおりとする。

```text
/download/install/latest.json
/download/install/v0.1.0-alpha.15/install-manifest.json
/download/install/v0.1.0-alpha.15/install-manifest.sig
/download/install/v0.1.0-alpha.15/tomos-0.1.0-alpha.15.zip
```

versioned ZIP、manifest、signatureを公開してから公開URLで再検証し、最後にlatest pointerを切り替える。manifestとsignatureを固定URLで別々に上書きしない。

## 鍵

初期製品の公開鍵は既存`update/public-key.pem`を使用する。private keyはproject外から明示的に渡し、toolはproject tree内のprivate keyを拒否する。private keyの内容はログへ出さない。自動テストは本番鍵を使わず、実行時に一時RSA keypairを生成するため、test keyをRelease工程へ誤用しない。

## error code

toolingは一般向け文言ではなく、次のerror codeを標準出力へ出す。

`manifest_signature`, `manifest_schema`, `manifest_version`, `asset_hash`, `asset_size`, `zip_open`, `zip_path`, `zip_duplicate`, `zip_symlink`, `zip_limits`, `zip_contents`, `file_hash`, `file_size`, `required_file`, `pointer_schema`, `private_key`, `environment`。

## 自動テスト

```bash
php tests/installer_phase1_check.php
```

testでは正常manifestのbyte一致、署名検証、manifest／ZIP／file hashの改変拒否、危険path、symlink、limits、pointer schemaを確認する。Phase 1ではUpdate関連ファイルとUpdate schemaを変更しない。

## Phase 2への引き渡し

Phase 2のinstaller coreは、latest pointerからversioned manifest／signature URLを取得し、Phase 1 verifierと同じ順序で署名、schema、asset hash、ZIP inventory、file hashを検証する。HTTPS host allowlist、downloader、第三者直接実行のbootstrap／所有確認、sessionとCSRFの責務分離はPhase 2で確定する。公開鍵は初期版ではinstallerへPEM定数として内蔵する。
