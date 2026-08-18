# 1ファイルインストーラー Phase 2 実装仕様

## 1. Phase 2目的

Phase 2の境界は、固定latest pointerからversioned manifest／signature／通常配布ZIPを取得し、署名・size・hash・ZIP安全性を検証して、設置root直下のhidden work内へ完全検証済みstagingを作ることとする。target rootへのTomos file配置、A/B配置、journal、rollback、自己削除、完成UIは実装しない。

## 2. bootstrap所有確認方式

方式A「初回アクセスsession所有＋短時間bootstrap」を採用した。最初のGETでsessionを開始し、session IDを再生成したうえで、15分間のbootstrap stateを保存する。POSTは同じsessionにbootstrap stateがあり、有効期限内である場合だけ受け付ける。

これは正規利用者のsessionを第三者sessionから操作することを防ぐための軽量な所有確認である。installer URLを知る第三者が正規利用者より先にGETした場合、その第三者が最初のownerになる可能性は防げない。installer自身へ長いtokenを手入力させる方式、アカウント登録、メール認証、外部認証、追加marker uploadは採用しない。運用上はinstallerを空directoryへ短時間だけ置く。

CSRFは別責務であり、第三者サイトから正規ブラウザを操作させる攻撃を防ぐ。lockは同時実行を防ぐだけで認証ではない。

## 3. session / CSRF

`InstallerSecurity`はHTTPS前提、strict session mode、cookie-only、Secure、HttpOnly、SameSite=Laxを設定する。bootstrap成立時に`session_regenerate_id(true)`を行い、CSRF tokenは`random_bytes()`で生成する。状態変更はPOSTを呼び出し側で要求し、token比較は`hash_equals()`で行う。

bootstrap owner確認とCSRF確認は別のerror code（`bootstrap_owner`、`csrf`）で失敗する。sessionだけで第三者の先行GETを防げるとは扱わない。

## 4. pointer取得とhost allowlist

productionの入口は`https://tomoswords.org/download/install/latest.json`に固定する。任意URLを利用者入力から受け付けない。テストでは`InstallerCore`の設定へfixture URLとallowlistを注入する。

Phase 2のallowlistは次の3種類を分離する。

- pointer host: 初期値`tomoswords.org`
- manifest／signature host: 初期値はpointer host
- ZIP asset host: 初期値はmanifest host

URLはHTTPS、host必須、userinfo／query／fragment禁止、非標準port禁止、IP literal禁止、最大2048 bytes、allowlist完全一致とする。GitHub Release CDNのredirect hostは現在の一次情報・実測なしに推測で追加しない。Phase 5で公式配布基盤と実際のredirect先を確定する。

pointerは署名の信頼起点ではない。pointer JSONをschema検証した後、manifestとsignatureを取得し、manifest生byteを既存公開鍵で検証してからmanifest JSONをdecodeする。pointerが改ざんされて別の未署名manifestを指しても、signature検証で停止する。

## 5. downloader

`InstallerDownloader`はcURLを優先し、利用できなければ`allow_url_fopen`のHTTPS streamへfallbackする。どちらも使えなければ`environment`で停止する。

- SSL peer／certificate検証は常時有効
- connect timeout 10秒、total timeout 120秒
- redirectは最大3回、各redirect後にHTTPSとhost allowlistを再検証
- pointer 64 KiB、manifest 2 MiB、signature 16 KiB、ZIP 50 MiBのhard limit
- response bodyはmemoryへ全保持せずdestinationへstream
- HTTP statusは2xxのみ成功
- Content-Lengthが存在する場合は実byte数と比較
- partial file、timeout、DNS／TLS、write failure、size超過時はdestinationを削除

Phase 2は同期処理とする。shared hostingの`max_execution_time`が120秒未満の場合はdiagnostics warningを出し、代表実サーバー確認をPhase 5へ残す。

## 6. manifest／signature検証順序

1. pointerを取得しschemaとhostを検証
2. manifest生byteを一時fileへ取得
3. signatureを一時fileへ取得
4. embedded public keyでRSA/SHA-256署名を検証
5. 署名成功後にmanifest JSONをdecodeしschema検証
6. pointer versionとmanifest versionを比較
7. 署名済みmanifestのasset URLをhost allowlistで検証
8. ZIPを一時fileへstream取得
9. hard size、manifest size、ZIP SHA-256を確認
10. `InstallManifest::verifyZipAgainstManifest()`で全entry、path、type、limits、file hashを確認
11. `InstallManifest::extractVerifiedZip()`でentryごとにstream展開
12. 展開後inventory、size、hash、required filesを再確認

署名検証前にmanifestのasset URLを信頼してZIP取得しない。Phase 1の検証処理をWeb coreでも呼び出し、CLIと別のZIP安全ルールを持たせない。

## 7. 公開鍵

