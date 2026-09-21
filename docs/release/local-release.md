# Local Release Gate

## 目的

GitHub Actionsが利用できない場合でも、TomosのRelease Candidateについて、Actionsと同じ既存の検証ロジックをローカル環境から再現できる正式手順を定義する。

GitHub ActionsをReleaseの唯一のGateとはしない。

TomosのReleaseは次の三層で扱う。

```text
AI review
  -> Local automated release gate
  -> Human / production acceptance
```

GitHub Actionsが利用可能な場合は、同じ検証をGitHub上でも再実行する追加Gateとして扱う。

## 原則

- Releaseの正否をAIだけに判断させない。
- 機械的に検証できる項目はスクリプトでfail-closedにする。
- AIは最終diff、Release checklist、docs、既知制約の確認を担当する。
- 実ブラウザ、実SNS、実hosting、公開artifact経路は人間のAcceptance Gateを残す。
- 本番署名private keyはrepository外に置く。
- test signing keyで生成したartifactを公開しない。
- local gate PASSだけで `PUBLIC RELEASE COMPLETE` としない。

## 実行ファイル

```text
tools/release-local.sh
```

既存のrelease/testロジックを呼び出すオーケストレーターであり、Release処理を別実装しない。

主に次を再利用する。

- `tools/prepare-distribution-dependencies.sh`
- `tools/build-distribution.sh`
- `tests/*_check.php`
- `tests/release_transition_check.php`
- `tools/build-installer-release-candidate.sh`
- `tests/installer_phase5_check.php`

## Mac / Linux

現行ActionsはUbuntuで `sha256sum` を利用する。

macOSで `sha256sum` がない場合、`release-local.sh` は一時PATHに互換shimを作り、標準の

```bash
shasum -a 256
```

を利用する。

repository内のRelease scriptをMac専用実装へ分岐させない。

## Check mode

開発中・PR最終確認では以下を実行する。

```bash
bash tools/release-local.sh
```

Check modeは以下を行う。

1. clean working tree確認
2. immutable public baseline取得
3. PHP lint
4. WebAuthn dependency preparation
5. Distribution build
6. Core regression checks
7. Release transition test Update生成・検証
8. PHP compatibility check
9. ephemeral RSA key生成
10. Installer Release Candidate生成
11. Installer Phase 5
12. SHA-256検証
13. Markdown Release Report生成

ephemeral keyで生成したInstaller assetは **TEST ONLY** であり公開しない。

## PHP compatibility matrix

GitHub ActionsではPHP 7.4 / 8.0 / 8.2 / 8.5を確認している。

ローカルで複数PHPを利用できる場合:

```bash
TOMOS_PHP_MATRIX="php74 php80 php82 php85" \
bash tools/release-local.sh
```

Check modeでmatrixを指定しない場合は現在のPHPだけを確認し、reportへ `PARTIAL` と記録する。

Formal release modeでは7.4 / 8.0 / 8.2 / 8.5の4系統を必須とし、不足時はBLOCKEDにする。

各binary名は環境に合わせてよい。実際のversionはbinary名ではなく `PHP_MAJOR_VERSION.PHP_MINOR_VERSION` で検証する。

## Formal release mode

本番Release Candidateを生成する場合:

```bash
TOMOS_PHP_MATRIX="php74 php80 php82 php85" \
bash tools/release-local.sh \
  --mode=release \
  --private-key=/secure/outside-project/install-signing-private.pem
```

Formal release modeでは:

- dirty tree不可
- production signing private key必須
- PHP 7.4 / 8.0 / 8.2 / 8.5 matrix必須
- Installer candidateはproduction public keyでverify
- 失敗・SKIPをRelease PASSへ読み替えない

生成物:

```text
build/local-release/
  release-report.md
  logs/
  artifacts/
    installer/
    test-update/
```

## AI Release Gate

Local automated gateの前後でAIは次を確認する。

### Before

- final diffの目的とscope
- 意図しないファイル変更
- required installed/source/distribution file list
- migration / backward compatibility
- protected dataへの影響
- security boundary
- Release Noteの対象version / transition
- official siteのNews / docs更新要否
- one-file Installer / Browser Updateへの影響
- Workspace / browser clientからInbox APIへ接続する変更が含まれる場合、CORS / OPTIONS preflightの配布物反映

