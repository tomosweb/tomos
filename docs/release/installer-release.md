# 1ファイルインストーラー Release手順

## 前提

- GitHub Releaseを配布物の正本とする。
- `tomoswords.org` はGitHub Releaseで署名済みの資産を配布するmirrorとする。
- 本番private keyはrepository外で管理し、GitHub Actionsには置かない。
- 公式mirrorの固定URLは以下とする。
  - Installer: `https://tomoswords.org/installer/install.php`
  - Pointer: `https://tomoswords.org/installer/latest.json`
  - versioned assets: `https://tomoswords.org/installer/releases/<VERSION>/`
- mirrorはlatestとpreviousの2世代を保持する。
- versioned assetsはimmutableとし、同じversionを上書きしない。

## 正式Releaseの完了条件

Tomosの正式Releaseは、GitHub Release作成、ZIP生成、単体テスト、mirror同期の個別成功では完了としない。

正式Releaseの最終Acceptance Gateは、**サポート対象となる既存実環境から、公開済みartifactを使用したBrowser Updateが最後まで成功すること**とする。

検証経路は次を一つのrelease transactionとして扱う。

```text
旧実環境
  -> 旧Updater
  -> 公開catalog
  -> 公開Update ZIP
  -> PHP実取得
  -> SHA-256 / 署名 / manifest検証
  -> 展開
  -> runtime必須ファイル配置
  -> 実サイト起動
  -> 保護データ保持確認
```

package builder、Updater、catalog、mirror、GitHub asset、公式Downloadページ、News、実サイト更新を別々にPASS判定してはならない。

上記の最終経路がPASSするまで `PUBLIC RELEASE COMPLETE` と報告しない。

## Release必須Gate

Release完了前に必ず以下を満たすこと。

1. 実際にRelease用として生成したUpdate ZIPを使用して検証する。
2. サポート対象の全更新元versionから更新を実行する。version番号だけでなく、実際に残り得るUpdater実装差分がある場合はUpdater実装単位でmatrix化する。
3. 各更新元に含まれる旧Updaterで公開catalogを読み込めることを確認する。
4. PHP runtimeが実際に使用する取得方式で公開Update ZIPを取得する。
5. SHA-256、署名、manifestの検証を行う。
6. 更新処理完了後にruntime必須ファイルが完成状態のpathへ配置されていることを確認する。Update ZIP内の一時配置・pending配置をruntime完成状態として扱わない。
7. `config.php`、`content/`、uploads、custom Themeその他の保護対象が保持されていることを確認する。
8. 意図的に必須ファイルを欠落させたUpdate ZIP等でrollbackが成立することを確認する。
9. 必要artifact、公開URL、実行環境が不足した場合の `SKIP` はPASSとして扱わない。必須Gateでの `SKIP` はBLOCKEDとする。
10. GitHub Release / mirror公開後、公開URLからartifactを再取得して最終更新テストを実行する。
11. 更新後のTomos実サイトをHTTP経由で起動確認し、主要画面が正常に表示されることを確認する。
12. 1ファイルInstallerについても公開 `latest.json`、manifest、signature、Distribution ZIP、smoke testを確認する。

いずれかが未完了、FAIL、または必須GateでSKIPの場合、Release statusはBLOCKEDとする。

## Installer Releaseの必須条件

Tomos本体の正式Releaseでは、1ファイルInstallerのmirror同期と `latest.json` の対象versionへの切替を必須タスクとする。GitHub Release、通常Distribution、Update、公式サイトの公開が完了していても、公開 `https://tomoswords.org/installer/latest.json` が対象versionを返さない状態では、そのversionのReleaseをCOMPLETEとして扱わない。

InstallerについてRelease完了前に必ず以下を満たすこと。

1. 対象versionのInstaller Release Assets 6点を生成・署名・検証し、GitHub Releaseへ公開する。
2. `tomosweb/tomos-official-site` の `Sync installer mirror` をdry-run、本番の順に実行する。
3. versioned assetsをHTTPS経由で検証する。
4. `latest.json` を対象versionへ切り替える。
5. 公開 `latest.json` が対象versionを返すことをfreshに確認する。
6. pointerからmanifest、signature、Distribution ZIPを取得・検証できることを確認する。
7. Installer smoke testをPASSさせる。

`install.php` 自体へTomosのversionを固定記述する必要はない。正式Releaseの判定対象は、固定Installerが参照する `latest.json` が対象versionを指し、その配布経路が正常に機能することである。

## 事前条件

- clean checkoutであること。
- `VERSION`、tag、GitHub Release versionが一致していること。
- `composer.lock`が対象依存versionを固定していること。
- 本番private keyがrepository外にあり、`update/public-key.pem`と対応していること。
- `tomosweb/tomos-official-site` のproduction環境に既存SFTP設定があること。
- 公開配布Release `tomosweb/tomos` を読むため、公式サイト側production環境から公開Release Assetsを取得できること。公開repositoryへのwrite credentialは使用しない。

