# PR #114 ローカル認証レビュー結果

2026-10-25 / 対象 `tomosweb/tomos` / `feature/post-passkey-security-20261025`

## 判定

修正後候補は**隔離された実環境での検証へ進行可、公開不可**。
**実サーバーへ未接続。iPhone Safari・macOS SafariのWebAuthn、さくらPHP・HTTPS・Cookie・Tomos Updateを含むE01–E16は全件NOT RUN。**
mainマージ、タグ作成、Release発行、実サーバー配置は実施していない。
GitHub書き込みツールはこのセッションの承認ポリシーneverで拒否されたため、push・PRコメントは未完了。GitHub上のDraftとHEADは変更していない。

- 調査元のPR HEAD: `22ffd740e246446a4aa89bbbd162c5b682d0640a`
- 調査時の最新main: `324065aa446dd0f1f96a314da3db786dfb3474d8`（PR baseと一致）
- 調査元全ファイルをGitHubのGit blob SHAで照合して隔離コピーを構築した。元作業フォルダは別リポジトリ・別ブランチのため変更していない。
- 通常のgit取得はGit管理領域の書込制限とネットワークDNS制限により不可。ローカル検証用のGit履歴は既存ローカル履歴とソーススナップショットから構築しており、PRの実コミット履歴とは異なる。
- 既存の完了報告・Actions結果は判定の根拠に使用していない。

## 修正と再現

| 問題 | 実測・修正 | 回帰テスト |
| --- | --- | --- |
| 未ログインで取得したパスキー登録許可が認証世代変更後も残る | 調査元で失敗を再現。登録許可を世代に紐付け、登録確定時にも最新設定を検証。専用モードのoptions/completeに直近パスキー認証を要求 | `post_auth_policy_check.php`、`passkey_registration_service_check.php`、実エンドポイント試験 |
| 従来のパスキー経由合言葉再設定で認証世代が変わらない | 調査元で再現。既存設定の書式・コメントを保ったまま世代を追加/更新。古い世代の再設定許可は設定ロック内で拒否 | `post_password_hash_updater_check.php`、既存resetサービス試験 |
| 削除開始後のモード変更と最後の1件削除が競合 | 調査元で再現。モード変更と管理削除を共通ロックにし、削除側は取得後に最新設定を読む。未知モードも最後の1件を保護 | `passkey_management_concurrency_check.php`（12組の同時削除・待機中モード変更） |
| 名称変更/利用履歴更新が削除済み資格情報を再保存しうる | 読込から保存まで管理ロックに揃えた。利用履歴の既存カウンターロックは維持 | 管理・利用履歴の既存試験と並行試験 |
| 認証失敗回数の並行更新を取りこぼす | 調査元では240件の同時記録が欠落することを実測。読込・加算・保存をロック内に置き、JSONは一時ファイルから置換 | `post_rate_limiter_concurrency_check.php`（12プロセス×20件、240件保持） |
| PHP 7.4の同時初回ディレクトリ作成 | 実PHP 7.4で再現。一段ずつ親を確認して作成し、競合後の存在を再確認 | 上記レート制限並行試験を実PHP 7.4で実行 |
| 合言葉ログインでPHPセッションIDを更新しない | 共通認証確立処理でID更新し、合言葉認証時は古いパスキー再認証時刻を破棄 | 実エンドポイント試験でID変更確認 |
| パスキー未登録時のAPIトークン再発行が古いログインだけで可能 | 現在の合言葉による再認証・レート制限を追加。登録済み時のパスキー再認証要求は維持 | 実エンドポイント試験 |
| 再認証後に管理/登録/セキュリティ/更新へ戻れない | 安全な固定戻り先を追加。Update・仕上げにも戻り先を付与 | `post_auth_return_to_check.php`、不正URL/サブディレクトリ既存試験 |
| 専用モードに合言葉フォールバックを示唆する案内 | 登録/削除/依存欠落時の説明とリンクを修正 | 実エンドポイント出力検査。実ブラウザの視覚確認は未実施 |

調査元へ追加回帰テストを適用し、匿名登録許可・合言葉再設定世代・登録サービス世代・待機中削除モード・並行認証失敗記録の5件が失敗することを確認した。修正後はいずれもPASS。

## 全25変更ファイルのレビュー範囲

