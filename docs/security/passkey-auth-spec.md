# Tomos Post パスキー認証 本実装仕様

## 1. 目的

Tomos Postの現行管理用合言葉認証を維持したまま、WebAuthnパスキーを追加の認証経路として実装する。

目的は次の2点である。

- スマートフォン、PCから管理用合言葉を入力せずTomos Postへ入れるようにする
- 管理用合言葉を忘れた場合、登録済みパスキーで認証して新しい管理用合言葉へ再設定できるようにする

パスキーは管理用合言葉を廃止・置換しない。

## 2. 設計上の前提

パスキーはWebAuthnの標準的な登録・認証フローを用い、既存のTomos Post認証済みセッションへ接続する。

本実装では単一credentialを前提とせず、複数パスキー、永続credential保存、管理用合言葉へのフォールバック、合言葉再設定、サーバー所有確認による初回復旧を製品仕様として扱う。

## 3. 基本方針

認証経路は次の2系統とする。

1. 現行の管理用合言葉認証
2. 登録済みパスキーによるWebAuthn認証

パスキー機能が利用不可、未登録、障害中であっても、現行の管理用合言葉認証は必ず利用できること。

投稿、編集、サイト設定、テーマ管理、Tomos Update等の既存処理は認証方式を意識せず、既存の認証済みセッションを参照する。

## 4. 利用条件

Tomos本体の最低動作環境は変更しない。

パスキー機能のみ、次の条件をすべて満たす場合に有効とする。

- PHP 8.0以上
- OpenSSL拡張が利用可能
- mbstring拡張が利用可能
- HTTPS
- WebAuthn対応ブラウザ
- パスキー用ライブラリを正常に読込可能

条件不足時はパスキー機能を無効化し、管理用合言葉認証のみ表示・利用する。

PHP 7.4環境でもTomos Postの既存機能は従来どおり動作すること。

## 5. WebAuthnライブラリ

本実装では `lbuchs/WebAuthn` v2.2.0を使用する。

採用理由:

- PHP 8.0以上で利用できる
- 外部依存が比較的小さい
- Composer利用を開発・ビルド工程に限定できる
- MIT License

暗号処理、Authenticator Data、Client Data JSON、署名検証をTomos独自実装しない。

ライブラリ更新は自動追従せず、Tomosのリリース単位で固定バージョンを検証する。

## 6. Composerと配布

利用者へComposerのインストールを要求しない。

Composerは開発・配布生成時だけ使用し、`core/webauthn/composer.lock` で `lbuchs/WebAuthn` v2.2.0を固定する。実行に必要なライブラリ一式は `core/webauthn/vendor/` としてTomos通常配布ZIPおよびTomos Update ZIPへ同梱する。

配布生成時は次を必須とする。

1. `composer install --working-dir=core/webauthn --no-dev --prefer-dist --no-interaction --classmap-authoritative` を実行する
2. `core/webauthn/vendor/autoload.php` が存在することを確認する
3. `core/webauthn/vendor/lbuchs/webauthn/src/WebAuthn.php` が存在することを確認する
4. 通常配布ZIPとUpdate ZIPの双方に `core/webauthn/vendor/` を含める
5. Update後の必須ファイル検査でも上記ファイルを確認する

`composer.phar`、Composer本体、開発用キャッシュは配布物へ含めない。

## 7. RP IDとOrigin

RP IDはTomosのサイトURL設定を基準として安全に決定する。

サブディレクトリはRP IDへ含めない。

例:

- URL: `https://example.com/tomos/post/`
- RP ID: `example.com`

Origin検証ではscheme、host、portを一致させる。

ホスト名の移行、`www` 有無、サブドメイン変更は既存パスキーが利用できなくなる可能性があるため、Tomosが自動的に別RP IDへ読み替えない。

Hostヘッダーを無条件に信頼せず、TomosのサイトURL設定との整合を確認してRP ID/Originを決定する。

## 8. credential保存

パスキー資格情報は `cache/` に保存しない。

Tomos Update、キャッシュ削除、HTML再生成で消えない永続領域 `storage/security/passkeys/` へ保存する。

1 credential 1 JSONを基本とし、credential IDそのものをファイル名に直接使用せず、安全なハッシュ名を使用する。

保存項目:

- schema_version
- credential_id
- public_key
- sign_count
- transports（取得できる場合）
- label
- created_at
- last_used_at
- rp_id

保存しないもの:

- 秘密鍵
- Face ID / Touch ID等の生体情報
- 管理用合言葉
- WebAuthn challengeの恒久保存

ファイルはWebから直接取得できないこと。保存時は一時ファイルからのatomic renameを用い、不完全JSONを残さない。

## 9. challenge管理

登録・認証challengeはPHPセッションへ保存し、短時間のみ有効とする。

challengeは一度使用したら破棄する。

登録challenge、認証challenge、合言葉再設定challenge、サーバー復旧用登録challengeを混用しない。

