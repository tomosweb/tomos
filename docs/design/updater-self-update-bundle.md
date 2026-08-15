# Updater自己更新 bundle

v0.1.0-alpha.18では、Updater自己更新を固定2 targetのbundleとして扱います。

- `update/index.php`
- `core/UpdateService.php`

通常のTomos Update applyでは、この2ファイルを直接置換しません。Update ZIP builderは要求されたtargetを`core/updater-pending/`へ変換し、PHP本体とtarget・SHA-256を記録したmetadataを通常の署名済みmanifestへ含めます。`core/UpdaterSelfUpdate.php`は、main update完了後に`/post/update-finalize/`から明示的に呼び出されます。

反映時はoperation lockと既存`UpdateLock`を確認し、pending内の全targetを一括で検証します。target whitelist、metadataの完全性、pendingファイルの非symlink・SHA-256・PHP syntax、現在targetの実体・配置・権限を全件確認してから、全targetをbackupし、全temporaryを準備します。置換後の検証に失敗した場合は、置換済みtargetをすべて同じbackupからrollbackします。1ファイルだけ新しくなった状態を成功として残しません。

pendingは`update/index.php`だけ、`core/UpdateService.php`だけ、または両方を保持できます。ただし1 targetについてPHPとmetadataの片方だけ、whitelist外のファイル、未知のpendingファイルが存在する場合はpending不正として拒否します。SHA-256が既存targetと同じtargetは`no_change`としてbundleの成功に含めます。pendingのcleanupは結果記録に成功した後だけ行います。

alpha.17からalpha.18への移行では、旧Updaterが読める`--legacy-bridge` manifestを使い、新`core/UpdaterSelfUpdate.php`を通常targetとして適用します。同じZIPに新`core/UpdateService.php`と`update/index.php`をpendingとして含め、main update完了後にこのbundleを反映します。これによりalpha.17の旧UpdateServiceとalpha.18の新UpdateServiceが混在する時間を、finalizeのatomic operation内に限定します。

手動ZIP更新と公式オンライン更新は、どちらもこのmain update・pending・finalize経路を使用します。Updater bundleは任意のcoreファイルを更新する機構ではなく、許可targetを固定した自己更新処理です。
