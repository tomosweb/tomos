# ブラウザ更新 Phase 1B

Phase 1B は、Phase 1A の `UpdateReleaseProvider` が返した公式 `package_url` から Update ZIP を取得し、呼び出し側が指定した一時ファイルへ保存する取得機能です。まだ `UpdateService` へ渡さず、`inspectStaged()`、`apply()`、`/update/` UI への接続も行いません。

`UpdatePackageDownloader` は HTTPS/TLS 検証、公式ホスト制限、リダイレクト先の再検証、リダイレクト回数制限、50 MiB の受信上限、Content-Length 整合性、SHA-256 整合性、exclusive create、失敗時の一時ファイル削除を担当します。cURL を優先し、利用できない場合は HTTPS stream を使用します。installer とは独立したクラスです。

Phase 1B の SHA-256 は配布物・転送の整合性確認です。catalog の SHA-256は最終的な信頼の root ではありません。Phase 1C 以降、既存の `update/public-key.pem` と Update ZIP 内 `manifest.json` / `manifest.sig` による `UpdateService` の署名検証が最終的な信頼判定になります。

手動 ZIP 更新の経路には影響しません。正式な一時 staging 構造や `UpdateService` との接続は後続フェーズで行います。