期限切れ、セッション不一致、challenge不一致は認証失敗とする。

## 10. パスキー登録

通常のパスキー追加は管理用合言葉による再認証を必須とする。

登録手順:

1. パスキー機能の環境診断
2. 管理用合言葉で再認証
3. registration challenge発行
4. ブラウザで `navigator.credentials.create()`
5. サーバーでWebAuthn登録レスポンスを検証
6. credentialを永続保存
7. 登録成功を表示

登録済みcredential IDと同一のcredentialは重複登録しない。

複数パスキーを登録可能とする。

ただし、管理用合言葉を忘れ、かつパスキー未登録の場合は、後述するサーバー所有確認が成功した1回に限り、最初のパスキー登録を許可する。

## 11. パスキー認証

Tomos Postの認証画面に次の経路を用意する。

- パスキーで開く
- 管理用合言葉で開く

パスキー認証手順:

1. authentication challenge発行
2. `navigator.credentials.get()`
3. credential IDに対応する保存済み公開鍵を取得
4. RP ID、Origin、challenge、署名等をライブラリで検証
5. 成功時に既存の `$_SESSION['tomos_post_authenticated'] = true` を設定
6. `last_used_at` 等を更新

認証に失敗しても管理用合言葉認証へ戻れること。

## 12. user verification

Tomos Postの管理操作に用いるため、WebAuthnのuser verificationは原則 `required` とする。

Face ID、Touch ID、端末PIN等の具体的な方式はAuthenticator側へ委ねる。

Tomosは生体情報を取得・保存しない。

## 13. signCount

保存可能なAuthenticatorではsignCountを更新する。

ただし同期型パスキーではカウンターが常に増加するとは限らないため、単純な「増加しなければ必ず拒否」という実装は行わない。

同期型パスキーの正当な利用を阻害しない範囲でライブラリの検証結果を利用する。

## 14. パスキー管理画面

Tomos Post内にパスキー管理画面を設ける。

最低限:

- 登録済みパスキー一覧
- 任意の識別名称
- 登録日時
- 最終利用日時
- 追加
- 個別削除

端末名をTomos側で推測しない。

利用者が「iPhone」「MacBook」等の名称を自由に付けられるようにする。

同期型パスキーでは複数端末から同一credentialが利用される可能性があるため、「1 credential = 1物理端末」とは表示しない。

## 15. パスキー削除

パスキー削除はTomos Postへの認証済み状態とCSRF保護を要求する。

最後のパスキーを削除しても管理用合言葉認証は残るため、削除自体は禁止しない。

削除後は対応credentialでの認証を直ちに拒否する。

## 16. 管理用合言葉の再設定

登録済みパスキーでWebAuthn再認証に成功した利用者は、新しい管理用合言葉を設定できる。

現在の管理用合言葉を表示・復元する機能は設けない。

再設定手順:

1. パスキーによる再認証
2. 短時間有効な再設定許可状態をセッションへ保存
3. 新しい管理用合言葉を2回入力
4. `config.php` の `post_password_hash` だけを安全に更新
5. 既存の記憶認証トークンをすべて失効
6. 現在の認証済み状態と関連セッションをリセット
7. CSRFトークンを再発行

旧合言葉では以後認証できないこと。

## 17. パスキー未登録・合言葉忘れ時の復旧

管理用合言葉を忘れ、登録済みパスキーもない場合は、サーバーへの書き込み権限を確認して最初のパスキーを登録し、そのパスキーで管理用合言葉を再設定できる。

復旧手順:

1. `/post/passkey/recovery/` で短時間有効な復旧要求を発行する
2. Tomosがランダムなファイル名 `tomos-recovery-<random>.txt` と内容を生成する
3. 利用者はZIPをダウンロードして展開する。ZIPが利用できない場合はTXTを直接取得できる
4. 展開したTXTだけをSFTP/FTPでTomos設置ルート、すなわち `config.php` と同じフォルダへアップロードする
5. Tomosはセッションに保持した要求と、ランダムファイル名・内容を照合する
6. 一致したTXTを即時削除する。削除できない場合は復旧を続行しない
7. 5分間だけ、最初のパスキー1件の登録を許可する
8. 登録したパスキーで改めてWebAuthn再認証する
9. 新しい管理用合言葉を設定する

復旧用ファイル名とファイル内容は独立した乱数から生成し、challengeそのものをファイル名に使用しない。復旧要求はセッションに束縛し、短時間・一回限りとする。

現在のRP IDに対するパスキーが1件でも存在する場合、このサーバー所有確認経路は利用できない。

## 18. 記憶認証との関係

既存の `PostAuthRememberToken` は維持する。

初回実装では、パスキー認証成功だけを理由に自動で30日記憶トークンを発行しない。

管理用合言葉を再設定した場合は全記憶認証トークンを失効する。

## 19. レート制限

