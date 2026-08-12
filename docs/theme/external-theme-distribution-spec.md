# 外部配布テーマZIP仕様

## 目的

Tomos Postのテーマ管理画面から、テーマZIPをブラウザでアップロードし、既存テーマを変更せずに新規テーマとして安全に追加できるようにする。

対象は新規追加だけとし、既存テーマの上書き、更新、削除、自動有効化は行わない。

## 利用手順

1. Tomos Postのテーマ管理画面を開く。
2. 「テーマZIPを追加」からZIPを選択する。
3. TomosがZIP構造、容量、パス、ファイル種別、テーマ内容を検査する。
4. 表示名、テーマID、version、ファイル数、展開後容量、warningを確認する。
5. 「このテーマを追加する」で確定する。
6. テーマ一覧へ戻り、既存のテーマ切り替え手順で有効化する。

アップロードと確定追加は別操作とする。確認時点では `themes/` へ設置せず、追加完了後も自動的には使用テーマへ切り替えない。

## 対象外

- 既存テーマの上書き・更新・削除
- 使用中テーマの置換
- テーマZIPの自動更新や更新通知
- オンラインテーマ一覧、テーマストア、課金
- 公式サイト/APIとの連携
- テーマZIPの署名・manifest
- JavaScriptを含む外部テーマ
- 複数テーマの一括追加
- 将来機能を見越した大規模な共通化

## テーマIDとversion

テーマIDは `themes/` 直下のディレクトリ名であり、`theme.json.name` と完全一致させる。

許可する形式:

```text
[A-Za-z0-9_-]+
```

`theme.json.version` は次の3要素形式とする。

```text
<major>.<minor>.<patch>
```

例:

```text
1.0.0
1.2.3
```

## ZIP内部構造

ZIPルートには、テーマIDと同名のディレクトリを1件だけ置く。

```text
tomos-example/
├── theme.json
├── templates/
│   ├── layout.html
│   ├── page.html
│   ├── list.html
│   └── home.html          # 任意
├── assets/
│   ├── style.css
│   └── ...
├── preview.png            # 推奨
├── README.md              # 推奨
└── LICENSE                # 推奨
```

ZIPルート直下のファイル、複数のテーマディレクトリ、二重のテーマディレクトリは拒否する。

## 必須ファイル

テーマとして動作するために次の5件を必須とする。

```text
theme.json
templates/layout.html
templates/page.html
templates/list.html
assets/style.css
```

欠落または空ファイルの場合は追加を拒否する。

`templates/home.html` は任意とする。存在しない場合は `templates/page.html` へフォールバックする。

## 配布時の推奨ファイル

次の3件は、個人制作テーマのアップロード必須条件にはしない。

```text
preview.png
README.md
LICENSE
```

不足時はエラーではなくwarningとして確認画面へ表示し、テーマ追加を許可する。

`preview.png` が存在する場合は、PNG画像として読み取れることを確認する。不正な画像は拒否する。

公式サイトや第三者へ配布するテーマでは、説明、利用条件、プレビューを明確にするため3件の同梱を推奨する。

## 許可するファイル

テーマ直下:

```text
theme.json
preview.png
README.md
LICENSE
```

`templates/` 直下:

```text
layout.html
page.html
list.html
home.html
```

`assets/` 配下ではサブディレクトリを許可し、次の拡張子だけを許可する。

- CSS: `.css`
- 画像: `.png`, `.jpg`, `.jpeg`, `.gif`, `.webp`, `.svg`, `.ico`
- フォント: `.woff`, `.woff2`

JavaScript、PHP、HTMLアセット、任意JSON、サーバー設定ファイル、許可外拡張子は拒否する。

## macOSメタデータ

Finder等で作成したZIPに含まれる次のメタデータは、テーマ内容ではないため無視する。

```text
__MACOSX/
.DS_Store
._*
```

これらは次の対象に含めない。

- テーマルート判定
- パス衝突判定
- 許可ファイル判定
- 展開
- ファイル数
- 展開後容量
- recordのファイル一覧

無視対象は上記3種類に限定する。危険なパスや不明なdotfileを一般的に許可するものではない。

## 拒否するもの

