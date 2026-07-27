# チェックリスト

この文書はプロジェクト管理者向けの内部確認メモです。通常の利用者が実行する必要はありません。

## 設置後チェックリスト

- [ ] `config.php` がない初回状態でトップページから相対URL `setup/` へ移動する
- [ ] トップページが表示される
- [ ] `/about` が表示される
- [ ] `/search/` が表示される
- [ ] `/tags/` が表示される
- [ ] `/feed.xml` がXMLで表示される
- [ ] `/sitemap.xml` がXMLで表示される
- [ ] ページのHTMLソースに favicon / OGP meta が出ている
- [ ] `/setup/` にアクセスしても設定フォームが出ない
- [ ] `setup/` ディレクトリを削除またはリネームした

## 配布前チェックリスト

- [ ] `config.php` が含まれていない
- [ ] `config.sample.php` が含まれている
- [ ] `.htaccess` が含まれている
- [ ] `VERSION` が含まれている
- [ ] `LICENSE` が含まれている
- [ ] `NOTICE` が含まれている
- [ ] `TRADEMARKS.md` が含まれている
- [ ] `CHANGELOG.md` が含まれている
- [ ] `SECURITY.md` が含まれている
- [ ] `KNOWN_LIMITATIONS.md` が含まれている
- [ ] `cache/index/pages.json` が含まれていない
- [ ] `cache/index/link-aliases.json` が含まれていない
- [ ] `cache/index/.gitkeep` が含まれている
- [ ] `cache/html/.gitkeep` が含まれている
- [ ] `cache/security/post-rate-limit/.gitkeep` が含まれている
- [ ] `cache/.htaccess` が含まれている
- [ ] `cache/index/.htaccess` が含まれている
- [ ] `cache/html/.htaccess` が含まれている
- [ ] `cache/security/.htaccess` が含まれている
- [ ] `cache/security/post-rate-limit/.htaccess` が含まれている
- [ ] `.DS_Store` が含まれていない
- [ ] `*.tmp` や `*.log` が含まれていない
- [ ] `setup/` が含まれている
- [ ] `post/` が配布Zipに含まれている
- [ ] `post/theme/` が配布Zipに含まれている
- [ ] `post/theme/confirm/` が配布Zipに含まれている
- [ ] `post-reset.enable` が配布Zipに含まれていない
- [ ] `.htpasswd` が配布Zipに含まれていない
- [ ] `trash/.htaccess` が含まれている
- [ ] `trash/.gitkeep` が含まれている
- [ ] `trash/content/` が含まれていない
- [ ] `themes/tomos-minimal/assets/favicon.png` が含まれている
- [ ] `themes/tomos-minimal/assets/apple-touch-icon.png` が含まれている
- [ ] `themes/tomos-minimal/assets/ogp.png` が含まれている
- [ ] `themes/tomos-journal/` が含まれている
- [ ] `themes/tomos-dark/` が含まれている
- [ ] テスト用ページが公開状態で残っていない
- [ ] `README.md` がある
- [ ] `INSTALL.md` がある
- [ ] `docs/README.md` がある
- [ ] `docs/user/writing.md` がある
- [ ] `README.md` にライセンス範囲が記載されている
- [ ] Tomosロゴ・アイコン・OGP画像がMIT License対象外であることが明記されている
## 配布Zipチェックリスト

- [ ] Zipを開いた直下に `index.php` がある
- [ ] Zipを開いた直下に `.htaccess` がある
- [ ] Zipに `config.php` が含まれていない
- [ ] Zipに `post-reset.enable` が含まれていない
- [ ] Zipに `.htpasswd` が含まれていない
- [ ] Zipに `post/index.php` が含まれている
- [ ] Zipに `post/theme/index.php` が含まれている
- [ ] Zipに `post/theme/confirm/index.php` が含まれている
- [ ] Zipに `post/reset/index.php` が含まれている
- [ ] Zipに `trash/.htaccess` が含まれている
- [ ] Zipに `trash/.gitkeep` が含まれている
- [ ] Zipに `trash/content/` が含まれていない
- [ ] Zipに `cache/index/pages.json` が含まれていない
- [ ] Zipに `cache/html/*.html` が含まれていない
- [ ] Zipに `cache/html/*.json` が含まれていない
- [ ] Zipに `cache/security/post-rate-limit/*.json` が含まれていない
- [ ] Zipに `cache/security/post-rate-limit/*.tmp` が含まれていない
- [ ] Zipに `cache/security/post-rate-limit/*.log` が含まれていない
- [ ] Zipに `tests/` が含まれていない
- [ ] Zipに `.DS_Store` が含まれていない
- [ ] Zipに `VERSION` が含まれている
- [ ] Zipに `LICENSE` が含まれている
- [ ] Zipに `NOTICE` が含まれている
- [ ] Zipに `TRADEMARKS.md` が含まれている

