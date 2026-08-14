# 1ファイルインストーラー Release手順

## 事前条件

- clean checkoutであること
- `VERSION`、tag、Release versionが一致していること
- `composer.lock`が対象依存versionを固定していること
- 本番private keyがrepository外にあり、公開鍵と対応していること
- 公式サイトmirrorのversioned deploy先が準備済みであること

## 候補生成

```bash
bash tools/build-installer-release-candidate.sh \
  --private-key=/secure/outside-project/install-signing-private.pem \
  --public-key=update/public-key.pem
```

候補生成後、`build/release-candidate/SHA256SUMS`、manifest、signature、ZIP、installerを確認する。

## 公開と検証

1. GitHub Releaseを対象tagで作成する（既存Update assetは従来どおり扱う）。
2. `tomos-x.y.z.zip`、`install-manifest.json`、`install-manifest.sig`、`install.php`をversioned Releaseへuploadする。
3. 同じ3資産を公式サイトの`/download/install/vx.y.z/`へimmutableにdeployする。
4. 公開URLから4資産を再取得し、local hash、signature、ZIP inventory、VERSIONを比較する。
5. すべて成功した場合だけ、公式サイトの`/download/install/latest.json`を切り替える。
6. installer URL、fallback URL、完了後の`setup/`遷移を確認する。

一部upload・再検証失敗・pointer deploy失敗時は、`latest.json`を更新しない。既存versioned assetを同名で上書きせず、修正版は新versionで作成する。

## 中止と復旧

候補生成、署名、公開後再検証のどこで失敗したかを記録する。pointerが未更新なら利用者への導線は旧versionのままである。誤ったpointerを切り替えた場合は、検証済みの前version pointerへ戻す。GitHub Release asset自動削除は行わず、必要なら人間が確認して処理する。

## Phase 5の制約

この手順は本番Releaseを自動実行しない。`.github/workflows/installer-release-dry-run.yml`はtest専用鍵を使用し、Release upload、公式site deploy、latest切替を行わない。本番secretと承認付きdeploy jobは、代表実サーバー確認後に接続する。
