# Tomos

Tomosは、Markdownで書いた文章を自分のWebサイトとして公開するための、軽量なMarkdown公開エンジンです。

現在のバージョンは `v0.1.0-alpha.2` です。限定テスト用の初期開発版であり、正式リリース版ではありません。

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

## 動作環境

- PHP 7.4以上
- `.htaccess` と `mod_rewrite` が利用できるApache系Webサーバーを推奨
- `config.php` と `cache/` への書き込み権限
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

## v0.1.0-alpha.1からの更新

`v0.1.0-alpha.2` では、フォルダー記事一覧のページングを追加し、数字だけの第1階層フォルダー名で発生するPHP 8の型エラーと、サブディレクトリ設置時にTomos Postの公開URLへ設置パスが二重に付く問題を修正しました。

既存環境では、更新前にバックアップを取り、配布Zipを別フォルダへ展開して、次の本体ファイルを同じパスへ上書きします。

- `core/App.php`
- `core/NavigationBuilder.php`
- `core/PostUpload.php`
- `core/TemplateRenderer.php`

フォルダーページングの表示には同梱テーマ側の更新も必要です。テーマを変更していない場合は、利用中の同梱テーマを配布Zipの同名テーマで更新します。テーマを独自に変更している場合は上書きせず、配布版との差分を確認してください。

`config.php`、`content/`、`cache/`、`trash/` は上書きまたは削除しないでください。配布Zip全体を既存の設置ルートへそのまま上書き展開しないでください。

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
