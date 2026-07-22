# 既存環境の更新

Tomosをすでに設置している場合の、データを保持した更新手順です。

## v0.1.0-alpha.1からv0.1.0-alpha.2への更新

この更新では、フォルダー記事一覧のページング追加、数字だけの第1階層フォルダー名で発生するPHP 8の型エラー修正、サブディレクトリ設置時にTomos Postの公開URLへ設置パスが二重に付く問題、日本語の濁点表現だけがNFC/NFD形式で異なる同名ファイルを更新確認なしで二重公開する問題の修正を行います。

更新前に設置ディレクトリ全体、または少なくとも `config.php`、`content/`、`themes/`、`cache/`、`trash/` をバックアップしてください。

`tomos-0.1.0-alpha.2.zip` をPC上の別フォルダへ展開し、次の本体ファイルを既存環境の同じパスへ上書きします。

- `core/App.php`
- `core/NavigationBuilder.php`
- `core/PostUpload.php`
- `core/TemplateRenderer.php`
- `themes/tomos-90s/assets/style.css`
- `themes/tomos-90s/templates/layout.html`
- `themes/tomos-dark/assets/style.css`
- `themes/tomos-dark/templates/layout.html`
- `themes/tomos-dark/templates/page.html`
- `themes/tomos-journal/assets/style.css`
- `themes/tomos-journal/templates/layout.html`
- `themes/tomos-journal/templates/page.html`
- `themes/tomos-minimal/assets/style.css`
- `themes/tomos-minimal/templates/layout.html`
- `themes/tomos-note/assets/style.css`
- `themes/tomos-note/templates/layout.html`

テーマの `theme.json` と各種ドキュメントも更新されています。テーマを独自に変更している場合は上書きせず、配布版との差分を確認してください。

更新後は、トップページ、30件を超える記事があるフォルダーページ、数字だけの第1階層フォルダー、Tomos Postから投稿したページの公開URLを確認します。日本語の濁点表現だけがNFC/NFD形式で異なる同名ファイルを投稿した場合は、新規公開せず既存ページの更新確認が表示されることも確認します。

## v0.1.0-alphaからv0.1.0-alpha.1への差分更新

この更新は、Tomos Postで既存ページの更新確認を行った直後に、連続投稿制限で更新できない問題を修正します。

差し替えるファイルは次の2つだけです。

- `core/PostRateLimiter.php`
- `post/index.php`

### 更新手順

1. 設置ディレクトリ全体、または少なくとも `config.php`、`content/`、`themes/`、`cache/`、`trash/` をバックアップします。
2. 現在設置されている `core/PostRateLimiter.php` と `post/index.php` を、復旧用に別の場所へ保存します。
3. `tomos-0.1.0-alpha.1.zip` をPC上の別フォルダへ展開します。
4. 展開した `core/PostRateLimiter.php` と `post/index.php` のみを、既存環境の同じパスへ上書きします。
5. トップページとTomos Postが表示できることを確認します。
6. Tomos Postから、既存の `index.md` と同名のMarkdownを送信し、確認画面の「このページを更新する」を押します。
7. 同じURLで新しい本文が表示され、既存の設定、テーマ、他の記事、画像が保持されていることを確認します。

## Zip全体で更新しない

配布Zipを既存の設置ルートへそのまま上書き展開しないでください。配布Zipには新規設置用のファイルも含まれます。

次のデータは上書き、削除、初期化しません。

- `config.php`
- `content/`
- `themes/`
- `cache/` 内の生成済みファイル
- `trash/`

## 問題が起きた場合

更新後にエラーが出る場合は作業を止め、更新前のバックアップから差し替えたファイルだけを元のパスへ戻してください。データの削除、再setup、キャッシュ全削除は行わず、エラー表示とサーバーログを保存してください。
