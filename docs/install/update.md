# 既存環境の更新

Tomosをすでに設置している場合の、データを保持した更新手順です。

## 現在の更新方法（alpha.18以降）

通常の更新は、Tomos Postの「Tomos Update」から次の手順で行います。

1. 公式のオンライン更新情報を確認します。
2. 更新内容の確認画面で、現在のversionと更新先versionを確認します。
3. 「更新する」を明示的に押して適用します。

オンライン更新はページを開いただけではZIPを取得・適用せず、自動適用もしません。更新は常に1versionずつ行い、Update ZIPの`from_version`が現在の`VERSION`と完全一致する場合だけ受け付けます。自動多段更新、バックグラウンド更新は行いません。

手動の署名済みUpdate ZIPも正式な更新経路として利用できます。オンライン更新と手動更新のどちらも、同じ署名検証、`from_version`検証、backup、rollback、apply処理を使用します。

### v0.1.0-alpha.18からv0.1.0-alpha.19への更新

alpha.18からalpha.19へは、通常のオンライン更新または手動の署名済みUpdate ZIPを使用します。`from_version`は`0.1.0-alpha.18`、更新先は`0.1.0-alpha.19`です。この更新ではUpdater finalizeは必要ありません。

### v0.1.0-alpha.17からv0.1.0-alpha.18への移行

alpha.17から新しいUpdaterへ移行する最初の更新だけは、一回限りのbootstrap例外です。次の2段階を必ず実行してください。

1. `tomos-update-0.1.0-alpha.18.zip`を手動で適用します。
2. 更新後に`/post/update-finalize/`を開き、「Updater更新を反映する」を実行します。

Step 2を完了すると、`update/index.php`と`core/UpdateService.php`のUpdater bundleが反映されます。その後、alpha.19へ進めてください。

## v0.1.0-alpha.14への更新

v0.1.0-alpha.13.1をご利用の場合は、Tomos Postの「Tomos Update」から、署名済みの `tomos-update-0.1.0-alpha.14.zip` を適用できます。

1. 既存サイトの `config.php`、`content/`、`themes/` をバックアップします。`storage/security/passkeys/` が存在する場合は、このディレクトリもバックアップします。
2. Tomos Postの「Tomos Update」を開きます。
3. `tomos-update-0.1.0-alpha.14.zip` を選び、「更新内容を確認」を押します。
4. 現在のバージョンが `0.1.0-alpha.13.1`、更新後のバージョンが `0.1.0-alpha.14` と表示されていることを確認します。
5. 更新対象を確認し、更新を実行します。
6. 更新完了後、「現在のバージョン」が `0.1.0-alpha.14` と表示されていることを確認します。

v0.1.0-alpha.14では、同じ日に複数の記事を投稿した場合の一覧表示順を改善しました。新しく投稿した記事が同日の既存記事より上に表示されます。公開済み記事を後から編集しても、投稿順は変更されません。

既存記事への `published` の一括変換は行いません。既存記事はそのまま表示できます。

## v0.1.0-alpha.13.1への更新

v0.1.0-alpha.13.1は、v0.1.0-alpha.13の修正版です。

v0.1.0-alpha.12またはv0.1.0-alpha.13をご利用の場合は、Tomos Postの「Tomos Update」から、署名済みの `tomos-update-0.1.0-alpha.13.1.zip` を適用できます。

1. 既存サイトの `config.php`、`content/`、`themes/` をバックアップします。`storage/security/passkeys/` が存在する場合は、このディレクトリもバックアップします。
2. Tomos Postの「Tomos Update」を開きます。
3. `tomos-update-0.1.0-alpha.13.1.zip` を選び、「更新内容を確認」を押します。
4. 現在のバージョンが `0.1.0-alpha.12` または `0.1.0-alpha.13`、更新後のバージョンが `0.1.0-alpha.13.1` と表示されていることを確認します。
5. 更新対象を確認し、更新を実行します。
6. 更新完了後、「現在のバージョン」が `0.1.0-alpha.13.1` と表示されていることを確認します。