- 設定/方針: `config.sample.php`、`core/ConfigWriter.php`、`core/PostAuthPolicy.php`、`core/PostAuthRememberToken.php`。
- 管理操作: `core/PasskeyManagementService.php`、`post/security/index.php`、`post/passkey/login/index.php`、`post/passkey/manage/index.php`、`post/passkey/register/index.php`、`post/reset/index.php`。
- Post境界: `post/index.php`、`post/auth-gate.php`、`post/inbox/image/index.php`、`post/write-handoff.php`、`post/social/bluesky/index.php`、`post/social/bluesky/callback/index.php`。
- 更新境界: `update/index.php`、`post/update-finalize/index.php`。Update固有CSRF・パッケージ所有者・署名・更新ロックは維持。
- 配布/設置必須一覧: `core/required-installed-files.txt`、`tools/required-distribution-files.txt`、`tools/required-source-files.txt`。
- 試験/記録: `tests/post_auth_policy_check.php`、`tests/post_auth_remember_token_check.php`、`tools/check-post-security-local.sh`、`docs/security/post-auth-live-validation.md`。

追加で全 `post/**/*.php`、Obsidian API、テーマ追加/確認/削除、設定/ナビゲーション/解析、パスキーサービス、ConfigWriteLock、合言葉再設定、更新サービス、配布ビルダーを追跡した。

## 実行結果

| 条件 | 結果 | 限界 |
| --- | --- | --- |
| PHP 7.4.33 | 一次PHP lint・認証事前テストPASS | WebAuthn runtimeはPHP>=8.0必須なのでSKIP。パスキーが使えるという判定ではない |
| PHP 8.0.30 | 一次PHP lint・認証事前テスト・WebAuthn配布検査PASS | CLI。Safari/HTTPS実通信ではない |
| PHP 8.2.33 | 同上PASS | 同上 |
| PHP 8.5.9 | 同上PASS | 同上 |
| `tests/*_check.php` 166件 | **150 PASS / 16 SKIP / 0 FAIL** | 個別結果はJSON。依存/検証用データを整備して再実行し、初回失敗をPASSに読み替えてはいない |
| JavaScript 3件 | PASS | image_preprocessor、image_selection_runtime、write_new_article_handoff_runtime |
| 実PHPエンドポイント別プロセス試験 | 各PHP版PASS | PHP実処理・ステータス・セッションを検証。HTTPヘッダー配送やCookieのブラウザ適用、WebAuthn実署名は対象外 |
| 専用検証配布ZIP | ビルド・必須ファイル・依存・ZIP内容検査PASS | config.phpと秘密鍵は含まない |
| 専用署名付きUpdate ZIP | 署名検証・apply・更新仕上げ・config.phpのバイト保持PASS | 更新サービスをCLIで実行。E14実ブラウザ試験の代用ではない |
| Git diff / シェル構文 | PASS | 公開操作なし |

固定依存 `lbuchs/webauthn v2.2.0` はcomposer.lockのcommit `20adb4a240c3997bd8cac7dc4dde38ab0bea0ed1` の一次ソースから取得し、各blobを照合した。Composer通常取得はDNS制限で失敗したため、照合済みソースからローカルのComposerでautoloadを生成した。依存テスト資産は配布していない。GitHub Actionsは未使用。

SKIPの内訳は、待受禁止で実行不能のHTTP/Apache試験7件、専用検証用データ不足・対象外の旧リリース試験9件。ローカル待受失敗の生ログを残し、代替の別プロセス試験をHTTPのPASSとは数えていない。

## 候補配布物の照合

| ファイル | SHA-256 |
| --- | --- |
| tomos-pr114-validation.zip | 85a1362e3156621a8fe5f158416ab924e955414fcae8f85a24bd8800801dd182 |
| tomos-pr114-update-validation.zip | 2d22159f4ab684d0762651ddea97963cd16b087de1b0ea473bd635978c3ce0c3 |
| validation-public-key.pem | e0fb22bce470d2ece80bb13fe325b26f3205b9eb9489bd9abc395511950e39c4 |

検証用VERSIONと検証公開鍵を除くランタイムは修正後ソースから生成。秘密鍵は一時領域で生成・削除しており、再ビルドでは署名とハッシュが変わる。ZIPは同じ一組を使用する。
既存ユーザーのconfig.php、記事、パスキー、APIトークンには触れていない。実サーバー・ブラウザでの認証保証や全競合条件の完全排除を主張しない。