### After

AIは `release-report.md` とfinal diffを照合し、

- FAIL / PARTIAL / SKIPPEDがないか
- artifact名/versionの一致
- known limitation
- Human Gate項目
- 実サーバー試験結果

をRelease checklistへまとめる。

AI確認は自動テストをPASSへ変更できない。

## Human Gate

正式Release前後で人間が確認する。

### Release前

- Pre-Release Production Smoke
- 対象機能の実ブラウザ確認
- 外部サービスを使う機能の実環境確認
- 復元が必要なsmoke testでは元状態への復元

Social Publishingなら少なくとも:

- Publisher / Postから記事公開
- Bluesky実投稿
- external card
- 記事更新時の再投稿防止
- draft非投稿

WorkspaceなどブラウザクライアントからInbox APIへ直接接続するReleaseでは、追加で以下を確認する。

- 配布物に `core/InboxApiCors.php` と対応する `post/inbox/api/index.php` が含まれる
- 許可Originからの `OPTIONS /post/inbox/api/` がトークン認証より先に処理される
- preflightが `204` を返す
- `Access-Control-Allow-Origin` が要求Originと一致する
- `Access-Control-Allow-Methods` に `GET, POST, OPTIONS` が含まれる
- `Access-Control-Allow-Headers` にWorkspaceが使用するTomosヘッダーが含まれる
- 未許可Originは拒否される
- CORS対応後も従来のPublisher token認証が維持される

### 公開時

- VERSION / tag / GitHub Release一致
- production-signed artifact upload
- 同version immutable assetを置換しない
- official mirror同期
- `latest.json` 切替

### 公開後

- 公開URLからartifactを再取得
- SHA-256 / manifest / signatureを再検証
- supported old versionからBrowser Update
- runtime必須ファイル確認
- protected data保持
- HTTP起動
- one-file Installer smoke test

これらが完了して初めて `PUBLIC RELEASE COMPLETE` とする。

## GitHub Actionsとの関係

GitHub Actionsは廃止しない。

```text
Local Release Gate = Releaseを自力で完結できる基盤
GitHub Actions      = 独立した追加検証
```

Actionsが利用可能な場合はfinal HEADで実行する。

Actionsがquota、障害、外部要因で利用できなくても、Formal local release gateとHuman GateがすべてPASSしていればRelease作業を継続できる。

## Actionsとの対応関係

| GitHub Actions | Local Release Gate |
| --- | --- |
| Core regression | PHP lint + dependency preparation + Distribution + `tests/*_check.php` |
| Installer release dry-run | ephemeral key + `build-installer-release-candidate.sh` + Phase 5 |
| Test Update release candidate | `release_transition_check.php` + ZIP/SHA検証 |
| Passkey compatibility | `TOMOS_PHP_MATRIX` |
| Public artifact release gate | 公開後Human/Public artifact Gate。公開URLが必要なためローカル事前Gateとは分離 |

## 運用上の狙い

通常は小さなcommitごとにRelease Gateを回さない。

```text
開発
 -> AIによる差分整理
 -> 変更をまとめる
 -> local check
 -> 実サーバー確認
 -> final HEAD固定
 -> formal local release gate
 -> merge / tag / Release
 -> public artifact Human Gate
 -> Actions利用可能なら独立再確認
```

これによりGitHub Actions利用時間を抑えながら、Release品質をActionsの可用性に依存させない。


## Formal release artifacts

`--mode=release` はtest用Updateに加えて、直前の正式公開versionから対象versionへの **production Browser Update ZIP** を本番署名鍵で生成する。

v1.0.6では以下を生成・検証する。

- `build/local-release/artifacts/update/tomos-update-1.0.5-to-1.0.6.zip`
- `build/local-release/artifacts/update/SHA256SUMS`
- production public keyによるUpdate manifest signature verification
- Installer 6資産（Distribution ZIP / manifest / signature / installer / pointer / checksum）

test用 `artifacts/test-update/` はephemeral test keyで署名されるため公開しない。
