# Tomos Post パスキー認証 本実装仕様

## 1. 目的

Tomos Postの現行管理用合言葉認証を維持したまま、WebAuthnパスキーを追加の認証経路として実装する。

目的は次の2点である。

- スマートフォン、PCから管理用合言葉を入力せずTomos Postへ入れるようにする
- 管理用合言葉を忘れた場合、登録済みパスキーで認証して新しい管理用合言葉へ再設定できるようにする

パスキーは管理用合言葉を廃止・置換しない。

## 2. PoCで確認済みの前提

PR #25のPoCを `https://tomoswords.org/dev/` へ適用し、以下を確認済みとする。

- PHP 8.2.32
- OpenSSL有効
- mbstring有効
- HTTPS
- RP ID `tomoswords.org`
- `/dev/` サブディレクトリ設置
- Mac Chromeでパスキー登録成功
- iPhone Safariでパスキー登録成功
- iPhone Safariでパスキー認証成功
- WebAuthn認証成功後、既存の `$_SESSION['tomos_post_authenticated'] = true` へ接続し、管理用合言葉を再入力せずTomos Postを利用できた

本実装ではPoCの単一credential保存や独立画面をそのまま製品化しない。

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

本実装の第一候補は `lbuchs/WebAuthn` v2.2系とする。

採用理由:

- PoCで実開発環境、Mac Chrome、iPhone Safariの登録・認証に成功した
- PHP 8.0以上で利用できる
- 外部依存が比較的小さい
- Composer利用を開発・ビルド工程に限定できる
- MIT License

暗号処理、Authenticator Data、Client Data JSON、署名検証をTomos独自実装しない。

ライブラリ更新は自動追従せず、Tomosのリリース単位で固定バージョンを検証する。

## 6. Composerと配布

利用者へComposerのインストールを要求しない。

開発・配布生成時にComposerを使用し、実行に必要なライブラリ一式をTomos通常配布ZIPおよびTomos Update ZIPへ同梱する。

本実装前に以下を確定する。

- 配置ディレクトリ
- autoloadの読込方法
- `composer.lock` の管理
- 配布必須ファイル一覧への追加
- 通常配布ZIP、Update ZIP双方での欠落検査
- Update適用後のライブラリ整合性確認

`composer.phar` は配布物へ含めない。

## 7. RP IDとOrigin

RP IDは現在アクセス中のTomosサイトのホスト名から安全に決定する。

サブディレクトリはRP IDへ含めない。

例:

- URL: `https://example.com/tomos/post/`
- RP ID: `example.com`

Origin検証ではscheme、host、portを一致させる。

ホスト名の移行、`www` 有無、サブドメイン変更は既存パスキーが利用できなくなる可能性があるため、Tomosが自動的に別RP IDへ読み替えない。

Hostヘッダーを無条件に信頼せず、TomosのサイトURL設定との整合を確認してRP ID/Originを決定する。

## 8. credential保存

パスキー資格情報は `cache/` に保存しない。

Tomos Update、キャッシュ削除、HTML再生成で消えない永続領域へ保存する。

候補:

`storage/security/passkeys/`

1 credential 1 JSONを基本とし、credential IDそのものをファイル名に直接使用せず、安全なハッシュ名を使用する。

保存項目の最小候補:

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

ファイルはWebから直接取得できないこと。保存時は一時ファイルからのatomic rename等を用い、不完全JSONを残さない。

## 9. challenge管理

登録・認証challengeはPHPセッションへ保存し、短時間のみ有効とする。

challengeは一度使用したら破棄する。

登録challengeと認証challengeを混用しない。

期限切れ、セッション不一致、challenge不一致は認証失敗とする。

## 10. パスキー登録

登録は管理用合言葉または既存の十分な再認証を通過した利用者だけに許可する。

初回実装では、管理用合言葉による再認証を必須とする。

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

採用ライブラリの挙動とWebAuthn仕様を確認し、clone detectionに関する扱いを実装時に明文化する。

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

パスキー削除は重要操作として再認証を要求する。

初回実装では管理用合言葉による再認証を必須とする。

最後のパスキーを削除しても管理用合言葉認証は残るため、削除自体は禁止しない。

削除後は対応credentialでの認証を直ちに拒否する。

## 16. 管理用合言葉の再設定

登録済みパスキーでWebAuthn認証に成功した利用者は、新しい管理用合言葉を設定できる。

現在の管理用合言葉を表示・復元する機能は設けない。

再設定手順:

1. パスキーによる再認証
2. 短時間有効な再設定許可状態をセッションへ保存
3. 新しい管理用合言葉を2回入力
4. 既存の合言葉設定方式で新しいhashを保存
5. 既存の記憶認証トークンをすべて失効
6. 現在のPHPセッションを新しい認証済み状態として再確立
7. CSRFトークンを再発行

