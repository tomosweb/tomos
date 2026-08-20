# 配布物の構成

この文書はプロジェクト管理者向けの内部確認メモです。通常の利用者が配布物を作成する必要はありません。

## 含めるもの

```text
index.php
.htaccess
config.sample.php
core/
setup/
post/
update/
storage/
storage/.htaccess
storage/update-backups/.gitkeep
storage/update-logs/.gitkeep
storage/update-tmp/.gitkeep
trash/
themes/
content/
cache/
cache/html/.gitkeep
cache/html/.htaccess
cache/logs/.gitkeep
cache/logs/.htaccess
cache/post-upload-sessions/.gitkeep
cache/post-upload-sessions/.htaccess
cache/security/.htaccess
cache/security/post-rate-limit/.gitkeep
cache/security/post-rate-limit/.htaccess
docs/
docs/README.md
docs/user/writing.md
docs/user/first-content.md
docs/user/faq.md
docs/install/install.md
docs/install/update.md
docs/install/tomos-update.md
docs/install/setup.md
docs/install/hosting.md
docs/install/troubleshooting.md
docs/features/tags.md
docs/features/search.md
docs/features/wiki-links.md
docs/features/images.md
docs/features/post.md
docs/features/themes.md
docs/features/rss-sitemap.md
docs/features/cache.md
docs/theme/theme-authoring.md
docs/theme/theme-validation.md
docs/theme/theme-checklist.md
docs/theme/ai-theme-prompt.md
docs/project/distribution.md
docs/project/checklist.md
docs/project/security.md
docs/project/performance.md
README.md
INSTALL.md
VERSION
LICENSE
NOTICE
TRADEMARKS.md
CHANGELOG.md
SECURITY.md
KNOWN_LIMITATIONS.md
```

## 含めないもの

```text
config.php
post-reset.enable
cache/index/pages.json
cache/index/link-aliases.json
cache/html/*.html
cache/html/*.json
cache/logs/*.log
cache/logs/*.tmp
cache/security/post-rate-limit/*.json
cache/security/post-rate-limit/*.tmp
cache/security/post-rate-limit/*.log
cache/post-upload-sessions/*.json
cache/post-upload-sessions/*.lock
cache/post-upload-sessions/*-images/
trash/content/
trash/**/*.json
trash/**/*.tmp
trash/**/*.log
storage/update.lock
storage/update-backups/*
storage/update-logs/*
storage/update-tmp/*
.DS_Store
.env
.htpasswd
*.tmp
*.log
不要なローカル作業ファイル
tests/
themes/tomos-creator/
themes/tomos-radical-poster/
docs/theme/ai-theme-safe-workflow-draft.md
```

## 扱いを明記するもの