## セキュリティチェック

- [ ] Markdown生HTMLが標準で無効
- [ ] content外ファイルを読めない
- [ ] `../config.php` のようなパスを拒否できる
- [ ] Wikiリンクから危険URLが出ない
- [ ] ローカル画像指定からcontent外を参照できない
- [ ] `http://` / `https://` の外部画像URLは安全な拡張子だけ `<img>` として表示される
- [ ] テーマ内PHPが検出される
- [ ] setup完了後にsetupフォームが出ない
- [ ] setup無効時にPOSTでconfig.phpを書き換えられない
- [ ] `config.php` が配布Zipに含まれていない
- [ ] `.htaccess` が配布Zipに含まれている
- [ ] `cache/index/pages.json` が配布Zipに含まれていない
- [ ] cache内PHP実行禁止方針が確認されている

## HTMLキャッシュチェック

- [ ] `cache/html/` が存在する
- [ ] `cache/html/.gitkeep` が含まれている
- [ ] `cache/html/.htaccess` が含まれている
- [ ] `cache/html/*.html` は配布Zipに含まれていない
- [ ] `cache/html/*.json` は配布Zipに含まれていない
- [ ] 通常Markdownページでキャッシュが生成される
- [ ] Markdown更新後にキャッシュが再生成される
- [ ] draftページがキャッシュ経由で表示されない
- [ ] 検索・タグ・RSS・sitemapはHTMLキャッシュ対象外
- [ ] テーマやテンプレート変更後は、必要に応じて `cache/html/` の生成ファイルを削除して表示を確認する

## Markdown表示チェック

- [ ] 見出しが `h1` / `h2` として表示される
- [ ] 太字が `strong` として表示される
- [ ] 斜体が `em` として表示される
- [ ] リンクが `a` として表示される
- [ ] 箇条書きが `ul` / `li` として表示される
- [ ] 番号リストが `ol` / `li` として表示される
- [ ] 引用が `blockquote` として表示される
- [ ] インラインコードが `code` として表示される
- [ ] コードブロックが `pre` / `code` として表示される
- [ ] 水平線が `hr` として表示される
- [ ] 画像が安全な `img` または欠落表示として表示される
- [ ] GFMテーブルが `table` として表示される
- [ ] スマホ幅で表だけ横スクロールできる
- [ ] `tomos-minimal`、`tomos-journal`、`tomos-dark` でテーブルが読める
- [ ] Wikiリンクが壊れていない
- [ ] Obsidian風画像埋め込みが壊れていない
- [ ] `cache/index/link-aliases.json` が生成される
- [ ] `[[ファイル名]]` が該当ページへリンクされる
- [ ] `[[ファイル名|表示名]]` の表示名が反映される
- [ ] `[[フォルダ/ファイル名]]` が該当ページへリンクされる
- [ ] frontmatter `title` とファイル名が異なるページでもWikiリンクが解決される
- [ ] `【254本目】` のような末尾括弧情報を除いたtitleでも、衝突がなければ解決される
- [ ] alias衝突時に勝手に一方へリンクされず、未解決表示になる
- [ ] 未解決Wikiリンクが404リンクにならない
- [ ] 未解決Wikiリンクの表示文字列が本文上に残る
- [ ] リンク先Markdownを後から追加すると、alias再生成後に既存Wikiリンクがリンク化される
- [ ] インデックス再生成時に古い本文HTMLキャッシュが残らない
- [ ] ページ表示時に `content/` 全体を走査していない
- [ ] frontmatterが本文に表示されない

## content階層走査チェック

