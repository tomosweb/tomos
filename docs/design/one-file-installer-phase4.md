# 1ファイルインストーラー Phase 4 設計・実装

## 1. Phase 4の責務

Phase 4はPhase 1〜3の取得、検証、staging、配置、rollbackを一般利用者向けの同期HTTP画面へ統合する。開発用ソースはclass分割するが、利用者へ渡す成果物は`build/install.php`の単一PHPファイルとする。

GitHub Release、latest pointer本番公開、CI署名、公式サイト変更、実サーバー検証はPhase 5の責務であり、本Phaseでは行わない。

## 2. 利用者フロー

1. `install.php`を設置してGETする。
2. 前回未完了transactionをlock取得後にrecoveryする。
3. 環境診断結果を確認する。
4. 「この場所に設置」または「新しいフォルダに設置」を選ぶ。
5. POST、CSRF、bootstrap owner、lockを検証する。
6. Phase 2で配布物を検証済みstagingへ準備する。
7. Phase 3でAまたはBを配置する。
8. installed marker、disabled markerをatomicに作成する。
9. installer自身の削除をbest-effortで試行し、完了画面を表示する。

初期版は同期POSTとする。長時間処理、PHP timeout、shared hostingの実測はPhase 5で確認する。

## 3. UIと設置場所

通常画面にはTomosの設置目的、2つの設置場所、child directory入力、送信ボタンだけを表示する。child入力はB選択時だけ表示し、server-sideで半角英数字開始、英数字・`-`・`_`、1〜64文字に制限する。絶対path、slash、backslash、日本語名は受け付けない。

JavaScriptはchild欄の表示と二重click抑制だけに使い、POSTの安全性や設置可否の根拠にはしない。semantic HTML、label、keyboard focus、`lang="ja"`、responsive CSSを単一file内に含める。

## 4. 環境診断とfallback

Phase 2のdiagnosticsを画面へ接続する。PHP、HTTPS、OpenSSL、ZipArchive、downloader、session、random_bytes、書込み、lock等のNGは「かんたんインストール非対応」と表示する。Tomos自体が利用できないとは表示しない。

取得失敗、署名・ZIP検証失敗、設置先衝突、B rename失敗も、可能な場合は公式の通常アップロード手順へ案内する。fallback URLは`InstallerApplication::FALLBACK_URL`またはtest configで差し替える。`recovery_unsafe`と`rollback_failed`だけは既存物へ重ねてアップロードしないよう、fallbackリンクを通常表示せず停止する。

## 5. bootstrap、session、CSRF

Phase 2の初回session owner＋15分bootstrapをそのまま使用する。session cookieはHTTPS、Secure、HttpOnly、SameSite=Laxとする。CSRF tokenは別の256-bit random tokenとして`hash_equals()`で確認し、CSRFは第三者の直接アクセス認証とは扱わない。lockは同時実行防止であり認証ではない。

別sessionには一般的な「最初に開いたブラウザから操作してください」を返す。第三者が正規利用者より先にGETしてownerになる残存リスクは、重いアカウント認証を導入せずPhase 5へ引き継ぐ。

## 6. 一般向けerror mapping

内部error codeは画面の詳細実装へ漏らさず、次のカテゴリへ変換する。

- 環境、session、lock、設置先問題：かんたんインストール非対応
- pointer、manifest、signature、ZIP取得失敗：Tomosを取得できない
- 署名、hash、path、ZIP内容検証失敗：配布データを安全に確認できない
- rollback/recovery unsafe：自動復旧を停止した

診断コードは時刻単位のhashから生成し、session ID、CSRF、cookie、path、stack trace、credentialを含めない。

## 7. A方式の公開window

Phase 4では一時deny fileを追加しない。`.htaccess`を先行作成すると、既存file上書き禁止、Apache以外で無効、nginx等の環境差、正式`.htaccess`との同一transaction識別が増え、安全なrollback境界を複雑化するためである。

代わりにPhase 3の全衝突検査、exclusive create、内部file先行、`.htaccess`、`index.php`最後の順序を維持する。公開windowは残存リスクとして明示し、Phase 5で代表サーバーの一時抑制を別途検討する。

## 8. recovery UI

起動時の正常cleanupは「前回のインストールは完了しませんでした。安全に初期化しました」と表示する。Bのmoved forward recoveryはinstalled markerを再確認し、disabled markerを作成して完了扱いにする。hash mismatch、symlink化、root fingerprint不一致、journal破損等は「自動復旧できないため、この場所へ新しいファイルをアップロードしないでください」と表示する。

## 9. marker、無効化、自己削除

配置成功後のPhase 3 `installed.json`とは別に、`<root>/.tomos-installer/disabled.json`を作成する。disabled markerはschema version、時刻、Tomos version、transaction ID、reason、installer versionを持ち、temp fileからrenameしてatomicに確定する。marker作成失敗は成功扱いにしない。

成功順序は、配置、installed marker、transaction完了、disabled marker、自己削除試行である。自己削除はC案として、厳密にroot直下の`install.php`だけを対象にbest-effortで`unlink()`する。削除失敗はinstall failureにせず、disabled markerが再実行を止める。再アクセス時はHTTP 410相当で使用済み画面を表示する。

## 10. 完了画面

完了画面には「Tomosのインストールが完了しました」と表示し、Host headerを使わず相対URLの「Tomosをはじめる」を出す。現行Tomosの初回導線は`setup/`であるため、Aは`./setup/`、Bは`./<child>/setup/`へ遷移する。自己削除できなかった場合だけ、可能なら`install.php`を削除するよう注意を表示する。

## 11. single-file build

開発用classは次のcommandで単一fileへbundleする。

```bash
php tools/build-installer.php
php -l build/install.php
```

build toolは依存classを固定順で連結し、外部`require`、private key、fixture URL、localhost、test-only fault hookを出力から拒否する。公開鍵は`update/public-key.pem`と一致するsourceだけをbundleする。生成fileにはsource treeのabsolute pathやComposer依存を埋め込まない。

`tools/installer/install.php`は開発用entrypointであり、利用者へ配布するsingle-file成果物ではない。

## 12. cleanupとproduction/test boundary

成功・通常失敗ではPhase 2のwork、download、staging、完了journalを既存物へ触れずcleanupする。recovery unsafeのjournalは調査のため残す。disabled markerとinstalled markerは残す。

fault injection、fixture transport、test key、localhost allowlistはテストソースに限定し、single-file buildでは除去・拒否する。UpdateService、Update ZIP、Update manifest、Update signature、通常配布ZIP仕様は変更しない。

## 13. テスト

`tests/installer_phase4_check.php`で次を確認する。

- 初期UI、A/B選択、内部用語非表示
- A/Bのfixture配布物取得から配置、marker、完了URL
- child validation
- disabled markerと使用済み画面
- 環境NGから通常アップロードfallback
- self-delete成功とdisabled marker残存

Phase 3テストでrollback、journal、B rename、recoveryを継続確認する。実サーバーのTLS、timeout、権限、self-delete、公開windowは未実施である。

## 14. Phase 5へ渡す内容と残存リスク

Phase 5では、`php tools/build-installer.php`の生成物を代表実サーバーへ置き、production pointer URL、manifest/signature/ZIP host、GitHub CDN redirect、Release asset、CI署名、self-delete、disable marker、timeoutを確認する。

残存リスクは、第三者が先にGETしてownerになること、A方式の短い公開window、shared hostingの同期処理・権限・自己削除挙動である。これらはPhase 4のlocal E2Eだけでは成功扱いにしない。
