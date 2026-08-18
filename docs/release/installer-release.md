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

## 事前条件

- clean checkoutであること。
- `VERSION`、tag、GitHub Release versionが一致していること。
- `composer.lock`が対象依存versionを固定していること。
- 本番private keyがrepository外にあり、`update/public-key.pem`と対応していること。
- `tomosweb/tomos-official-site` のproduction環境に既存SFTP設定があること。
- private `tomos-dev` Releaseを読むため、公式サイト側production環境に `TOMOS_RELEASE_READ_TOKEN` が設定されていること。fine-grained tokenを使う場合は対象repositoryを `tomosweb/tomos-dev` に限定し、ContentsをRead-onlyとする。

## Release候補生成

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

同じversionのRelease Assetsを差し替えて運用しない。修正が必要な場合は新しいversionを作成する。

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
- `latest.json` の切替はSFTP renameによる上書きが実サーバーで検証済みである。
- GitHub Release Assetsは自動削除しない。必要な場合のみ人間が確認して処理する。
- versioned assetsを同名で上書きせず、修正版は新versionで作成する。

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
