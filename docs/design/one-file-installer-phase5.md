# 1ファイルインストーラー Phase 5 設計・実装記録

## 1. 目的と基点

Phase 5はInstaller機能を追加せず、Phase 1〜4の成果物をclean checkoutから再現可能に生成し、Release候補を検査する工程を担当する。正式baselineは`53bc1f76754cbf550d726e0cdc6c4ebe7609fe51`の直系である。

本PhaseではGitHub Release、production tag、公式サイトの`latest.json`、secret登録は実行しない。

## 2. Release候補生成

次のcommandが依存準備から候補生成までの正本である。署名鍵はrepository外から渡す。

```bash
bash tools/build-installer-release-candidate.sh \
  --private-key=/secure/outside-project/install-signing-private.pem \
  --public-key=update/public-key.pem \
  --output-dir=build/release-candidate
```

工程は、Composer依存準備、通常配布ZIP、ZIP検査、Passkey検査、Installer Phase 1〜4、single-file生成、manifest生成、RSA/SHA-256署名、package検証、latest pointer生成、SHA-256一覧生成の順である。

## 3. 依存準備とCI

`bash tools/prepare-distribution-dependencies.sh`が`core/webauthn/composer.lock`に基づき、Composer 2の`--no-dev --prefer-dist --no-interaction --no-progress --no-scripts --classmap-authoritative`でruntimeを生成する。ComposerはRelease build環境だけに必要で、Tomos利用者へは要求しない。

CIのdry-runはPHP 8.2、OpenSSL、mbstring、ZipArchive、Composer 2、bash、rsync、zip、unzipを使用する。通常PRではtest専用の一時RSA鍵を生成し、本番鍵secretを参照しない。

## 4. Asset構成とURL

Release候補には次を含める。

```text
tomos-x.y.z.zip
install-manifest.json
install-manifest.sig
install.php
latest.json
SHA256SUMS
```

既存の`tomos-update-x.y.z.zip`はUpdate専用として責務を維持する。

manifestのasset URLとpointerのversioned URLは、公式サイトのimmutable mirrorを正本として次の形にする。

```text
https://tomoswords.org/download/install/vx.y.z/tomos-x.y.z.zip
https://tomoswords.org/download/install/vx.y.z/install-manifest.json
https://tomoswords.org/download/install/vx.y.z/install-manifest.sig
```

GitHub Releaseにも同名のZIP、manifest、signature、installerを置けるが、現行InstallerCoreのURL検証はGitHubの署名付きredirect queryを受け付けない。そのため、Phase 5ではinstallerがGitHub CDNへ直接依存するallowlist拡張を行わず、公式サイトmirrorをinstallerの配布hostとする。GitHub redirectは2026-08-13に実測し、`github.com`から`release-assets.githubusercontent.com`への302であった。redirect先は短期的に変動し得るため、未検証のwildcardを追加しない。

## 5. 署名鍵

本番ReleaseではGitHub Actions secretから一時ファイルへ復元し、署名、公開鍵による自己検証、trapによる削除を行う。秘密鍵はログ、artifact、repository、build/install.phpへ出さない。通常PRはtest専用鍵でdry-runし、本番署名jobだけが本番secretを参照する構造にする。

## 6. 公開順序と再検証

公開の論理境界は次の順序である。

1. versioned ZIP、manifest、signature、installerを生成する
2. local package verifierとsingle-file検査を通す
3. versioned assetを公開する
4. 公開URLからZIP、manifest、signature、installerを再取得する
5. signature、ZIP size/hash、全inventory、VERSION、installer hashを再検証する
6. 最後に公式サイトの`latest.json`をatomic相当に切り替える

assetの一部公開や再検証失敗時はpointerを更新しない。公開済みversioned assetは同じversion名で差し替えず、修正時は新しいversionを発行する。誤配布時はpointerを前versionへ戻し、versioned assetは削除・上書きしない。

公開後再検証は次で実行する。

```bash
php tools/verify-published-install-assets.php \
  --pointer-url=https://tomoswords.org/download/install/latest.json \
  --installer-url=https://tomoswords.org/download/install/install.php \
  --installer-sha256=<公開したinstall.phpのSHA-256>
```

## 7. latest pointer

`latest.json`はversioned manifestとsignatureの場所を示すだけで、信頼の起点ではない。最終的な受入れ判断は署名済みmanifestとZIP hash/inventory検証で行う。公式サイトdeploy側で一時ファイルからatomic renameまたは同等のdeploy単位切替を行い、壊れたJSONを先に公開しない。

Phase 5では本番`latest.json`を更新しない。公式サイト側のdeploy方式が確定してから、人間の承認付きdeploy jobで切り替える。

## 8. fallback URL

現行の公式手順URLは`https://tomoswords.org/start/install/`である。旧`/docs/install/install.md`は404だったため、installerのfallback定数はこの確認済みURLへ最小修正する。

## 9. dry-run workflow

`.github/workflows/installer-release-dry-run.yml`はPRまたは手動実行で、clean checkout、依存準備、候補生成、Phase 1〜4、candidate検査を行い、GitHub Releaseやproduction siteを変更せずartifactだけを保存する。本番Release uploadとlatest切替は別の承認付き工程としてPhase 5後に接続する。

## 10. 代表実サーバー

本Phaseでは外部サーバーへアップロードしない。人間が検証用に、次のcommandでtest keyと検証用pointerを埋め込んだ候補を作成する。

```bash
bash tools/build-installer-real-server-test.sh \
  --private-key=/secure/outside-repo/test-private.pem \
  --public-key=/secure/outside-repo/test-public.pem \
  --asset-base-url=https://<検証ホスト>/installer-test-assets
```

候補の`versioned/`資産と`latest.json`は`installer-test-assets/`へ、`install.php`は別の空の`installer-test-app/`へアップロードする。test版はproduction latest URLを実行時設定にせず、test public keyで署名された資産だけを受け入れる。GitHub asset直リンクではなく、検証用mirror URLを使う。詳細手順と記録欄は`docs/testing/installer-real-server.md`に固定する。

## 11. Go条件

正式公開前に、次を人間が確認する。

- 公式サイトmirrorへversioned 3資産と`latest.json`を安全にdeployできる
- 本番署名secretが公開鍵と対応し、公開後再取得検証が通る
- representative serverでA/B、timeout、permission、self-deleteを確認する
- 公式サイトのinstall.php URL、通常ZIP URL、fallback URL、SHA-256を公開導線へ反映する

したがって、Phase 5のコード/dry-run統合は条件付き完了であり、正式Releaseは上記実環境確認後とする。
