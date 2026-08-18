# ブラウザ更新 Phase 1A

Phase 1A は、Tomos 本体の `/update/` から公式の更新カタログを取得し、現在のバージョンから次に進める 1 ステップを判定する基盤です。更新 ZIP のダウンロード、確認、適用、UI 変更はこの段階では行いません。

## 更新経路

更新は逐次更新です。たとえば `alpha.17` から `alpha.20` が公開されていても、1 回の操作で進むのは `alpha.18` だけです。次の操作で `alpha.19` を判定します。カタログに現在版を `from` とするレコードがなければ、Phase 1A では「次の更新なし」とします。これは将来「最新版」と「更新経路なし」を区別できる戻り値へ拡張できます。

手動 ZIP 更新は恒久的な正式ルートとして残ります。将来の構成は、公式オンライン取得と手動 ZIP アップロードの双方を既存 `UpdateService` の確認・`apply()` へ接続します。`UpdateService` の署名検証、manifest 検証、SHA-256、対象パス検証、backup、rollback、`UpdaterSelfUpdate` はこの Phase 1A では変更しません。

## カタログ

取得元は固定の `https://tomoswords.org/assets/updates/catalog.json` です。`schema: 1`、`product: Tomos`、`updates` 配列を要求します。各レコードは `from`、`to`、`package_url`、64 桁の `sha256` を持ち、`from < to`、`from` の重複なし、公式 HTTPS URL であることを検証します。さらに、あるレコードの `from` と `to` の間に別レコードの `from` が存在する場合、そのレコードを中間版の飛び越しとして拒否します。これはcatalog内部の整合性チェックであり、catalogに掲載されていない世の中のリリースまでは推測しません。

カタログの SHA-256 は、将来取得する ZIP の輸送・配布物確認用であり、カタログ自体は ZIP の真正性の root of trust ではありません。最終的な信頼判定は、従来どおり `update/public-key.pem` と ZIP 内の `manifest.json` / `manifest.sig` による署名検証です。

## 取得の安全性と責務

`core/UpdateReleaseProvider.php` にネットワーク取得とカタログ解釈を閉じ込めています。cURL または HTTPS stream を使い、TLS peer/hostname 検証、リダイレクト自動追従禁止、各リダイレクト先の再検証、リダイレクト回数上限、64 KiB の応答サイズ上限、HTTP 2xx のみ成功を適用します。`package_url` は HTTPS、`tomoswords.org`、userinfo/fragment なし、想定外 port なしを要求します。

この機能は installer とは独立しています。installer の `/installer/latest.json` や installer クラスは使用しません。更新 ZIP のダウンロード・適用と `/update/` UI への接続は後続フェーズです。
