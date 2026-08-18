# 1ファイルインストーラー Phase 3 設計・実装

## 1. Phase 3の責務

Phase 3は、Phase 2が返す完全検証済みstagingを、ネットワーク処理なしで設置する。対象はA方式（現在のディレクトリ）とB方式（新しい子ディレクトリ）、永続transaction journal、rollback、強制終了後のcleanup、installed markerである。

このPhaseでは、latest pointer、manifest、署名、ZIPの再取得は行わない。未検証のstaging pathだけを受け取って配置することも許可しない。完成UI、通常アップロードへの案内、installer自己削除はPhase 4の責務とする。

## 2. verified staging interface

`InstallerVerifiedResult`をPhase 2とPhase 3の境界に置く。`fromArray()`は、`verification_completed === true`、実在するstaging、installer root、manifest、Tomos version、32桁のtransaction candidate IDを検証する。

受け渡す値は次のとおり。

- `verified_staging_path`
- `installer_root`
- `version`
- `manifest`
- `transaction_candidate_id`
- `selected_mode`
- `child_directory`
- `verification_completed`

Phase 3は受け取ったmanifestとstagingを配置直前にも再検証する。Phase 2の結果を配列に戻して改変した場合は拒否することが、将来のWeb実装でも必要である。

## 3. A方式

### 3.1 配置前検査

manifestにある全fileと、file pathから導出した全directoryについて、配置開始前に存在を確認する。file、directory、symlink、special fileのいずれかが存在する場合は`target_collision`で中止し、transactionを開始しない。`.tomos-installer`は管理領域であり、配布物の衝突検査対象には含めない。

各file作成時にも`fopen(..., 'xb')`を使用する。事前検査後に別プロセスがfileを作成した場合でも、既存fileを上書きしない。

### 3.2 配置順序

filesystemの列挙順には依存せず、明示的な順位関数で次の順序にする。

1. 親directory
2. `core/`
3. `setup/`
4. `themes/`
5. その他の内部・補助file
6. `.htaccess`
7. `index.php`

`index.php`を最後に置くことで、公開入口が先に現れる時間を最小化する。`.htaccess`は公開入口より前に配置するが、Webサーバーの設定反映は環境差があるため、これだけで保護できるとは扱わない。

### 3.3 file作成とrollback

各fileはjournalへ`pending`として記録してからexclusive createする。stream copy後にsizeとSHA-256を検証し、成功したentryを`created`、次に`verified`へ更新する。directoryも作成前にpending、作成後にcreatedとして記録する。

通常失敗時は、今回作成したfileを逆順に、directoryを深い順に削除する。削除前にroot fingerprint、relative path、非symlink、file type、期待size、期待SHA-256を確認する。hashが変わったもの、symlink化したもの、既存か今回作成か判別できないものは削除せず、`rollback_failed`または`recovery_unsafe`としてjournalを残す。

## 4. transaction journal

格納場所は次のとおりで、staging内には置かない。

```text
<installer-root>/.tomos-installer/
  install.lock
  installed.json
  transactions/<transaction-id>/journal.json
  work-<transaction-id>/
```

journalの主要schemaは以下である。

```json
{
  "schema_version": 1,
  "transaction_id": "0123456789abcdef0123456789abcdef",
  "installer_version": "phase3",
  "mode": "current",
  "state": "placing",
  "tomos_version": "0.1.0",
  "root_fingerprint": "sha256...",
  "manifest": {},
  "created_files": [
    {"path": ".htaccess", "size": 12, "sha256": "...", "state": "verified", "sequence": 1}
  ],
  "created_directories": [],
  "target": ".",
  "target_child": null,
  "staging_relative": null,
  "work_relative": ".tomos-installer/work-0123456789abcdef0123456789abcdef",
  "started_at": "2026-01-01T00:00:00+00:00"
}
```

JSONは同じdirectory内のrandom temp fileへ書き、flush（利用可能ならfsync）、close後にrenameする。`journal.json`をtruncateして直接書き換えない。書き込みの途中でプロセスが終了しても、旧journalまたは新journalのどちらかが残ることを狙う。

## 5. B方式

Bでは、Phase 2のstagingがinstaller root配下にあることを確認し、target childと同じfilesystemにあることを`stat()['dev']`で確認する。system tempは使わない。

```text
<root>/
  .tomos-installer/work-<id>/staging/
  .tomos-installer-stage-<id>/  # stagingを同じroot配下へ移した一時sibling
  blog/                         # 最終target
```