旧合言葉では以後認証できないこと。

## 17. 記憶認証との関係

既存の `PostAuthRememberToken` は維持する。

パスキー認証成功後に記憶認証を発行するかはUI仕様で明示的に決める。

初回実装では、パスキー自体が再認証を容易にするため、パスキー認証成功だけを理由に自動で30日記憶トークンを発行しない。

既存の合言葉再設定時と同様、管理用合言葉を再設定した場合は全記憶認証トークンを失効する。

## 18. レート制限

管理用合言葉の既存失敗制限は変更しない。

パスキー登録・認証endpointには、過剰なchallenge発行や連続失敗による負荷を抑える軽量な制限を設ける。

パスキー失敗を管理用合言葉失敗回数へ加算しない。

## 19. CSRF

パスキー登録、削除、名称変更、合言葉再設定開始・確定は既存のCSRF保護を適用する。

WebAuthn challengeだけをCSRF対策の代替にしない。

## 20. UI

既存のTomos Post認証画面を大きく変更しない。

パスキー利用可能かつ登録済みの場合:

```text
[パスキーで開く]

または

管理用合言葉
[認証]
```

パスキー未登録の場合は管理用合言葉認証を従来どおり表示する。

パスキー利用条件を満たさない環境では、通常利用時に技術的な警告を常時表示しない。パスキー管理画面でのみ利用不可理由を確認できるようにする。

## 21. 非対応・障害時フォールバック

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

## 22. Tomos Update

パスキーcredentialは利用者データとして扱い、Tomos Updateの更新対象へ含めない。

Updateでライブラリ・PHP実装を更新しても、登録済みパスキーを維持する。

通常配布・Update生成では必要なライブラリファイルの存在を検査する。

Update前後で以下を回帰確認する。

- 登録済みパスキー数が変わらない
- 既存credentialで認証できる
- 管理用合言葉で認証できる
- 記憶認証が仕様どおり維持される

## 23. バックアップ

パスキーcredential保存領域はTomosのバックアップ対象として明記する。

ただしcredentialファイル単体を別ドメインへ復元してもRP ID/Originが一致しなければ利用できない。

## 24. ログ

認証ログへ秘密情報を保存しない。

記録する場合の候補:

- 成功/失敗
- 処理種別（register/authenticate/delete/reset-password）
- credentialの不可逆な識別用ハッシュ
- 時刻
- エラー分類

credential IDそのもの、challenge、公開鍵全文、clientDataJSON、署名値は通常ログへ残さない。

## 25. 実装単位

本実装は少なくとも以下へ責務分離する。

- 環境判定
- WebAuthnライブラリadapter
- credential repository
- challenge/session管理
- パスキー登録
- パスキー認証
- パスキー管理
- 合言葉再設定authorization

`post/index.php` にWebAuthn処理を集中させない。

## 26. 初回実装の受入条件

- 現行の管理用合言葉認証が変更なく利用できる
- PHP 7.4ではパスキー機能が無効でもTomos Postが正常動作する
- PHP 8以上、HTTPS、必要拡張ありの環境でパスキー機能が利用できる
- 管理用合言葉で再認証後、パスキーを追加できる
- 複数パスキーを登録できる
- Mac Chromeで登録・認証できる
- Mac Safariで登録・認証できる
- iPhone Safariで登録・認証できる
- パスキー認証後、既存Tomos Postへ認証済み状態で入れる
- パスキー一覧、名称、登録日時、最終利用日時を確認できる
- 個別パスキーを削除できる
- パスキー認証後、管理用合言葉を再設定できる
- 再設定後、旧合言葉では認証できない
- 合言葉再設定時に既存の記憶認証トークンがすべて失効する
- サブディレクトリ設置で動作する
- Tomos Update後も登録済みパスキーが維持される
- パスキー機能の障害時も管理用合言葉認証を利用できる

## 27. 本実装対象外

初回リリースでは次を対象外とする。

- 管理用合言葉の廃止
- パスキーのみでの初期setup
- パスキーのみでの完全passwordless運用の強制
- クラウド上のTomosアカウント
- Tomos公式サイトを介した中央認証
- credential同期機能のTomos独自実装
- Authenticator attestationによる端末機種判定

## 28. 実装順

1. 環境判定とライブラリ配布方式を確定
2. credential repositoryとchallenge管理
3. 複数パスキー登録
4. パスキー認証を既存セッションへ接続
5. パスキー管理画面
6. パスキーによる管理用合言葉再設定
7. 非対応環境フォールバック確認
8. 通常配布・Tomos Update対応
9. Mac Chrome / Mac Safari / iPhone Safari実機回帰
10. 開発環境で最終受入確認