`tools/installer/InstallerPublicKey.php`へ、Phase 1／Updateの`update/public-key.pem`とbyte一致するPEMを埋め込んだ。Phase 2テストでSHA-256比較を行う。最終の1ファイルinstallerではこのconstantを生成物へ内蔵し、利用者に公開鍵fileの追加uploadを要求しない。

鍵rotationはPhase 1設計どおり、新鍵を内蔵した新installerを公式配布する。複数鍵の動的取得は導入しない。

## 8. ZIP取得とsafe extraction

ZIPはmanifestのasset URLだけから取得し、manifest記載sizeとhard 50 MiBの両方を満たす必要がある。`ZipArchive::extractTo()`は使用しない。

Phase 1のinventoryで、traversal、dot component、absolute／drive path、backslash、NUL、control文字、invalid UTF-8、symlink、暗号化、unsupported compression、duplicate、path collision、entry／file／total limits、manifest外／missing fileを拒否する。展開は`getStream()`、`fopen(..., 'xb')`、chunk size／hash／size確認で行い、展開後にも全file inventoryとrequired filesを確認する。

## 9. hidden working areaとstaging

設置root直下に次の構造を作る。

```text
<installer root>/
  .tomos-installer/
    .htaccess
    install.lock
    work-<128bit random>/
      downloads/
        latest.json
        install-manifest.json
        install-manifest.sig
        tomos-x.y.z.zip
      staging/
```

`.tomos-installer`とworkは0700、deny用`.htaccess`は0600相当で作成する。既存の`.tomos-installer`がsymlinkなら拒否し、既存workを再利用しない。system temp directoryは使わず、Phase 3のB方式要件に合わせて設置root配下へ作る。Phase 2成功時はstagingをPhase 3へ渡せるようworkを保持し、明示cleanupで削除する。

## 10. cleanupとlock

Phase 2はpersistent journalを持たない。request中の失敗時は、そのrequestが作ったpointer、manifest、signature、ZIP、staging、workだけを削除する。既存target fileには触れない。

`.tomos-installer/install.lock`を`flock(LOCK_EX | LOCK_NB)`で取得する。lockは認証ではなく同時実行防止であり、lock pathがsymlinkなら停止する。成功時はverified stagingを返してlockを解放する。Phase 3で配置開始時のlock／journal設計へ引き渡す。

## 11. diagnosticsとerror code

必須診断はPHP最低version、HTTPS、OpenSSL、ZipArchive、cURL／allow_url_fopen、session、random_bytes、root write、temp／staging作成、flockである。disk free、max execution timeはwarningとして返す。

内部error codeはPhase 1から拡張し、`bootstrap_owner`、`session`、`csrf`、`pointer_download`、`pointer_schema`、`manifest_download`、`signature_download`、`manifest_signature`、`manifest_schema`、`manifest_version`、`asset_host`、`asset_download`、`asset_size`、`asset_hash`、`zip_open`、`zip_path`、`zip_duplicate`、`zip_symlink`、`zip_limits`、`zip_contents`、`extract_write`、`file_size`、`file_hash`、`required_file`、`staging_create`、`lock`、`cleanup`を使用する。一般画面へstack trace、absolute path、private detailは出さない。

## 12. ローカル統合テスト

`tests/installer_phase2_check.php`は外部Internetを使わず、`InstallerDownloader`へfixture transportを注入する。latest pointer、versioned manifest、signature、ZIPをfixture mapから返し、次を確認する。

- 正常pointerからverified stagingまでの一連の処理
- embedded public keyとUpdate公開鍵の一致
- session bootstrap、CSRF、期限切れ、別session
- pointer選択先の署名済みmanifest改変停止
- disallowed pointer host
- redirect成功とdisallowed redirect
- request失敗時のwork cleanup
- target rootが未変更であること

実HTTP、TLS、shared hosting固有の挙動はこのfixtureテストで代替せず、Phase 5の代表実サーバー確認事項とする。

## 13. Phase 3へ渡すinterface

`InstallerCore::prepare()`は成功時に次を返す。

- `verified_staging_path`
- `manifest`
- `version`
- `transaction_candidate_id`（work random ID）
- `installer_root`
- `selected_mode`（Phase 2ではnull）
- `work_path`

Phase 3はこのstagingを入力としてA/B配置を行う。Phase 2 coreはtarget rootへfileを配置しない。

## 14. 残存リスクと実サーバー確認

- 第三者がinstallerへ先にGETした場合、初回session所有だけでは先行取得を防げない。
- GitHub Release CDN redirect hostは未確定であり、Phase 5まで公式host以外を許可しない。
- cURL／allow_url_fopen、TLS、120秒timeout、disk／権限差はshared hostingで未確認。

## 15. Phase 3開始条件

ローカルではPhase 3へ進める。実サーバーのTLS／download／timeout確認と、第三者先行アクセスの運用限界については条件付きで持ち越す。Phase 3ではA/B配置、全衝突、persistent journal、rollback、強制終了復旧、installed markerだけを追加し、downloaderと署名検証を再実装しない。
