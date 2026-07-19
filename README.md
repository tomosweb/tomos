# Tomos

Tomos は、自分の言葉を Web に置きたい人のための、軽量な Markdown 公開エンジンです。

`content/` に Markdown ファイルを置くことで、文章中心の公開サイトを作れます。データベースや追加のパッケージ管理ツールは必須ではありません。一般的なレンタルサーバーに FTP または Zip アップロードで設置し、ブラウザの setup 画面から初期設定できます。

現在のバージョン: `v0.1.0-alpha`

> このバージョンは `v0.1.0-alpha` の限定テスト版です。
> 知人向けの動作確認・感想収集を目的としており、正式リリース版ではありません。
> 既存サイトや重要な環境ではなく、差し支えのないテスト用ディレクトリでお試しください。
> 利用前に既存ファイルのバックアップを取ってください。

## Tomos が目指すこと

Tomos は、自分の言葉を Web に置きたい人が、安く、軽く、好きな見た目で、自分の Web を持てる選択肢になることを目指します。

ここでいう「持てる」とは、単に Web 上に公開できるという意味ではありません。自分の文章を Markdown ファイルとして扱い、自分で選んだ公開場所に置き、自分の判断と管理のもとで Web として維持できる、という意味です。

Tomos が優先するのは、無料であることそのものではありません。安く、軽く、理解しやすく、長く、自分の管理で続けられることです。

詳しい開発判断の基準は [docs/principles.md](docs/principles.md) を参照してください。

## できること

- Markdown ページの公開
- 基本Markdown記法
- GFMテーブル
- 階層URL
- `draft: true` の非公開
- タグ
- 検索
- RSS
- sitemap
- Wikiリンク
- Markdown画像
- Obsidian 形式画像
- PC / スマホ対応ナビゲーション
- favicon / OGP 初期設定
- setup画面でのテーマ選択
- 標準テーマ `tomos-minimal`
- 文章中心テーマ `tomos-journal`
- ダークテーマ `tomos-dark`
- Tomos PostによるMarkdownファイル投稿
- Tomos Postによる投稿取り下げ

## 必要な環境

- PHP 7.4 以上
- `.htaccess` と `mod_rewrite` が使える Apache 系ホストを推奨
- 初期設定時に `config.php` を生成できる書き込み権限
- `cache/index/` に書き込める権限
- HTMLキャッシュを使う場合は `cache/html/` に書き込める権限
- 画像投稿でリサイズやメタデータ削除を使う場合は PHP GD 拡張を推奨
- Tomos Postの画像は最大5点、1点10MBまでです。1点ずつ順番に送信し、すべての画像が揃った後だけMarkdownを公開します

## 最初に読むもの

- [INSTALL.md](INSTALL.md): 設置方法
- [docs/user/writing.md](docs/user/writing.md): 記事の書き方
- [docs/principles.md](docs/principles.md): 開発判断の基準
- [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md): 現在の制限
- [docs/README.md](docs/README.md): ドキュメント一覧

## 基本の流れ

Tomosでは、次の流れで記事を公開します。

1. 設置する: 配布Zipを空のテスト用ディレクトリへ展開し、setupを完了します。
2. 書く: Tomos WriteでTomos用のMarkdownファイルを作ります。
3. 投稿する: Tomos PostでMarkdownファイルをTomosへ投稿します。
4. 公開を確認する: 投稿後に表示される公開URLを開き、TomosでWebページとして確認します。
5. 見た目を変える: setup時にテーマを選び、設置後は必要な時だけ `/post/` から切り替えます。

Tomos WriteはMarkdownを書く外部ツールです。Tomos Postは、作成済みのMarkdownファイルを投稿する画面です。Tomos本体は、投稿されたMarkdownをWebとして公開します。

## インストール概要

詳しい手順は [INSTALL.md](INSTALL.md) を参照してください。

1. 配布された `tomos-0.1.0-alpha.zip` を受け取ります。
2. サーバー上の空ディレクトリへ展開します。
3. `config.php` が存在しないことと、`.htaccess` が配置されていることを確認します。
4. ブラウザでトップページへアクセスします。初回設置では相対URL `setup/` へ自動的に移動します。
5. サイト名、URL、テーマなどを入力してsetupを完了します。
6. setup完了画面に表示される管理用合言葉を控えます。
7. setup 完了後は、安全のため `setup/` ディレクトリを削除またはリネームすることを推奨します。

