# Tomos ドキュメント

Tomosのドキュメントは、目的別に分けています。

現在のバージョン: `v0.1.0-beta.1`

## 最短ルート

はじめてTomosを使う場合は、まず次の順番で読んでください。

1. `../README.md`
2. `../INSTALL.md`
3. `user/writing.md`
4. `../KNOWN_LIMITATIONS.md`

## 記事を書くとき

- `user/writing.md`: 記事の書き方
- `user/analytics.md`: Google Analytics 4の設定
- `user/first-content.md`: 最初に編集するファイル
- `user/faq.md`: よくある質問
- `user/pilot-post.md`: Tomos Post先行利用時の条件と操作

Tomos WriteでMarkdownを書き、Tomos Postの `/post/` から投稿し、Tomosで公開ページを確認します。

Tomos Postには、合言葉の連続失敗をIP単位で一時停止する軽量なbot対策と、投稿操作IDによる二重保存防止があります。異なる記事の正常な連続投稿に、共通の待ち時間は設けません。

## 設置で困ったとき

- `install/install.md`: インストール補足
- `install/update.md`: 既存環境の更新
- `install/tomos-update.md`: 署名済み更新ZIPを使うTomos Update
- `install/setup.md`: setup画面の説明
- `install/troubleshooting.md`: よくあるトラブル
- `install/hosting.md`: ホストサービスの注意

## 機能を知りたいとき

- `features/post.md`: Tomos Post
- `features/tags.md`: タグ
- `features/search.md`: 検索
- `features/wiki-links.md`: Wikiリンク
- `features/images.md`: 画像
- `features/themes.md`: テーマ
- `features/rss-sitemap.md`: RSS / sitemap
- `features/cache.md`: HTMLキャッシュ

## テーマを扱うとき

Tomosには次の6テーマを同梱しています。

- `tomos-minimal`
- `tomos-journal`
- `tomos-dark`
- `tomos-note`
- `tomos-blog`
- `tomos-90s`

設置後のテーマ切替は、`/post/settings/`のサイト設定画面から移動できるテーマ変更専用画面で行えます。投稿、取り下げ、trash整理、テーマ切り替えには共通の管理用合言葉を使います。

- `theme/theme-authoring.md`: テーマの作り方
- `theme/theme-validation.md`: テーマ検証
- `theme/theme-checklist.md`: テーマ確認チェックリスト
- `theme/ai-theme-prompt.md`: AIにテーマ作成を依頼するためのプロンプト

## 開発・配布向け

- `project/distribution.md`: 配布パッケージ
- `project/checklist.md`: チェックリスト
- `project/phase9-runtime-checklist.md`: 画像対応Phase 9実機回帰チェック
- `project/security.md`: セキュリティ詳細
- `project/performance.md`: 通常表示とキャッシュの計測メモ

ルートの `SECURITY.md` は外部向けの概要、`project/security.md` は開発者・設置者向けの詳細です。