この修正版では、セキュリティ画面とパスキー管理画面での管理用合言葉認証の導線を改善し、認証後も同じ画面からパスキーの追加・管理へ進めるようにしました。また、通常UIからRP ID表示を削除し、WebAuthn runtimeの読み込みを安定化しました。

`storage/security/passkeys/` はTomos Updateの更新対象ではありません。登録済みパスキーのcredentialはUpdateによって上書きまたは削除されません。

## v0.1.0-alpha.13への更新

すでにv0.1.0-alpha.12をご利用の場合は、Tomos Postの「Tomos Update」から、署名済みの `tomos-update-0.1.0-alpha.13.zip` を適用できます。

### v0.1.0-alpha.12からv0.1.0-alpha.13への更新

1. 既存サイトの `config.php`、`content/`、`themes/` をバックアップします。`storage/security/passkeys/` が存在する場合は、登録済みパスキーのcredentialを保持するため、このディレクトリもバックアップします。
2. Tomos Postの「Tomos Update」を開きます。
3. `tomos-update-0.1.0-alpha.13.zip` を選び、「更新内容を確認」を押します。
4. 現在のバージョンが `0.1.0-alpha.12`、更新後のバージョンが `0.1.0-alpha.13` と表示されていることを確認します。
5. 更新対象を確認し、更新を実行します。
6. 更新完了後、「現在のバージョン」が `0.1.0-alpha.13` と表示されていることを確認します。
7. Tomos Postに「セキュリティ」への導線が追加されていることを確認します。

alpha.13では、Tomos Postに次の機能を追加します。

- WebAuthnパスキー認証
- 複数パスキーの管理
- パスキーによる管理用合言葉の再設定
- パスキー未登録時のサーバー所有確認による復旧
- Tomos Postのセキュリティ画面

Tomos本体はPHP 7.4以上で利用できます。パスキー機能を利用する場合は、PHP 8.0以上、OpenSSL、mbstring、HTTPS、WebAuthn対応ブラウザが必要です。条件を満たさない場合も、従来の管理用合言葉認証は利用できます。

WebAuthn runtimeはUpdate ZIPに同梱されています。利用者がComposerをインストールしたり、サーバー上でComposerを実行したりする必要はありません。

`storage/security/passkeys/` はTomos Updateの更新対象ではありません。登録済みパスキーのcredentialはUpdateによって上書きまたは削除されません。

このUpdateでは、`config.php`、`content/`、利用者が追加したテーマも上書きまたは削除しません。

## v0.1.0-alpha.12への更新

すでにv0.1.0-alpha.11をご利用の場合は、Tomos Postの「Tomos Update」から、署名済みの `tomos-update-0.1.0-alpha.12.zip` を適用できます。

### v0.1.0-alpha.11からv0.1.0-alpha.12への更新

1. 既存サイトの `config.php`、`content/`、`themes/` をバックアップします。
2. Tomos Postの「Tomos Update」を開きます。
3. `tomos-update-0.1.0-alpha.12.zip` を選び、「更新内容を確認」を押します。
4. 現在のバージョンが `0.1.0-alpha.11`、更新後のバージョンが `0.1.0-alpha.12` と表示されていることを確認します。
5. 更新対象を確認し、更新を実行します。
6. 更新完了後、「現在のバージョン」が `0.1.0-alpha.12` と表示されていることを確認します。
7. Tomos Postのテーマ管理画面に「テーマZIPを追加」が表示されることを確認します。

このUpdateでは、`config.php`、`content/`、利用者が追加したテーマを上書きまたは削除しません。

## v0.1.0-alpha.11への更新

v0.1.0-alpha.5以前をご利用の場合は、最初にv0.1.0-alpha.6へ手動で更新してください。

alpha.6への移行後、署名済みUpdate ZIPを使い、v0.1.0-alpha.7、alpha.8、alpha.9、alpha.10の順に更新してください。alpha.10からは、Tomos Postの「Tomos Update」でv0.1.0-alpha.11へ更新できます。