- `../`、`.`、空セグメント、連続スラッシュ
- 絶対パス、drive/colon、バックスラッシュ
- NUL、制御文字、不正UTF-8
- 大文字小文字またはUnicode正規化で衝突するパス
- ファイルとディレクトリが矛盾する構造
- PHP系ファイル、JavaScript、許可外拡張子
- `.git`, `.svn`, `.hg` 等のVCSメタデータ
- swap、backup、一時ファイル
- symlink、device、FIFO、socket、不明な特殊エントリー
- 実行可能ファイル
- 暗号化ZIP
- store/deflate以外の圧縮方式

## 容量と件数

内部値はバイト単位で管理する。

- アップロードZIP: 10 MiB（10,485,760 bytes）
- 展開後合計: 30 MiB
- 1ファイル: 5 MiB
- ZIPエントリー: 200件
- テーマルート下のディレクトリ階層: 最大4階層

利用者向け画面では、一般的な表記として `MB` を使用する。

実効アップロード上限は次の最小値とする。

- TomosのZIP上限
- `upload_max_filesize`
- `post_max_size` からrequest overheadを差し引いた値

画面には「テーマZIPの上限：最大10 MB」と、必要に応じてサーバーの実効上限を表示する。

## 一時領域

アップロードしたZIPは次へ保存する。

```text
storage/theme-upload-tmp/<ランダムID>/
├── package.zip
├── record.json
└── extracted/
    └── <テーマID>/
```

- ランダムIDは16バイトの暗号学的乱数を16進表記する。
- 一時ディレクトリは原則0700、ファイルは原則0600とする。
- 元ファイル名、テーマID、セッションIDを一時ディレクトリ名に使わない。
- recordには所有者ハッシュ、期限、ZIP SHA-256、各ファイルの相対パス・容量・SHA-256を保存する。
- 確認期限は30分とする。
- 1時間を超えた一時データはstale cleanup対象とする。

## 二段階処理

### アップロード・検査

1. 管理認証とCSRFを確認する。
2. HTTPアップロードエラー、拡張子、MIME、容量を確認する。
3. ZIP中央ディレクトリと各エントリーを展開前に検査する。
4. macOSメタデータを限定的に除外する。
5. 許可された通常ファイルだけをstreamで一時領域へ展開する。
6. テーマID、version、必須ファイル、許可リストを検査する。
7. `ThemeValidator` を実行する。
8. 同じテーマIDが存在しないことを確認する。
9. recordを保存し、確認画面を表示する。

`ZipArchive::extractTo` による未検査エントリーの一括展開は行わない。

### 確定追加

1. 管理認証、CSRF、所有者、期限、ZIP SHA-256を再確認する。
2. ZIPエントリーと展開済みファイルを再照合する。
3. `storage/theme-upload.lock` を取得する。
4. 同じテーマIDが存在しないことを再確認する。
5. `themes/.tomos-theme-staging-<ランダムID>/<テーマID>/` へコピーする。
6. hidden stagingで再検証する。
7. `themes/<テーマID>/` へrenameする。
8. 配置後に再検証する。
9. 一時データとlockを削除する。

## 原状維持

確定renameより前に失敗した場合、最終テーマディレクトリを作らない。

配置後検証に失敗した場合は、今回追加したテーマをhidden stagingへ戻して削除する。既存テーマ、使用中テーマ、`config.php`、content、cache、Tomos Update用データは変更しない。

同じテーマIDが存在する場合は、valid・invalid・使用中を問わず拒否し、上書きしない。

## Tomos Updateとの分離

テーマZIP追加機能はTomos Updateとは別機能とする。

- `UpdateService` と `UpdateLock` を使用しない。
- Update ZIPの署名やmanifestをテーマZIPへ流用しない。
- 利用者が追加したテーマをTomos Updateで上書き・削除しない。
- テーマ追加機能のPHPファイル自体は、通常配布ZIPおよび将来のUpdate ZIPで更新できる。

## 検証

ローカル自動テスト:

```bash
php tests/theme_package_upload_check.php
php tests/theme_package_http_check.php
```

確認内容:

- 正常な二段階追加
- 必須ファイル不足の拒否
- 推奨3ファイル不足時のwarningと追加許可
- Finder由来のmacOSメタデータの無視と未展開
- 危険パス、PHP、JavaScript、symlink、特殊ファイルの拒否
- 重複テーマID、CSRF、期限、owner、record改変、lock競合
- hidden staging、rename、配置後検証、rollback
- 追加後の一覧表示、手動切り替え、公開render
- 一時データとlockの削除

実サーバーの開発サイトでは、Finderで作成したテーマZIPについて、アップロード、検査、確定追加、テーマ切り替え、公開表示まで確認済みである。
