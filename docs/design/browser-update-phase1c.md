# ブラウザ更新 Phase 1C

Phase 1C では、Phase 1B が保存したUpdate ZIPを `UpdateService::stageDownloadedPackage()` へ接続します。オンライン取得ZIPと手動アップロードZIPは、どちらも `storage/update-tmp/<32hex>/package.zip` の正式stagingへ入り、既存の `inspectStaged()`、署名検証、manifest検証、ファイルhash検証を再利用します。手動ZIP更新のルートは恒久的に維持されます。

オンライン入口はHTTP通信を持ちません。呼び出し元から受け取ったsourcePathを、`package.download`へstream copyし、サイズを再確認してから`package.zip`へrenameします。sourcePathは削除せず、失敗時も呼び出し元が所有します。staging失敗時はwork directory全体を削除します。

ブラウザ更新の逐次性は、catalogの`from/to`、署名済みmanifestの`from_version/version`、現在の`VERSION`をすべて照合して保証します。`summary['current_version']`と`summary['from_version']`が現在版、`summary['version']`が期待するtoと一致しなければ`update_sequence`またはmanifest検証で拒否します。catalogは次に取得すべきパッケージの案内であり、Update ZIPの真正性の最終根拠ではありません。最終的な信頼判定は既存の`update/public-key.pem`による`manifest.sig`検証です。手動ZIPも同じ検証を通るため、段階を飛ばしたZIPは適用できません。

Phase 1Cでは`apply()`、rollback、既存lock、`/update/` UI、自動更新には変更を加えません。apply時の既存の再inspect・backup・rollback実装を後続接続で再利用します。installerとは独立した機能です。