- [ ] `content/` 内に空フォルダがあってもエラーにならない
- [ ] `content/test-folder/sample.md` が `/test-folder/sample` として表示される
- [ ] `content/test-folder/index.md` が `/test-folder/` として表示される
- [ ] `content/test-folder/image.jpg` がMarkdownページとして処理されない
- [ ] `content/.DS_Store` と `content/test-folder/.DS_Store` が無視される
- [ ] `content/test-folder/memo.txt` と `content/test-folder/data.json` が無視される
- [ ] `content/2026/` のような数字だけの空フォルダでエラーにならない
- [ ] `content/2026/index.md` が `/2026/` として表示される
- [ ] `content/2026/07/sample.md` が `/2026/07/sample` として表示される
- [ ] `content/1/test.md` が `/1/test` として表示される
- [ ] `content/2026/07/10/sample.md` のような深い階層が表示される
- [ ] `content/2026-01-03-2025年心に残った本10冊【254本目】.md` が表示される
- [ ] `content/読書メモ.md` が表示される
- [ ] `content/読書メモ（改訂版）.md` が表示される
- [ ] `content/books(2025).md` が表示される
- [ ] `content/my memo.md` が表示される
- [ ] `content/2026/2026-01-03-2025年心に残った本10冊【254本目】.md` が表示される
- [ ] 日本語・記号入りURLが一覧・検索・タグ・RSS・sitemapで壊れていない
- [ ] Tomos Postで危険文字を含むファイル名が安全な名前へ変更される
- [ ] Tomos Postでファイル名が変更された場合、変更前後が表示される
- [ ] Tomos Postで `【】` が削除されず保存される
- [ ] content内のシンボリックリンクを辿らない
- [ ] タグ・検索・RSS・sitemapが壊れていない
- [ ] 数字だけのフォルダを含むページでパンくず・ナビゲーションが壊れていない

## 日付表示チェック

- [ ] frontmatter の `date` が記事ページに表示される
- [ ] `updated` が `date` と異なる場合だけ更新日が表示される
- [ ] `date` / `updated` がないページでも表示が崩れない
- [ ] `tomos-minimal`、`tomos-journal`、`tomos-dark` で日付表示を確認する
- [ ] HTMLキャッシュ削除後に日付表示が反映される

## テーマ選択チェック

- [ ] setup画面でテーマ一覧が表示される
- [ ] `tomos-minimal` が選択可能
- [ ] `tomos-journal` が選択可能
- [ ] `tomos-dark` が選択可能
- [ ] テーマ名、説明、バージョンが表示される
- [ ] 無効テーマは選択できない
- [ ] 無効テーマの理由が表示される
- [ ] 選択したテーマ名が `config.php` の `theme.name` に保存される
- [ ] 有効テーマが0の場合はsetupを進めない
- [ ] setup完了後はテーマ選択POSTで設定を書き換えられない

## Tomos Post内テーマ切替チェック

- [ ] `/post/` の最下部にサイト設定とテーマ切り替え入口がある
- [ ] 未認証で `/post/theme/` にアクセスすると `/post/` へ戻る
- [ ] `/post/theme/` で現在テーマと有効テーマ一覧が表示される
- [ ] `/post/theme/confirm/` で変更前に確認できる
- [ ] テーマ変更後に `config.php` の `theme.name` だけが変わる
- [ ] テーマ変更後にHTMLキャッシュが削除される

## Tomos Postチェック

- [ ] Tomos Post画面にTomos Writeとの接続が分かる説明がある
- [ ] `/post/` が表示される
- [ ] 管理用合言葉なしでは投稿できない
- [ ] 誤った合言葉では投稿できない
- [ ] 正しい合言葉で `.md` を投稿できる
- [ ] `.txt` / `.markdown` が `.md` として保存される
- [ ] Markdown内の frontmatter `folder` が保存先フォルダ欄に自動反映される
- [ ] frontmatter `folder` が空または未設定の場合は保存先フォルダ欄が空のままになる
- [ ] frontmatter `folder` に危険なパス指定がある場合は自動反映されず注意が表示される
- [ ] Tomos Writeで指定した保存先フォルダがMarkdown内の `folder` に保存される
- [ ] 保存先は `content/` 配下に限定される
- [ ] `../` を含む保存先は拒否される
- [ ] 数字だけ、日本語、記号を含む通常フォルダ名を保存先フォルダとして扱える
- [ ] 同名ファイルは上書きされない
- [ ] 投稿後に `cache/index/pages.json` が削除される
- [ ] 合言葉失敗が一定回数続くと一時ブロックされる
- [ ] 短時間連続POSTが拒否される
- [ ] `/post/reset/` の短時間連続POSTが拒否される
- [ ] `cache/security/post-rate-limit/` が直接閲覧できない
- [ ] レート制限JSONが配布Zipに含まれない
- [ ] `/post/reset/` は `post-reset.enable` なしでは開かない
- [ ] `post-reset.enable` ありで新しい合言葉を再発行できる
- [ ] 再発行後、古い合言葉は使えない
- [ ] date / updated がページ内に表示される
- [ ] `/post/` に投稿する・投稿を取り下げる・trashを整理する導線がある
- [ ] 公開URLを入力して取り下げ対象を確認できる
- [ ] content内パスを入力して取り下げ対象を確認できる
- [ ] 確認画面で別タブ確認リンクが表示される
- [ ] 外部URLと予約パスは取り下げ対象として拒否される
- [ ] 管理用合言葉なしでは取り下げできない
- [ ] 誤った合言葉では取り下げできない
- [ ] 正しい合言葉で取り下げできる
- [ ] 短時間POST制限中は取り下げできない
- [ ] 取り下げ後、content/からファイルが消える
- [ ] 取り下げ後、trash/content/へファイルが移動する
- [ ] 同名衝突時にタイムスタンプ付きで退避される
- [ ] 取り下げ後、公開URLが表示されなくなる
- [ ] 取り下げ後、一覧・検索・タグから外れる
- [ ] trashを空にする前に件数と容量が表示される
- [ ] 確認入力なしではtrash全削除できない
- [ ] 正しい合言葉と確認入力でtrash全削除できる
- [ ] trash/.htaccess と trash/.gitkeep は残る
- [ ] trash/以外が削除されていない