更新順序:

```text
v0.1.0-alpha.5以前
↓
v0.1.0-alpha.6へ手動更新
↓
Tomos Updateからv0.1.0-alpha.7へ更新
↓
Tomos Updateからv0.1.0-alpha.8へ更新
↓
Tomos Updateからv0.1.0-alpha.9へ更新
↓
Tomos Updateからv0.1.0-alpha.10へ更新
↓
Tomos Updateからv0.1.0-alpha.11へ更新
```

すでにv0.1.0-alpha.10をご利用の場合は、そのままTomos Updateからalpha.11へ更新できます。

## v0.1.0-alpha.10からv0.1.0-alpha.11への更新

alpha.10の環境では、署名済みの`tomos-update-0.1.0-alpha.11.zip`をTomos Updateから適用できます。

1. Tomos Postへ認証し、Tomos Update画面（`/update/`）を開きます。
2. `tomos-update-0.1.0-alpha.11.zip`を選び、「更新内容を確認」を押します。
3. 現在のバージョンが`0.1.0-alpha.10`、更新後のバージョンが`0.1.0-alpha.11`と表示されていることを確認します。
4. 更新対象に`config.php`、`content/`、独自テーマが含まれていないことを確認します。
5. 「更新する」を押します。
6. 更新完了後、「現在のバージョン」が`0.1.0-alpha.11`と表示されていることを確認します。
7. Tomos PostのUpdater更新反映画面（`/post/update-finalize/`）を開き、反映待ち状態を確認します。
8. 管理用合言葉を入力し、「Updater更新を反映する」を押します。
9. 「Updater本体を更新しました。」と表示され、反映待ちの更新がなくなったことを確認します。

通常Updateが完了した時点では、`update/index.php`と`core/UpdateService.php`はまだ旧版です。Updater更新反映画面から明示的にbundleを反映した場合だけ、2ファイルがまとめて置き換わります。

## v0.1.0-alpha.9からv0.1.0-alpha.10への更新

alpha.9の環境では、署名済みの`tomos-update-0.1.0-alpha.10.zip`をTomos Updateから適用できます。

1. Tomos Postへ認証し、Tomos Update画面（`/update/`）を開きます。
2. `tomos-update-0.1.0-alpha.10.zip`を選び、「更新内容を確認」を押します。
3. 現在のバージョンが`0.1.0-alpha.9`、更新後のバージョンが`0.1.0-alpha.10`と表示されていることを確認します。
4. 更新対象に`config.php`、`content/`、独自テーマが含まれていないことを確認します。
5. 「更新する」を押します。
6. 更新完了後、「現在のバージョン」が`0.1.0-alpha.10`と表示されていることを確認します。
7. Tomos PostのUpdater更新反映画面（`/post/update-finalize/`）を開き、反映待ち状態を確認します。
8. 管理用合言葉を入力し、「Updater更新を反映する」を押します。
9. 「Updater本体を更新しました。」と表示され、反映待ちの更新がなくなったことを確認します。

通常Updateが完了した時点では、`update/index.php`と`core/UpdateService.php`はまだ旧版です。Updater更新反映画面から明示的にbundleを反映した場合だけ、2ファイルがまとめて置き換わります。置換前のファイルは`storage/update-backups/`へ保存され、結果は`storage/update-logs/`へ記録されます。

更新前に`config.php`、`content/`、利用中テーマをバックアップしてください。Tomos Updateは更新対象ファイルだけをバックアップし、`config.php`、`content/`、独自テーマを更新しません。

## v0.1.0-alpha.5以前からv0.1.0-alpha.6への更新

Tomos Updateを今後も安定して提供するため、alpha.6で署名確認に使用する信頼点を更新します。既存環境では、alpha.6への更新だけ手動操作が必要です。alpha.6へ移行した後は、alpha.7以降の署名済み更新ZIPをTomos Updateから利用できます。

