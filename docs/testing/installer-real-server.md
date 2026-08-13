# 1ファイルインストーラー代表実サーバー検証

この手順は正式公開前の検証用です。本番サイトでは実行しないでください。Codexは外部サーバーへアップロードしません。検証者はFTP/SFTPまたはサーバーのファイル管理画面だけを使い、PHPコード編集・SSH・CLI・URL query入力は行いません。

## 1. 検証用候補の作成

検証用候補は、本番 `latest.json` と本番 `install.php` から分離して作成します。署名用秘密鍵はリポジトリ外に置き、コマンドへ明示的に指定してください。

```bash
bash tools/build-installer-real-server-test.sh \
  --private-key=/secure/outside-repo/test-private.pem \
  --public-key=/secure/outside-repo/test-public.pem \
  --asset-base-url=https://<検証ホスト>/installer-test-assets
```

このコマンドは、通常配布ZIP、test keyで署名したmanifest、test pointer、test pointerを埋め込んだstandalone installerを、次の構成へ生成します。

```text
build/installer-real-server-test/
├── install.php
├── latest.json
├── SHA256SUMS
└── versioned/
    ├── tomos-<version>.zip
    ├── install-manifest.json
    └── install-manifest.sig
```

本番private keyを使う場合は、管理者が安全な環境で対応するkeyを指定します。test keyを使う場合、この候補は本番公開物ではありません。test public keyはtest版 `install.php` にだけ埋め込まれます。

## 2. サーバーへの配置

資産とinstallerを同じinstaller rootへ置かないでください。

1. 空の `installer-test-assets/` を作り、`latest.json` と `versioned/` の中身をアップロードします。
2. `latest.json` 内のURLが、実際にブラウザから取得できる検証用URLになっていることを確認します。
3. 別の空の `installer-test-app/` を作り、`install.php` だけをアップロードします。
4. 次のURLをブラウザで開きます。

```text
https://<検証ホスト>/installer-test-app/install.php
```

`installer-test-assets/` は、installerが配置するTomosの対象外にしてください。実際のURLは候補生成時の `--asset-base-url` と一致させます。

URLは次の構成になります。生成toolのversioned directory名は `v<version>` です。

```text
https://<検証ホスト>/installer-test-assets/latest.json
https://<検証ホスト>/installer-test-assets/v<version>/tomos-<version>.zip
https://<検証ホスト>/installer-test-assets/v<version>/install-manifest.json
https://<検証ホスト>/installer-test-assets/v<version>/install-manifest.sig

https://<検証ホスト>/installer-a/install.php
https://<検証ホスト>/installer-b/install.php
```

### アップロード対象

mirror側へアップロードするのは次のファイルです。

- `latest.json`
- `versioned/tomos-<version>.zip`
- `versioned/install-manifest.json`
- `versioned/install-manifest.sig`
- 任意で `SHA256SUMS`

A方式側とB方式側には、それぞれ `install.php` だけをアップロードします。mirror資産とinstaller targetを同じ空directoryへ置かないでください。

### アップロード後の確認

ブラウザまたはHTTP確認手段で、`latest.json`、manifest、signature、ZIPがすべてHTTPSで200を返すことを確認します。redirectがある場合は想定したものだけであることを確認し、directory listingは不要です。Content-Typeが一般的な値でなくても、binary取得が成功すれば記録上の問題とはしません。

## 3. A方式 正常系

空のinstaller rootで実施します。

1. 初期画面と「かんたんインストールを利用できます」の表示を確認します。
2. 「この場所に設置」を選び、インストールします。
3. 取得、署名確認、ZIP安全確認、設置が完了することを確認します。
4. 完了画面の「Tomosをはじめる」から `./setup/` を開き、Tomosの初期設定画面へ到達することを確認します。
5. `.tomos-installer/installed.json` と `disabled.json` が作成されていることを確認します。
6. `install.php` が消えた場合は自己削除成功、残った場合は再アクセスして使用済み表示・再実行不可・Tomos利用可能を確認します。

## 4. B方式 正常系

A方式とは別の空のinstaller rootで実施します。

1. 初期画面で「新しいフォルダに設置」を選びます。
2. 子directory名に `blog` を入力して実行します。
3. `blog/` が完成状態で作成され、installer root直下にTomos本体が配置されていないことを確認します。
4. 完了画面から `./blog/setup/` を開き、Tomosの初期設定画面へ到達することを確認します。
5. installed marker、disabled marker、自己削除または使用済み表示を確認します。

## 5. 再アクセス

自己削除に失敗して `install.php` が残った場合だけ実施します。使用済み表示になり、インストール処理へ戻らず、Tomosが引き続き利用できることを確認します。

## 6. recovery 1ケース

Phase 3の自動テストで、A/Bのjournal、rollback、recovery、Bのmoved forward recoveryを確認済みです。実サーバーでは、既存ファイルを改変する危険なケースを再現しません。