## 利用導線・名称チェック

- [ ] READMEにTomos Writeで書く、Tomos Postで投稿する、Tomosで公開する流れがある
- [ ] INSTALLにsetup完了時の管理用合言葉説明がある
- [ ] `docs/user/writing.md` にTomos Write / Tomos Post / Tomosの流れがある
- [ ] Tomos Write表記に統一されている
- [ ] Tomos Writeの旧称表記がbuild版に残っていない

## サンプルテーマチェック

- [ ] `themes/tomos-minimal/` が含まれている
- [ ] `themes/tomos-journal/` が含まれている
- [ ] `themes/tomos-dark/` が含まれている
- [ ] setup画面で `tomos-minimal` が選択できる
- [ ] setup画面で `tomos-journal` が選択できる
- [ ] setup画面で `tomos-dark` が選択できる
- [ ] `tomos-journal` を選んでsetup完了できる
- [ ] `tomos-journal` でトップページが表示される
- [ ] `tomos-journal` で下層ページが表示される
- [ ] `tomos-dark` を選んでsetup完了できる
- [ ] `tomos-dark` でトップページが表示される
- [ ] `tomos-dark` で下層ページが表示される

## テーマ文書チェック

- [ ] `docs/theme/theme-authoring.md` がある
- [ ] `docs/theme/theme-validation.md` がある
- [ ] `docs/theme/theme-checklist.md` がある
- [ ] `docs/theme/ai-theme-prompt.md` がある
- [ ] READMEからテーマ文書へ辿れる
- [ ] docs/README.mdからテーマ文書へ辿れる

## 限定配布前チェック

- [ ] 配布済みの `tomos-0.1.0-alpha.4.zip` が用意されている
- [ ] 対象ホスティング環境で新規インストールできる
- [ ] `/` が表示される
- [ ] `/about` が表示される
- [ ] `/search/` が表示される
- [ ] `/tags/` が表示される
- [ ] `/feed.xml` が表示される
- [ ] `/sitemap.xml` が表示される
- [ ] `/setup/` が完了後にフォームを出さない
- [ ] `/config.php` が見えない
- [ ] `/config.sample.php` が見えない
- [ ] `/content/index.md` がForbiddenまたは非表示になる
- [ ] `/cache/index/pages.json` が見えない
- [ ] `/cache/html/` が見えない
- [ ] `/storage/` が見えない
- [ ] `/update/` で現在のバージョンが表示される
- [ ] 通常Markdownページ表示後に `cache/html/` にキャッシュが生成される
- [ ] Markdown更新後にキャッシュが再生成される
- [ ] `docs/user/writing.md` が含まれている
- [ ] READMEまたはINSTALLから記事作成ガイドへ誘導されている
- [ ] `KNOWN_LIMITATIONS.md` に未実装項目が明記されている

## 通常表示パフォーマンスチェック

- [ ] `pages.json` と `link-aliases.json` がある通常表示で `content/` 全体走査が走らない
- [ ] 同一URLの2回目表示でHTMLキャッシュがhitする
- [ ] HTMLキャッシュhit時にMarkdown変換が走らない
- [ ] HTMLキャッシュhit時にWikiリンク変換が走らない
- [ ] 通常記事表示で検索・タグ一覧用の重い生成処理が先に走らない
- [ ] FTP追加後はインデックスキャッシュ削除で `pages.json` と `link-aliases.json` を再生成できる
- [ ] Tomos Post投稿後に必要なキャッシュが無効化される
- [ ] `cache/logs/performance.log` が直接閲覧できない
- [ ] `cache/logs/*.log` が配布Zipに含まれない
- [ ] Markdown基本記法が表示される
- [ ] GFMテーブルが表示される
- [ ] スマホ幅で表だけ横スクロールできる
- [ ] `content/`、`cache/`、`trash/`、`config.php` を直接閲覧できない