1. 公式URLからalpha.6のGitHub Releaseを開きます。
2. `tomos-trust-migration-0.1.0-alpha.6.zip`を取得します。
3. GitHub Releaseに記載されたSHA-256と、ダウンロードしたZIPのSHA-256が一致することを確認します。
4. ZIPをPC上の別フォルダへ展開し、次の2ファイルだけを既存環境の同じパスへ上書きします。
   - `VERSION`
   - `update/public-key.pem`
5. Tomos Postへ認証し、Tomos Update画面（`/update/`）を開きます。
6. 「現在のバージョン」が`0.1.0-alpha.6`と表示されていることを確認します。
7. alpha.7以降は、公式に配布される署名済みUpdate ZIPをTomos Updateで確認して更新します。

新しい公開鍵のフィンガープリントは次のとおりです。

```text
SHA-256: 228636b1c3d2c93cf320063c478c2604b892a287bb346e1f6a3adf98047247cf
```

フィンガープリントは、この公式更新ドキュメントとalpha.6のGitHub Releaseの両方で同じ値であることを確認してください。alpha.6自体の署名済みUpdate ZIPは提供しません。

手動移行では、`config.php`、`content/`、独自テーマを含む`themes/`、`cache/`、`storage/`、`trash/`を上書き、削除、初期化しません。通常配布ZIP全体を既存サイトへ上書きしないでください。

## v0.1.0-alpha.3からv0.1.0-alpha.4への更新

この更新では、管理画面から署名済み更新ZIPを確認し、Tomos本体を更新する「Tomos Update」を追加します。

`v0.1.0-alpha.3` にはTomos Update自体がないため、この更新だけは `tomos-0.1.0-alpha.3-to-alpha.4-manual-update.zip` を一度だけFTPまたはサーバーのファイル管理機能でアップロードしてください。`v0.1.0-alpha.4` 導入後の更新からTomos Updateを利用できます。

更新前に設置ディレクトリ全体、または少なくとも `config.php`、`content/`、`themes/`、`cache/`、`trash/` をバックアップします。手動更新ZIPにはこれらのデータは含まれていません。

アップロード後、`storage/update-backups/`、`storage/update-logs/`、`storage/update-tmp/` をPHPから書き込み可能にします。Tomos Postの「サイト設定」から「Tomos Updateを開く」を選び、現在のバージョンが `0.1.0-alpha.4` と表示されること、`storage/` へWebから直接アクセスできないことを確認します。

Apache以外のWebサーバーでは、サーバー設定でも `storage/` へのアクセスを拒否してください。

## v0.1.0-alpha.2からv0.1.0-alpha.3への更新

この更新では、Tomos Postから `index.md` と `about.md` をダウンロードし、編集後に再投稿できるようにします。また、画像を6枚以上選択した場合に最大5枚であることを明示します。

更新対象は次のファイルです。

- `core/PostBasicPage.php`
- `core/PostUpload.php`
- `core/PostContentResolver.php`
- `core/PostWithdraw.php`
- `core/TrashManager.php`
- `post/index.php`
- `docs/install/update.md` などの関連ドキュメント

更新前に設置ディレクトリ全体、または少なくとも `config.php`、`content/`、`themes/`、`cache/`、`trash/` をバックアップしてください。`tomos-0.1.0-alpha.3.zip` をPC上の別フォルダへ展開し、上記の本体ファイルを既存環境の同じパスへ上書きします。配布Zip全体を既存の設置ルートへ上書き展開しません。`config.php`、`content/`、`themes/`、`cache/` 内の生成済みファイル、`trash/` は上書き・削除・初期化しません。

更新後はTomos Postの「サイト設定」から `index.md` と `about.md` をダウンロードできること、編集した各ファイルを再投稿して同じ基本ページを更新できること、画像を6枚選択した場合に「画像は最大5枚まで選択できます。」と表示され投稿されないことを確認します。

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