- `config.php`: 初回setupで生成するため、配布物には含めません。
- `config.sample.php`: 設定ひな型として含めます。
- `.htaccess`: 必須です。不可視ファイルのためアップロード漏れに注意します。
- `cache/index/.gitkeep`: 空ディレクトリ維持のため含めます。
- `cache/index/link-aliases.json`: 実行環境で生成されるObsidianリンク解決用alias辞書のため、配布物には含めません。
- `cache/html/.gitkeep`: HTMLキャッシュ用の空ディレクトリ維持のため含めます。
- `cache/.htaccess` / `cache/index/.htaccess` / `cache/html/.htaccess`: cache配下のPHP実行、JSON直接参照、HTMLキャッシュ直接参照を防ぐために含めます。
- `cache/html/*.html` / `cache/html/*.json`: 実行環境で再生成されるキャッシュ生成物のため、配布物には含めません。
- `cache/logs/.gitkeep` / `cache/logs/.htaccess`: パフォーマンス計測ログ用ディレクトリを維持し、直接閲覧を防ぐために含めます。
- `cache/logs/*.log` / `*.tmp`: 実行環境で生成される計測ログのため、配布物には含めません。
- `cache/security/.htaccess` / `cache/security/post-rate-limit/.htaccess`: Tomos Postのレート制限JSONの直接閲覧を防ぐために含めます。
- `cache/security/post-rate-limit/.gitkeep`: レート制限用ディレクトリ維持のため含めます。
- `cache/security/post-rate-limit/*.json` / `*.tmp` / `*.log`: 実行環境で生成される一時データのため、配布物には含めません。
- `cache/post-upload-sessions/.gitkeep` / `.htaccess`: 順次画像送信の一時領域を維持し、Webからの直接閲覧を拒否します。
- `cache/post-upload-sessions/` のJSON、ロック、一時画像: 実行環境固有の未確定データのため、配布物には含めません。
- `setup/`: 初回配布には含めます。setup完了後は削除またはリネームを推奨します。
- `post/`: Tomos Post画面として含めます。Tomos Writeなどで作成したMarkdownファイルの投稿、取り下げ、trash整理、テーマ切り替えを扱う最小機能です。
- `post/theme/`: Tomos Post内のテーマ切り替え画面として含めます。
- `update/`: Tomos Updateの管理画面と署名検証用公開鍵を含めます。秘密鍵は含めません。
- `storage/.htaccess`: 更新バックアップ、ログ、一時ファイルへのWebアクセスを拒否するために含めます。
- `storage/update-backups/.gitkeep` / `storage/update-logs/.gitkeep` / `storage/update-tmp/.gitkeep`: Tomos Update用の空ディレクトリ維持のため含めます。
- `storage/` の更新バックアップ、ログ、一時ファイル、`update.lock`: 実行環境固有の運用データのため、配布物には含めません。
- `post-reset.enable`: 管理用合言葉を再発行するための運用ファイルです。配布物には含めません。
- `trash/.htaccess` / `trash/.gitkeep`: 取り下げ済みファイルの退避先を保護し、空ディレクトリを維持するために含めます。
- `trash/content/`: 実行環境で取り下げ時に生成される退避ファイル置き場です。配布物には含めません。
- `themes/tomos-minimal/`: 標準テーマとして含めます。追加テーマは `themes/` 配下に置きます。
- `themes/tomos-journal/`: 日記・エッセイ・個人の記録向けの同梱サンプルテーマとして含めます。
- `themes/tomos-dark/`: 暗い背景で文章を読める同梱サンプルテーマとして含めます。
- `themes/tomos-90s/`: 1990年代風の同梱サンプルテーマとして含めます。
- `themes/tomos-note/`: ノート風の同梱サンプルテーマとして含めます。
- `themes/tomos-creator/` / `themes/tomos-radical-poster/`: 公開サイト用・試作用テーマのため、配布物には含めません。
- `tests/`: 開発確認用です。公開配布物には含めなくて構いません。含める場合でも `tests/.htaccess` でWeb実行を拒否します。
- `VERSION`: 配布バージョンを示します。
- `LICENSE`: Tomos本体コード、標準テーマ、サンプルcontentに適用するMIT Licenseです。
- `NOTICE`: MIT Licenseの適用範囲と対象外素材を示します。
- `TRADEMARKS.md`: Tomosの名称、ロゴ、アイコン、OGP画像の扱いを示します。
- `.htpasswd`: Basic認証の認証情報です。環境ごとに用意するもので、配布物には含めません。

## ロゴ・アイコン・OGP画像

Tomosのロゴ・アイコン・OGP画像は、Tomosプロジェクトの識別表示目的で同梱されます。

これらはMIT Licenseの対象外です。別プロダクト、別サービス、派生プロジェクトの名称やロゴとして使用することはできません。

## 0.x開発系列としての配布

Tomosは0.x開発系列です。1.0の安定版ではないため、既存サイトへ上書きせず、空のテスト用ディレクトリで主要機能を確認してから設置してください。

既存サイトへ上書きせず、空のテスト用ディレクトリへ設置することを推奨します。

## 配布Zipの構成確認

Zipを開いた直下に `index.php` と `.htaccess` がある構成にしてください。

良い例:

```text
index.php
.htaccess
core/
setup/
post/theme/
post/theme/confirm/
post/
update/
storage/
trash/
themes/
themes/tomos-minimal/
themes/tomos-journal/
themes/tomos-dark/
content/
cache/
```

避ける構成:

```text
Tomos/index.php
Tomos/.htaccess
Tomos/core/
```

配布Zipには、目的別に整理した `docs/` 構成も含めます。利用者向けは `docs/user/` と `docs/install/`、機能説明は `docs/features/`、テーマ関連は `docs/theme/`、内部確認用は `docs/project/` に分かれます。