管理用合言葉の既存失敗制限は変更しない。

パスキー失敗を管理用合言葉失敗回数へ加算しない。

challengeは短時間・一回限りとし、連続再利用を認めない。

## 20. CSRF

パスキー登録、削除、名称変更、合言葉再設定、サーバー復旧の状態変更操作はCSRF保護を適用する。

WebAuthn challengeだけをCSRF対策の代替にしない。

## 21. UIと導線

通常の利用者向け導線は `/post/` と `/post/security/` を中心にする。内部の `/post/passkey/.../` URLを利用者へ機能一覧として露出しない。

`/post/` はTomos Postの入口として、必要に応じて次を提供する。

- パスキーで開く
- 管理用合言葉で開く
- 合言葉を忘れた場合

認証後のTomos Postには「セキュリティ」導線を設ける。

`/post/security/` は次の2目的を案内する。

- パスキーを管理する
- 管理用合言葉を忘れた場合

関連画面の主タイトルは `Tomos Post` とし、その下に「セキュリティ」「パスキー管理」「パスキーを追加」「パスキーで合言葉を再設定」等の機能名を表示する。ボタン名と遷移先の機能タイトルは一致させる。

パスキー利用条件を満たさない環境では、通常利用時に技術的な警告を常時表示しない。セキュリティ画面でのみ利用不可理由を確認できるようにする。

## 22. 非対応・障害時フォールバック

次のどの状態でも管理用合言葉認証は利用できること。

- PHP 7.4
- OpenSSL/mbstring不足
- HTTP
- WebAuthn非対応ブラウザ
- JSエラー
- ライブラリ読込失敗
- credential保存領域の読込失敗
- パスキー未登録
- 登録済みパスキー喪失
- WebAuthn認証失敗

パスキー関連コードのFatal errorによって `/post/` 自体を利用不能にしない。

## 23. Tomos Update

パスキーcredentialは利用者データとして扱い、Tomos Updateの更新対象へ含めない。

Updateでライブラリ・PHP実装を更新しても、`storage/security/passkeys/` の登録済みパスキーを維持する。

通常配布ZIPとUpdate ZIPには `core/webauthn/vendor/` を含める。`core/required-installed-files.txt` では、パスキー実装とWebAuthn runtimeの代表必須ファイルを検査する。

Updateで保持する不変条件:

- `storage/security/passkeys/` を更新対象にしない
- 既存credentialの保存形式を破壊しない
- 管理用合言葉認証を維持する
- WebAuthn runtimeを欠落させない
- `core/required-installed-files.txt` によるインストール整合性検査を通過する

## 24. バックアップ

パスキーcredential保存領域 `storage/security/passkeys/` はTomosのバックアップ対象として明記する。

ただしcredentialファイル単体を別ドメインへ復元してもRP ID/Originが一致しなければ利用できない。

## 25. ログ

認証ログへ秘密情報を保存しない。

記録する場合の候補:

- 成功/失敗
- 処理種別（register/authenticate/delete/reset-password/recovery）
- credentialの不可逆な識別用ハッシュ
- 時刻
- エラー分類

credential IDそのもの、challenge、公開鍵全文、clientDataJSON、署名値は通常ログへ残さない。

## 26. 実装単位

本実装は以下へ責務分離する。

- 環境判定
- WebAuthnライブラリadapter
- credential repository
- challenge/session管理
- パスキー登録
- パスキー認証
- パスキー管理
- 合言葉再設定authorization
- サーバー所有確認による復旧

`post/index.php` にWebAuthn処理を集中させない。

## 27. 実装受入条件

- 現行の管理用合言葉認証を維持する
- PHP 7.4ではパスキー機能を無効化し、Tomos Postの既存機能を維持する
- PHP 8.0および8.2でWebAuthn runtimeを読み込める
- 管理用合言葉で再認証後、パスキーを追加できる
- 複数パスキーを登録できる
- パスキー認証後、既存Tomos Postへ認証済み状態で入れる
- パスキー一覧、名称、登録日時、最終利用日時を扱える
- 個別パスキーを削除できる
- パスキー認証後、管理用合言葉を再設定できる
- 再設定後、旧合言葉では認証できない
- 合言葉再設定時に既存の記憶認証トークンがすべて失効する
- パスキー未登録かつ合言葉忘れ時に、サーバー所有確認から最初のパスキー登録、再認証、合言葉再設定まで継続できる
- 復旧成功後にアップロードしたTXTを削除し、削除できない場合は復旧を継続しない
- サブディレクトリ設置でもRP IDはホスト名を基準に扱う
- 通常配布ZIPとUpdate ZIPの双方に `core/webauthn/vendor/` を含める
- Tomos Updateで `storage/security/passkeys/` を更新対象にしない
- パスキー機能の障害時も管理用合言葉認証を利用できる
- PHP 7.4 / 8.0 / 8.2互換CIとパスキー関連自動テストが成功する
