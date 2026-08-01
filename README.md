# Tomos

Tomosは、Markdownで書いた文章を自分のWebサイトとして公開するための、軽量なMarkdown公開プログラムです。

現在のバージョンは `v0.1.0-alpha.10` です。限定テスト用の初期開発版であり、正式リリース版ではありません。

## 主な機能

- Markdownページの公開
- 階層URL
- タグと検索
- RSSとSitemap
- Wikiリンク
- 画像表示
- PCとスマートフォン対応
- テーマ切り替え
- Tomos PostによるMarkdownと画像の投稿
- Tomos Postによる `index.md` と `about.md` のダウンロード・再投稿
- Tomos Postの記事管理から公開記事、下書き、固定ページを検索
- 既存原稿の編集用Markdownダウンロード、再投稿、競合確認
- 公開記事の更新、下書き保存、下書きからの公開
- Tomos Postによるサイト名、サイト説明、タイムゾーン、RSS、RSS対象パス、Sitemapの設定変更
- Tomos Updateによる署名済み更新ZIPからの本体更新

## 動作環境

- PHP 7.4以上
- `.htaccess` と `mod_rewrite` が利用できるApache系Webサーバーを推奨
- `config.php` と `cache/` への書き込み権限
- Tomos Updateを使う場合はPHP ZipArchive / OpenSSL拡張と `storage/` への書き込み権限
- 画像処理を行う場合はPHP GD拡張を推奨

データベースやComposerは必要ありません。

## 記事を書く

[Tomos Write](https://tomoswords.org/write/)は、ブラウザ上でMarkdownの記事を作成するための補助ツールです。Tomos Writeを使わず、任意のMarkdownエディタで記事を作成することもできます。

## インストール

1. 配布ファイルを空のテスト用ディレクトリに展開します。
2. `index.php` と `.htaccess` が設置ディレクトリ直下にあることを確認します。
3. 初回アクセス時にsetup画面を開き、サイト情報とテーマを設定します。
4. setup完了時に表示される管理用合言葉を安全な場所に保存します。
5. 動作確認後、`setup/` を削除または公開領域外へ移動します。

すでにTomosを設置している場合は、データを保持するために[既存環境の更新手順](docs/install/update.md)を確認してください。

Tomos Updateを今後も安定して提供するため、`v0.1.0-alpha.6`で署名確認に使用する信頼点を更新します。既存環境では、alpha.6への更新だけ専用の手動移行ZIPを使用します。`VERSION`と`update/public-key.pem`だけを上書きし、`config.php`、`content/`、独自テーマは上書きしません。

alpha.6への移行後は、alpha.7以降の署名済み更新ZIPをTomos Updateから利用できます。alpha.6自体の署名済みUpdate ZIPは提供しません。

設置後は、`/post/settings/`からサイト名、サイト説明、タイムゾーン、RSSの有効・無効と対象パス、Sitemapの有効・無効を変更できます。GA4測定IDと使用テーマは、サイト設定画面から移動できる既存の専用画面で変更します。

## 利用上の注意

- 必ず既存データのバックアップを作成してから設置してください。
- 重要なサイトや既存サイトへ直接上書きしないでください。
- 本番環境で使用する前に、主要ページ、投稿、検索、RSS、Sitemapを確認してください。
- `config.php`、`content/`、`themes/`、`cache/`、`trash/` を定期的にバックアップしてください。
- Tomosは現状のまま提供され、すべてのサーバー環境での動作を保証するものではありません。

## ライセンス

Tomos本体のコードはMIT Licenseで提供します。

Tomosの名称、ロゴ、アイコン、OGP画像はMIT Licenseの対象外です。

Copyright (c) 2026 Goro Kawasaki