代表環境でrecoveryを実施する場合は、検証用候補にあらかじめ用意されたtest-only fault injection buildを使い、画面に表示される限定された検証操作だけを選びます。production `install.php` にはtest hookを含めません。test-only buildが用意されていない場合、この項目は未実施として記録し、通常の配置を重ねて実行しないでください。

## 7. fallback 1ケース

検証用 `latest.json` を存在しない検証URLへ差し替えた候補を管理者が用意し、別の空のinstaller rootで開きます。次の内容が表示され、`/start/install/` の公式手順へ到達できることを確認します。

```text
Tomosを取得できませんでした。
Tomosは通常のファイルアップロードで設置できます。
```

確認後は、検証用 `latest.json` を正しい内容へ戻します。recovery unsafeの場合は通常アップロードを重ねず、先に状態を管理者へ報告します。

## 8. 記録

| 項目 | 結果 | 備考 |
|---|---|---|
| 検証環境・日付 | 未記入 | PHP / hosting / URL |
| HTTPS・session・CSRF | 未実施 | |
| 環境診断 | 未実施 | |
| A方式 正常設置 | 未実施 | |
| A方式 `setup/`遷移 | 未実施 | |
| B方式 rename | 未実施 | |
| B方式 `blog/setup/`遷移 | 未実施 | |
| recovery 1ケース | 未実施 | |
| fallback | 未実施 | `/start/install/` |
| timeout | 未実施 | |
| permission | 未実施 | |
| disabled marker・再実行防止 | 未実施 | |
| self-delete | 未実施 | 成功 / 安全な失敗 |
| 総合判断 | 未実施 | Go / 条件付きGo / No-Go |

実サーバー確認が完了するまで正式Release Goとは判定しません。

## 実サーバー最終検証結果

以下は人間が実測後に記入します。作業時点では未実施です。

環境：未記入
検証日：未記入
Tomos version：未記入
Installer version：未記入

### A方式

- [ ] 初期画面
- [ ] 環境診断
- [ ] pointer / manifest / signature / ZIP取得
- [ ] signature検証
- [ ] ZIP検証
- [ ] 配置
- [ ] `installed.json`
- [ ] `disabled.json`
- [ ] 完了画面
- [ ] `./setup/`遷移
- [ ] Tomos setup画面

self-delete：未実施（成功 / 安全な失敗）
timeout：未実施（なし / あり）
permission特殊対応：未実施（なし / あり）

### B方式

- [ ] child validation
- [ ] target child事前不存在
- [ ] pointer / manifest / signature / ZIP取得
- [ ] verified staging
- [ ] same-filesystem rename
- [ ] target child生成
- [ ] `installed.json`
- [ ] `disabled.json`
- [ ] 完了画面
- [ ] `./blog/setup/`遷移
- [ ] Tomos setup画面

self-delete：未実施（成功 / 安全な失敗）
timeout：未実施（なし / あり）
permission特殊対応：未実施（なし / あり）

### fallback

- [ ] 通常ファイルアップロード導線表示
- [ ] `/start/install/`到達

### recovery

- [ ] 今回実施
- [ ] PoC＋Phase 3自動テストを根拠として省略

test-only fault injectionが安全に利用できない場合は、recoveryを実サーバーで再現しません。既存fileを改変して検証することも禁止します。

### 総合

- [ ] Go
- [ ] 条件付きGo
- [ ] No-Go

## 実サーバー後の正式公開工程

実サーバー結果がGoまたは条件付きGoの場合でも、次の順序で人間が正式公開を実施します。今回の検証では実行しません。

1. production Release Candidateを本番署名で生成する。
2. versioned mirrorへZIP、manifest、signatureをimmutableに配置する。
3. production `install.php` を配置する。
4. 公開URLから `verify-published-install-assets.php` を実行する。
5. SHA-256、signature、ZIP inventory、VERSION、installer hashを確認する。
6. 確認成功後にproduction `latest.json`を切り替える。
7. 公式サイトの導線を公開する。

## 公式サイトへのhandoff情報

- 正式 installer URL：`https://tomoswords.org/download/install/install.php`（公開前に最終確認）
- 通常ZIP URL：`https://tomoswords.org/download/install/v<version>/tomos-<version>.zip`
- latest pointer：`https://tomoswords.org/download/install/latest.json`
- versioned manifest：`https://tomoswords.org/download/install/v<version>/install-manifest.json`
- versioned signature：`https://tomoswords.org/download/install/v<version>/install-manifest.sig`
- fallback：`https://tomoswords.org/start/install/`
- SHA-256：installerおよび通常ZIPの確認情報として公開候補に含める。一般利用者の必須操作にはしない。
- GitHub Release：補助配布・参照先として扱い、installerの通常取得先は公式mirrorとする。
- 導線：「かんたんインストール」と「通常のファイルアップロード」を併記し、自動install非対応環境を行き止まりにしない。