`work-<id>/staging`から`.tomos-installer-stage-<id>`へのrenameも、最終siblingから`blog`へのrenameもdirectory `rename()`を使う。cross-filesystem copyへの自動置換はしない。rename失敗時はA方式へfallbackせず、Bだけを中止してPhase 4の通常アップロード導線へ渡す。

Bのstateは`prepared` → `ready_to_move` → `moved` → `verified` → `complete`とする。rename成功後はsource不存在、target存在、targetがsymlinkでないことを確認し、manifest全fileをtarget上で再検証する。

## 6. B方式のrecovery

`prepared`または`ready_to_move`でtargetがなくsourceだけが残る場合は、sourceをcleanupして再試行可能とする。targetが予期せず存在する場合は削除しない。

`moved`または`verified`でsourceがなくtargetが存在する場合は、directory renameが一括commitであり、target全体が完成状態として残るため、manifest再検証後にinstalled markerを書いて完了へ進めるforward recoveryを採用する。再検証に失敗した場合はtargetを自動削除せず、`recovery_unsafe`を返す。これにより、rename直後の強制終了で完成したTomosを不用意に消さない。

## 7. installed marker

markerは`<root>/.tomos-installer/installed.json`にatomic writeする。

```json
{
  "schema_version": 1,
  "tomos_version": "0.1.0",
  "transaction_id": "0123456789abcdef0123456789abcdef",
  "mode": "current",
  "completed_at": "2026-01-01T00:00:00+00:00"
}
```

Bでは`target_child`も記録する。Aでは`index.php`を配置し全検証を終えた後にmarkerを書く。marker作成に失敗した場合は、今回作成したAのfile/directory、またはBのtargetを安全条件付きでrollbackする。既に作成したものを残したまま成功扱いにはしない。

## 8. 強制終了後のrecovery

次回のinstaller起動時にroot lockを取得してtransactionsを走査する。journalのschema、transaction ID、root fingerprint、manifestを検証し、resumeはしない。

Aはpending/created/verified entryについて安全に今回作成したと確認できるものだけを逆順削除する。対象が不存在なら削除処理を飛ばせるが、hash mismatch、symlink、unexpected type、root fingerprint不一致、journal破損があれば自動cleanupを停止してjournalを残す。

Bは前節のとおり、ready-to-moveはsource cleanup、movedはtarget再検証によるforward recoveryとする。すべてのcleanup後にwork directoryとjournalを削除する。管理領域そのものやinstalled markerはPhase 3では削除しない。

## 9. root fingerprintと安全境界

fingerprintはcanonical filesystem rootとdevice情報からSHA-256で計算する。Host header、URL、利用者入力は使わない。journalを別rootへコピーした場合やrootが変わった場合は自動削除しない。

rollbackで削除するpathは安全なrelative pathに限定し、`.tomos-installer`管理領域を対象にしない。管理領域のrecursive cleanupも、既知のtransaction/work pathに限定する。

## 10. error code

配置・復旧では次を使用する。

- `placement_verify`
- `target_collision`
- `target_exists`
- `target_symlink`
- `transaction_create`
- `transaction_write`
- `placement_create`
- `placement_verify`
- `rollback_failed`
- `recovery_unsafe`
- `root_fingerprint`
- `rename_failed`
- `rename_state`
- `post_move_verify`
- `installed_marker`
- `lock`
- `simulated`

内部error codeと一般利用者向け文言はPhase 4で分離する。

## 11. fault injectionとテスト

fault injectionはテストコードから配列で渡すだけで、HTTP入力や通常UIから有効化できない。Aではdirectory/file pending・created・verified、marker前、Bではready-to-move・rename失敗・rename直後・marker前を再現する。`InstallerSimulatedTermination`はjournalを残したままrequestが消えた状態を模擬する。

`tests/installer_phase3_check.php`で正常配置、衝突、exclusive create、rollback、hash mismatch保護、journal cleanup、B rename、B非fallback、ready/moved recoveryを確認する。Phase 1/2のテストも毎回再実行する。

## 12. Phase 4へ渡すinterface

Phase 3成功時は、配置済みTomos、installed marker、transaction完了、不要work/staging cleanup済みを返す。Phase 4はこの結果を用いて完了画面、通常アップロードfallback、disable marker、自己削除を実装する。Phase 3はtarget rootへの配置だけを担当し、network・UI・self-deleteを持たない。

## 13. 残存リスク

- PHP processがjournal更新の直前に停止するwindowでは、pending entryの扱いが保守的になり、cleanup不能状態が残る可能性がある。
- shared hostingの権限、symlink制御、Webサーバーの設定反映はローカルだけでは確定できない。
- A方式ではfile単位公開の時間が存在するため、最終的な公開開始・自己無効化のUXはPhase 4で実サーバー確認が必要である。