## Release候補生成

公開Release候補を作成する前に、対象repositoryが固定の公開配布先であることをfail-closedで確認する。

```bash
bash tools/verify-public-release-target.sh
```

本番署名はrepository外のprivate keyを指定してローカルで行う。

```bash
bash tools/build-installer-release-candidate.sh \
  --private-key=/secure/outside-project/install-signing-private.pem \
  --public-key=update/public-key.pem
```

生成先は `build/release-candidate/` で、以下の6資産が揃っていることを確認する。

- `tomos-<VERSION>.zip`
- `install-manifest.json`
- `install-manifest.sig`
- `install.php`
- `latest.json`
- `SHA256SUMS`

`install-manifest.json` のZIP URL、`latest.json` のmanifest/signature URLは、すべて `https://tomoswords.org/installer/releases/<VERSION>/` を指す。

候補生成処理ではdistribution build、Installer Phase 1〜4、署名、package verification、Phase 5相当の構造検証を行う。本番候補とは別に、`.github/workflows/installer-release-dry-run.yml` でもtest専用鍵を使った再現確認を行える。

## GitHub Release公開

1. 対象versionのtagを作成する。
2. GitHub Releaseをそのtagで作成する。GitHub Releaseを正本とする。
3. 通常のTomos配布資産に加え、上記6つのInstaller資産をRelease Assetsとしてuploadする。
4. Release Assetsの名称、size、GitHubが返すdigest、`SHA256SUMS`を確認する。
5. 本番private keyや一時的な秘密情報がRelease Assetsへ含まれていないことを確認する。

通常Releaseの同じversionのRelease Assetsを差し替えて運用しない。修正が必要な場合は新しいversionを作成する。同一versionの緊急修復が必要な場合は、通常Updateとは分離したRecovery Update仕様に従う。

## 公式mirror同期

mirror同期は `tomosweb/tomos-official-site` のGitHub Actions `Sync installer mirror` を使用する。

1. 同期対象の明示tagを指定する。
2. まずdry-runでGitHub Releaseの6 Assets、digest、`SHA256SUMS`、manifest、pointer、ZIP size/hashを検証する。
3. 本番同期時は確認値 `SYNC_INSTALLER_MIRROR` を入力する。
4. 既存のproduction SFTP設定を利用して `/installer/releases/<VERSION>/` へversioned assetsを先に配置する。
5. HTTPS経由で配置済み資産のhashを再確認する。
6. 固定 `install.php` を一時名からrenameして切り替える。
7. `latest.json` を最後に一時名からrenameし、atomicにpointerを切り替える。
8. 公開後に `latest.json`、manifest、Installerのsmoke testを実行する。
9. 成功後、latestとprevious以外の既知構成の旧世代をcleanupする。未知ファイルを含む世代は自動削除しない。

## rollback

- versioned assets配置中またはHTTPS再検証中に失敗した場合、`latest.json` は切り替えない。
- `install.php` または `latest.json` の切替後にsmoke testが失敗した場合、同期処理は事前取得した固定ファイルへrollbackする。
- Browser Updateでは、runtime必須ファイルの完成状態を検証したうえでcommitする。pending領域に存在するだけでは成功扱いにしない。
- `latest.json` の切替はSFTP renameによる上書きが実サーバーで検証済みである。
- GitHub Release Assetsは自動削除しない。必要な場合のみ人間が確認して処理する。
- versioned assetsを同名で上書きせず、修正版は新versionまたはRecovery Updateの新しいimmutable artifactとして作成する。

## 初回本番同期後の確認

初回のproduction mirror同期では、少なくとも以下を実機で確認する。

1. `https://tomoswords.org/installer/latest.json` が対象versionを返す。
2. `https://tomoswords.org/installer/install.php` が取得できる。
3. pointerからmanifest、signature、ZIPを取得できる。
4. 1ファイルInstallerからA方式・B方式のインストールが成功する。
5. setupへ遷移し、`site_url` / `base_path` が自動設定される。
6. setup完了後にTomosトップページを表示できる。

## 自動化の境界

`.github/workflows/installer-release-dry-run.yml` はtest専用鍵でRelease候補を検証するだけで、本番署名・Release公開・mirror同期を行わない。

本番private keyはGitHub Actionsへ置かない。GitHub Actionsが担当するのは、すでに本番署名済みでGitHub Releaseへ公開されたAssetsを検証し、公式mirrorへ同期する工程までとする。

Release自動化は、必須Gateを実際に実行していないのにPASS相当の終了コードを返してはならない。必要artifactや環境が存在しない場合はfail-closedでBLOCKEDとする。
