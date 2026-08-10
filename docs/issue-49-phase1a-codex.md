# Issue #49 Phase 1A 実装指示

対象Issue: #49「PostUpload責務分散と共通投稿基盤化（Obsidian連携を含む）」

## 目的

現行 `core/PostUpload.php` の外部挙動を変えずに、HTTPファイルアップロード固有処理だけを分離する。

このPhaseでは、Obsidian Inbox、SFTP受信、共通投稿サービス全体の実装には進まない。

目的は次Phase以降のための安全な責務境界を作ることであり、既存Tomos Postの挙動維持を最優先とする。

## 現状認識

`PostUpload::handle()` は現在、冒頭で以下のHTTPアップロード固有処理を行っている。

- upload error判定
- `tmp_name` 取得
- `is_uploaded_file()`
- file size判定
- original filename取得
- `file_get_contents($tmpPath)`

その後、Markdown本文に対して以下を行う。

- UTF-8 / binary判定
- ファイル名・folder正規化
- editable reupload処理
- 画像処理
- 保存先決定
- 競合判定
- 一時確認データ生成
- 保存
- published metadata付与
- index再構築

Phase 1Aでは前者だけを切り離す。

## 実装方針

### 1. HTTP入力責務を専用クラスへ分離

仮称:

```php
Tomos\PostUploadInput
```

新規ファイル候補:

```text
core/PostUploadInput.php
```

責務は以下に限定する。

- `$_FILES` 相当の配列を受け取る
- upload errorを検証する
- `tmp_name` を検証する
- `is_uploaded_file()` を確認する
- file sizeを検証する
- original filenameを取得する
- tmp fileから本文を読み込む
- HTTPアップロード固有エラーをTomos用エラー文へ変換する

ファイル名正規化、Front Matter、保存先、競合、画像、published、index更新は担当させない。

### 2. 入力結果DTOを最小限追加してよい

必要なら以下のような内部DTOを追加してよい。

```php
final class PostUploadInputResult
{
    public bool $ok;
    public array $errors;
    public string $content;
    public string $originalFileName;
    public int $size;
}
```

名称・詳細は既存コード規約に合わせて調整可。

ただし、このPhaseで汎用的すぎる `PostSubmission` DTOや大規模なService階層まで作らない。

## PostUpload::handle() の扱い

既存公開APIを維持すること。

```php
public function handle(
    array $file,
    string $folderInput,
    string $fileNameInput,
    ?string $sessionId = null,
    array $imageFiles = [],
    array $omittedImages = [],
    bool $trustedStagedImages = false,
    string $submissionId = ''
): PostUploadResult
```

引数・返却型・呼び出し側を変更しない。

`handle()` 冒頭で新しいHTTP入力クラスを利用し、取得した `content` と `originalFileName` を、現行後続処理へそのまま渡す構造にする。

Phase 1Aでは後続処理のアルゴリズム変更を行わない。

## エラー文の互換性

既存Tomos PostのUIに出るエラー文を可能な限り変更しない。

特に以下のケースを維持する。

- ファイル未選択
- upload error
- `is_uploaded_file()` 不成立
- 空ファイル
- 1MB超過
- tmp file読み込み失敗

既存の `uploadErrorMessage()` を移動する場合も、返却文言を変更しない。

## 今回変更禁止の領域

Phase 1Aでは以下をリファクタリングしない。

- `normalizeFileName()`
- `normalizeFolder()`
- `PostEditableMarkdown`
- `PostUploadTempStore`
- `prepareImages()` / `saveImages()`
- `prepareEditableConfirmation()`
- `updateFromTemp()`
- `replaceFileSafely()`
- `withInitialPublishedMetadata()`
- `rebuildIndexes()`
- Metadata / Feed / Navigation / Cache処理
- published順序処理
- 競合時hash照合
- 別名保存処理

必要最小限の依存追加以外は触らない。

## required-installed-files への対応

新規 `core/PostUploadInput.php` が実行時必須ファイルになる場合は、Tomosのインストール整合性チェック対象を確認する。

`core/required-installed-files.txt` 等への追加が必要なら行う。

既存Update/IntegrityVerifierの仕様を壊さないこと。

## テスト要件

### A. 新規ユニット/回帰テスト

HTTP入力クラスについて、可能な範囲で以下をテストする。

- upload error
- 空ファイル
- サイズ超過
- original filename取得
- 本文取得
- 不正tmp file拒否

`is_uploaded_file()` はCLIテストで直接成立させにくい場合があるため、既存のテスト方式を確認し、無理に偽装しない。

必要なら入力検証の一部をpureなprivate/public内部処理としてテスト可能にするが、テストのためだけに本番設計を歪めない。

### B. 既存回帰テスト

リポジトリ内の既存投稿関連テストをすべて実行する。

最低限、影響し得る以下を確認する。

- 新規Markdown投稿
- 日本語ファイル名
- Front Matter
- `index.md` / `about.md`
- editable reupload
- 既存記事競合
- 一時確認
- 画像付き投稿
- published metadata
- index再構築

既存テスト名称はリポジトリを調査して実際のものを使用すること。

## 手動確認

実装後、実サーバーまたは既存Tomos開発環境でTomos Postから通常のMarkdown 1件を投稿し、以下を確認する。

- 投稿成功
- URL生成
- 記事表示
- 更新順
- 管理画面表示

可能なら既存記事への再投稿も1件確認する。

## 完了条件

- `PostUpload::handle()` の公開APIが変わっていない
- HTTPアップロード固有処理が専用責務へ移動している
- その後のMarkdown投稿処理は実質的に変更されていない
- 既存投稿関連テストが成功する
- PHP lintが成功する
- `git diff --check` が成功する
- required-installed-files等の必要な整合性設定が反映されている
- Tomos Postによる手動投稿で回帰がない

## 実装後の報告内容

Codexは実装完了時に以下を報告すること。

1. 変更ファイル一覧
2. 分離した責務
3. `PostUpload::handle()` の変更概要
4. 実行したテストと結果
5. PHP lint結果
6. `git diff --check` 結果
7. 既知の未検証事項
8. Phase 1Bへ進む際の注意点

## 禁止事項

- Phase 1B以降を先取りしない
- Inboxを実装しない
- Obsidian連携コードをTomos本体へ追加しない
- 公開APIを変更しない
- エラー文を不用意に変更しない
- `PostUpload.php` 全体を一括再設計しない
- 既存の競合・画像・index処理を整理目的で同時変更しない

## 判断基準

このPhaseの良い実装は「コードが大きく変わること」ではなく、差分が小さく、既存挙動を保ったままHTTP依存だけが明確に外へ出ることである。

Phase 1A完了後、差分と回帰結果を確認してからPhase 1Bへ進む。