# セキュリティ詳細メモ

この文書は、Tomosの設置者・開発者向けのセキュリティ詳細メモです。外部向けの概要はルートの `SECURITY.md` を参照してください。

## content外読み取り禁止

Tomosの公開ページは `content/` 配下の `.md` ファイルだけを対象にします。

- URLパスは `Router` と `Security::validateUrlPath()` で検証します。
- `..` セグメント、ヌルバイト、制御文字、バックスラッシュ、`:` を拒否します。
- URLデコードは複数回行い、二重エンコードされた危険パターンも検査します。
- `PageRepository` は候補パスを相対パスとして検証し、`realpath()` 後に `content/` 配下であることを確認します。
- ページとして読む拡張子は `.md` のみです。

## Markdown生HTML

Markdown本文の生HTMLは標準で無効です。

`MarkdownParser` は標準設定で本文をHTMLエスケープします。`<script>`、`onerror`、`iframe` などはHTMLとして実行されず、テキストとして扱われます。

`config.sample.php` と setup で生成される `config.php` は、`security.allow_raw_html` を `false` にします。

## テンプレートエスケープ

`TemplateRenderer` は、通常変数 `{{ variable }}` をHTMLエスケープします。

三重波括弧 `{{{ variable }}}` はHTML出力用ですが、`page.content`、`page.body`、`page.tags_html`、`nav.tree`、`nav.mobile_tree`、`nav.breadcrumbs`、`list.pages` など、core側で生成する安全HTMLだけをホワイトリストで許可します。

URL属性向けの変数は危険スキームを拒否します。`javascript:`、`data:`、`vbscript:`、プロトコル相対URL `//example.com` は安全なURLとして扱いません。

## Wikiリンク

Wikiリンクは内部ページリンク専用です。

- `[[about]]` のような内部リンクだけを公開URLに変換します。
- `[[javascript:alert(1)]]`、`[[data:text/html,...]]`、`[[//example.com]]`、`[[../config]]` は有効リンクにしません。
- draftページは存在しないページとして扱います。
- 存在しないページはリンクにせず、`.tomos-missing-link` として表示します。

## 画像解決

Markdown画像とObsidian形式画像は `ImageEmbedParser` が処理します。

- ローカル画像実体は `content/` 配下に限定します。
- ローカル画像は `realpath()` 後に `content/` 配下であることを確認します。
- `http://` または `https://` で始まる外部画像URLは、サーバー内ファイル存在確認の対象にしません。
- 許可拡張子は `jpg`, `jpeg`, `png`, `gif`, `webp` です。
- SVG、PHP、HTML、PDFは画像として表示しません。
- `javascript:`, `data:`, `file:`, `//example.com` は画像URLにしません。
- 存在しないローカル画像は `image-missing` 表示にします。

## テーマ検証

テーマはHTML/CSS/静的アセットだけを担当します。

`ThemeValidator` は以下を確認します。

- `theme.json` と必須テンプレートの存在
- `theme.json` の `name` とディレクトリ名の一致
- テーマ内PHP系ファイルの禁止
- テンプレート内のPHPタグ、`javascript:`, `data:text/html` の検出
- script要素やイベント属性の警告

テーマ内でPHPロジックを書くことは禁止です。

## setup無効化

`config.php` が存在しない場合のみ初回setupが可能です。

`config.php` が存在する場合、以下ではsetupを無効化します。

- `security.disable_setup_after_install === true`
- `setup_completed === true`
- `setup_completed` が未定義

setup無効時はPOST処理にも進まず、`config.php` を更新しません。

## config.php保護

`config.php` は配布物に含めません。setupで生成します。

Apache環境ではルート `.htaccess` で `config.php` と `config.sample.php` への直接アクセスを拒否します。ホスト設定によって `.htaccess` が無効な場合は、サーバー側の設定で同等の保護を行ってください。

## cache保護

`cache/index/pages.json` は配布物に含めません。

`cache/.htaccess` と `cache/index/.htaccess` で、PHP系ファイル、JSON、tmp、logへの直接アクセスを拒否します。PHPコードをcache配下に置かない運用も維持してください。

`cache/html/` はHTMLキャッシュ用の内部ディレクトリです。`cache/html/.htaccess` で直接アクセスを拒否し、生成された `.html` と `.json` は配布物に含めません。

HTMLキャッシュのファイル名には元Markdownパスをそのまま使わず、ハッシュ化したキーを使います。draftページは公開可否判定後にのみキャッシュ経路へ進むため、過去のキャッシュが残っていてもdraftページは表示しません。

管理用合言葉の記憶トークンは `cache/security/post-auth/`、投稿完了記録は `cache/security/post-submissions/` に保存します。どちらも生のトークンや投稿IDをファイル名に使わずSHA-256ハッシュを使用し、ディレクトリ内の `.htaccess` でも直接アクセスを拒否します。配布ZIPには生成済みJSON、ロック、一時ファイルを含めません。

記憶CookieはHttpOnly、SameSite=Strict、Tomos Post配下のPath、30日のMax-Ageを使用します。HTTPS判定時はSecureを必須とし、HTTPのローカル開発環境だけSecureを外します。Cookieには管理用合言葉を保存しません。

## .htaccessの役割

ルート `.htaccess` は以下を担当します。

- `index.php` へのルーティング
- ディレクトリ一覧の抑止
- `config.php` と `config.sample.php` の直接アクセス拒否
- tmp/logファイルの直接アクセス拒否

`content/.htaccess` はMarkdownファイルとPHP系ファイルへの直接アクセスを拒否し、画像ファイルは静的配信できるようにします。

## Tomos Updateの保存領域

Tomos Updateは、更新対象ファイルだけのバックアップ、更新結果ログ、確認中のZIP、排他ロックを `storage/` に保存します。`storage/.htaccess` はディレクトリ内の全ファイルへのWebアクセスを拒否します。Apache以外ではWebサーバー側にも同等の拒否設定が必要です。

更新ZIPはTomos同梱の公開鍵でRSA/SHA-256署名を検証し、manifestに記載された各ファイルのSHA-256を確認します。秘密鍵はTomos本体、配布ZIP、公開リポジトリ、公式サイト公開領域へ置きません。

`config.php`、`content/`、`cache/`、`storage/`、`trash/`、独自テーマ、Tomos Updateの検証制御ファイルは更新対象として許可しません。

## HTTPヘッダー

Tomosは標準で以下を送信します。

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `Referrer-Policy: strict-origin-when-cross-origin`

`security.content_security_policy` が有効な場合は、基本的なCSPも送信します。

## 既知の制限

- Nginx向けの詳細設定は未整備です。
- CSPは標準テーマ向けの基本設定です。テーマ拡張時は確認が必要です。
- テーマ内の外部scriptやイベント属性は検出対象ですが、詳細なHTMLサンドボックスではありません。
- HTMLキャッシュは通常Markdownページの本文HTMLだけを対象にします。