setup画面では、利用可能なテーマを選択できます。標準テーマ `tomos-minimal`、日記・エッセイ向けの文章中心テーマ `tomos-journal`、暗い背景で読めるダークテーマ `tomos-dark` を同梱しています。テーマは `themes/` フォルダに配置します。

## 既存環境の更新

更新前に、少なくとも `config.php`、`content/`、`themes/`、`cache/`、`trash/` をバックアップしてください。配布Zipには新規設置用のサンプル記事と標準テーマが含まれるため、既存環境のルートへZip全体をそのまま上書き展開しないでください。

更新時は配布Zipを別フォルダへ展開し、次の本体ファイルだけを既存環境へ上書きします。

- `index.php`、`.htaccess`、`core/`、`post/`
- `setup/` と各種ドキュメントは必要に応じて更新
- `cache/` 配下に追加された空フォルダ、`.htaccess`、`.gitkeep` は追加するが、生成済みファイルは上書き・削除しない

`config.php`、`content/`、`themes/`、`trash/` は上書きしません。これにより、設定、Markdown、公開画像、利用中テーマ、取り下げ済みデータを維持します。更新後は主要ページとTomos Postを確認し、問題があればバックアップから戻してください。

設置後にテーマを変更する場合は、`/post/` のサイト設定から有効テーマを選択できます。テーマ変更にも管理用合言葉を使います。

テーマを追加したい場合は、[テーマの作り方](docs/theme/theme-authoring.md) と [テーマ確認チェックリスト](docs/theme/theme-checklist.md) を参照してください。AIにテーマ作成を依頼する場合は、[Tomosテーマ作成プロンプト](docs/theme/ai-theme-prompt.md) を参考にできます。

Tomos Postにより、Tomos Writeなどで作成したMarkdownファイルをブラウザから投稿できます。投稿、取り下げ、trash整理、テーマ切り替えにはsetup完了時に一度だけ表示される管理用合言葉が必要です。この合言葉はあとから画面では確認できないため、安全な場所に控えてください。

投稿済みページをWeb上から外したい場合も、Tomos Postから投稿を取り下げられます。取り下げたファイルは完全削除されず、設置ディレクトリ直下の `trash/` に移動します。`trash/` を空にすると完全削除され、元に戻せません。

Tomos Postには、合言葉失敗の連続試行や短時間の連続POSTを一時的に制限する簡易bot対策があります。

## ページの編集

トップページは `content/index.md` です。

新しいページを作る場合は、`content/` に Markdown ファイルを追加します。

```text
content/about.md        => /about
content/diary/index.md  => /diary/
content/diary/note.md   => /diary/note
```

フォルダ内に公開記事があれば、フォルダ専用の `index.md` がなくても `/diary/` のようなURLに記事一覧が表示されます。`content/diary/index.md` を用意すると、その本文と記事一覧を同じページに表示できます。

詳しい記事の書き方は [Tomosでの記事の書き方](docs/user/writing.md) を参照してください。

Tomos Writeで作成した `.md` ファイルは、Tomos Postの `/post/` から投稿できます。投稿済みページを取り下げる場合も `/post/` から公開URLまたは `content/` 内のパスを指定します。

## 注意

`.htaccess` は不可視ファイルとして扱われ、FTPソフトで見えない場合があります。アップロード漏れがあると、トップページは表示されても `/about`、`/search/`、`/feed.xml` などが Not Found になることがあります。

setup 完了後は、安全のため `setup/` ディレクトリを削除またはリネームすることを推奨します。

## ドキュメント

- [インストール手順](INSTALL.md)
- [記事の書き方](docs/user/writing.md)
- [開発判断の基準](docs/principles.md)
- [ドキュメント一覧](docs/README.md)
- [テーマの作り方](docs/theme/theme-authoring.md)
- [変更履歴](CHANGELOG.md)
- [既知の制限](KNOWN_LIMITATIONS.md)
- [セキュリティ方針](SECURITY.md)

## ライセンス

Tomosは無保証で提供されます。利用前に `DISCLAIMER.md` と `LICENSE` を確認してください。

Tomos本体のコード、同梱テーマ `tomos-minimal` / `tomos-journal` / `tomos-dark`、サンプルcontentは MIT License で提供します。

Copyright (c) 2026 Goro Kawasaki

ただし、Tomosの名称、ロゴ、アイコン、OGP画像は MIT License の対象外です。これらの扱いについては [TRADEMARKS.md](TRADEMARKS.md) を参照してください。
