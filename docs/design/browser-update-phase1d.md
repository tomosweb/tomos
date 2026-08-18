# ブラウザ更新 Phase 1D

Phase 1D で `/update/` に公式オンライン更新を接続しました。更新経路は、公式オンライン更新と手動Update ZIP更新の2系統です。手動ZIP更新はfallbackではなく、恒久的な正式ルートとして残ります。両経路は既存の `UpdateService` の staging、`inspectStaged()`、署名検証、summary、`apply()` へ合流します。

GETでは現在のTomos VERSIONに対するcatalog確認だけを行います。ページを開いただけではUpdate ZIPを取得しません。オンラインの「更新内容を確認」を押した認証済みPOSTでcatalogを再取得し、現在版から次の1バージョンを再判定してから、`UpdatePackageDownloader`、`stageDownloadedPackage()`へ進みます。取得後は共通確認画面で停止し、「更新する」の明示POSTで初めて既存`apply()`を呼び出します。自動更新、バックグラウンド更新、一括多段更新は行いません。

オンライン取得用の一時ファイルはWeb公開されない `storage/update-tmp/online-download-<random>.zip` に置き、成功・失敗を問わず`finally`で削除します。UpdateServiceの正式stagingは同じ `storage/update-tmp/<32hex>/package.zip` です。catalog取得、通信、SHA-256、署名・manifest検証のどこで失敗してもapplyへ進まず、オンラインエラーは手動ZIPフォームを妨げません。

catalogは次に取得すべき更新の案内であり、ZIPの真正性のrootではありません。catalogのfrom/toと、署名検証済みmanifestのcurrent/versionが一致して初めてオンライン更新候補として受理します。最終的な信頼判定は既存の `update/public-key.pem` と `manifest.sig` による署名検証です。CSRF、Post認証、rate limit、session owner、backup、rollbackは既存経路を再利用します。installerとは独立した機能です。

オンライン・手動の両経路で、署名済みmanifestの`from_version`が現在の`VERSION`と完全一致することを必須にします。`from_version`と`version`が1ステップの順序でないZIP、または旧`minimum_version`だけを持つZIPは拒否します。catalogのfrom/toとmanifestのfrom_version/versionも一致して初めてオンライン更新候補を受理します。
