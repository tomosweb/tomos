# GitHub Publish API v1 契約

Status: Draft for Issue #97 review  
Scope: Tomos Workspace / Tomos Publisher / Tomos Write から共通利用する GitHub 投稿基盤

## 1. 目的

GitHub App を使う投稿経路を Workspace 固有実装から切り離し、Tomos Publisher と Tomos Write でも同じ認証・Repository・Publish 契約を利用できるようにする。

次を原則とする。

- GitHub App private key は Tomos サーバー外へ出さない。
- GitHub user access token / refresh token / PAT を Tomos サーバーへ保存しない。
- installation access token は要求時に Repository 単位で発行し、永続化しない。
- 外部クライアントへ GitHub token を渡さない。
- Markdown 本文を Tomos サーバーの恒久DBへ保存しない。
- GitHub REST の tree / commit / ref 更新処理を各クライアントへ重複実装しない。
- GitHub 版と既存 Core 版の投稿経路は併存させる。

## 2. 現行実装との関係

### Workspace

現行 Workspace は `/publish-api/github/` の Cookie 認証を使い、クライアント側で以下を実行している。

1. branch HEAD を取得
2. base tree を取得
3. 画像 blob を作成
4. 新しい tree を作成
5. commit を作成
6. branch ref を fast-forward 更新

rename / move と不要画像削除も同じ tree に含め、1 commit で反映する。

v1 共通契約では、この GitHub Git Database API 操作を共通 API 側へ移す。Workspace は当面現行 proxy 経路を維持できるが、最終的には高水準 Publish API へ切り替える。

### Tomos Publisher

現行 Publisher は Core 版 Tomos に対し、

- Tomos URL
- 投稿用トークン
- Markdown
- 最大5点・1点10MBまでの画像

を HTTPS 送信する。画像ありの場合は start → chunk upload → finalize を行う。

GitHub 版追加後もこの Core 版設定・送信は維持する。GitHub 版は別の投稿先として選択可能にする。

### Tomos Write

現行 Write は Tomos Post へブラウザ handoff を行い、認証情報そのものは Write へ渡さない。

GitHub 版でも同じ原則を維持し、GitHub token を Write に渡さない。同一 origin のブラウザ利用では Cookie 認証を優先する。

## 3. 認証モード

共通 API は2種類の認証モードを持つ。

### A. Browser Cookie

対象:

- Tomos Workspace
- Tomos Write の公式サイト版

既存の HttpOnly Cookie `tomos_github_installation` を利用する。

- SameSite=Lax
- Secure
- HttpOnly
- Path=/
- 有効期限は現行と同じ180日を上限とする

書き込み系 POST は `X-Tomos-GitHub-Request: 1` を必須とする。

### B. External Client Grant

対象:

- Tomos Publisher（Obsidian plugin）
- 将来のデスクトップ外部クライアント

GitHub の token ではなく、Tomos が署名した Repository 限定の publish grant をクライアントへ渡す。

grant payload の最小項目:

```json
{
  "v": 1,
  "client": "publisher",
  "installation_id": 123,
  "repository_id": 456,
  "repository": "owner/repo",
  "branch": "main",
  "content_root": "content",
  "issued_at": 0,
  "expires_at": 0
}
```

- HMAC-SHA256 署名を用いる。
- GitHub credential は含めない。
- publish grant は1 Repository に固定する。
- 有効期限は最大180日。
- API 利用時は毎回 GitHub App installation と Repository access を再確認する。
- App uninstall / Repository access 解除後は grant が残っていても利用不可とする。
- クライアント側の「切断」は grant のローカル削除とする。
- v1 では個別 grant のサーバー側恒久 revocation DB は持たない。
- `connection_secret` のローテーションで全 grant を無効化できる。

## 4. External Client handoff

Publisher では PKCE と一時コードを使う。

### 4.1 開始

クライアントは以下を生成する。

- `state`: 128bit 以上の乱数
- `code_verifier`
- `code_challenge = BASE64URL(SHA256(code_verifier))`

ブラウザで次を開く。

```text
GET /publish-api/github/connect/start.php
  ?client=publisher
  &state=...
  &code_challenge=...
```

`client` はサーバー側 allowlist で固定し、任意 return URL は受け取らない。

Publisher の戻り先は Tomos 側で固定する。

```text
obsidian://tomos-publisher/github-connect
```

実装時は Obsidian Desktop / Mobile の Human Gate を行い、custom URI が利用できない環境向けに一時コードの手入力 fallback を用意できる。

### 4.2 GitHub App 接続

ブラウザに有効な接続 Cookie があれば既存 installation を検証して利用する。

未接続なら現行 GitHub App installation flow を実行する。

GitHub setup 完了後、Tomos サーバーは短時間だけ有効な one-time handoff code を発行する。

### 4.3 一時コード

handoff code は以下に紐づく。

- client
- installation_id
- code_challenge
- 発行時刻
- 有効期限
- random nonce

TTL は5分以内とする。

使い捨て保証のため、private temporary storage に nonce hash と expiry のみ保持し、exchange 成功時に consumed とする。これはユーザーDBではなく短命な replay 防止情報とする。期限切れデータは削除する。

### 4.4 Exchange

```text
POST /publish-api/github/connect/exchange.php
Content-Type: application/json
```

```json
{
  "client": "publisher",
  "code": "...",
  "code_verifier": "...",
  "state": "..."
}
```

検証:

- code 未使用
- TTL 内
- client 一致
- state 一致
- PKCE challenge 一致
- installation が現在も有効

成功時は Repository 一覧取得だけに使える短命な discovery grant を返す。

discovery grant は10分以内とする。

### 4.5 Repository bind

クライアントは Repository 一覧から1件を選択し、

```text
POST /publish-api/github/connect/bind.php
Authorization: Bearer <discovery-grant>
```

```json
{
  "repository_id": 456,
  "branch": "main",
  "content_root": "content"
}
```

を送る。

サーバーは installation がその Repository を現在も利用可能であることを確認し、Repository 固定 publish grant を返す。

## 5. Repository API

### Status

```text
GET /publish-api/github/status.php
```

認証:

- Browser Cookie
- または External Client Grant

### Repository list

```text
GET /publish-api/github/repositories.php
```

認証:

- Browser Cookie
- または discovery grant

返却項目:

- id
- owner
- name
- full_name
- default_branch
- private

External Client の publish grant は Repository 固定のため、通常は一覧再取得不要。

## 6. Publish API

GitHub の Git Database API をクライアントへ公開せず、高水準 Publish API を定義する。

### 6.1 Markdown のみ

```text
POST /publish-api/github/publish.php
Authorization: Bearer <publish-grant>
Content-Type: application/json
```

Browser Cookie の場合は Authorization を省略し、`X-Tomos-GitHub-Request: 1` を付ける。

Request:

```json
{
  "request_id": "publisher-...",
  "repository": {
    "id": 456,
    "owner": "owner",
    "name": "repo",
    "branch": "main",
    "content_root": "content"
  },
  "document": {
    "filename": "entry.md",
    "folder": "diary",
    "content": "---\n...\n---\n\n本文",
    "state": "published"
  },
  "previous": {
    "content_path": "content/old/entry.md",
    "asset_paths": []
  }
}
```

`previous` は新規投稿では省略可能。

### 6.2 画像あり

Publisher の現行方式を踏襲し、大きな JSON base64 payload は使わない。

1. `publish.php` へ `action=start`
2. 画像を 512 KiB 程度の chunk で送信
3. `action=finalize`
4. 必要なら `action=cancel`

start request:

```json
{
  "action": "start",
  "request_id": "publisher-...",
  "repository": {
    "id": 456,
    "owner": "owner",
    "name": "repo",
    "branch": "main",
    "content_root": "content"
  },
  "document": {
    "filename": "entry.md",
    "folder": "diary",
    "content": "---\n...\n---\n\n![photo](files/article-.../tms-....jpg)",
    "state": "published"
  },
  "images": [
    {
      "name": "tms-0123456789abcdef.jpg",
      "mime_type": "image/jpeg",
      "size": 123456,
      "sha256": "..."
    }
  ],
  "previous": {
    "content_path": "content/old/entry.md",
    "asset_paths": [
      "content/old/files/article-.../tms-old.jpg"
    ]
  }
}
```

chunk:

```text
POST /publish-api/github/publish-image.php
Authorization: Bearer <publish-grant>
X-Tomos-Upload-Id: ...
X-Tomos-Image-Name: ...
X-Tomos-Chunk-Index: 0
X-Tomos-Chunk-Count: 2
X-Tomos-Total-Size: ...
Content-Type: application/octet-stream
```

finalize:

```json
{
  "action": "finalize",
  "upload_id": "..."
}
```

cancel:

```json
{
  "action": "cancel",
  "upload_id": "..."
}
```

temporary upload は private storage に置き、15分程度で期限切れにする。finalize / cancel 後は削除する。

## 7. GitHub 上の配置規則

### Markdown

```text
<content_root>/<folder>/<filename>.md
```

- `content_root` 既定値: `content`
- folder は空欄可
- `..`、空 segment、NUL、絶対パスは禁止
- filename から `.md` / `.markdown` を除いた後、`.md` を付ける

### 画像

Workspace の成立済み semantics を v1 の基準とする。

```text
<content_root>/<folder>/files/<article-key>/<asset-name>
```

```text
article-key = "article-" + first12hex(SHA256(original filename including extension))
asset-name  = "tms-" + first16hex(SHA256(final image bytes)) + "." + normalized-extension
```

対応形式:

- jpg / jpeg
- png
- gif
- webp

上限:

- 5点
- 1点10MB

クライアントはローカル画像を解決し、送信用 Markdown の参照を上記 relative path に書き換える。元 Markdown / Vault ファイル自体は変更しない。

画像最適化を行う場合はクライアント側で final bytes を確定してから SHA-256 を計算する。

## 8. create / update / rename / move / cleanup

1回の Publish は1回の Git commit とする。

サーバー側処理:

1. grant / Cookie と Repository を検証
2. branch HEAD commit を取得
3. base tree を取得
4. 新画像 blob を作成
5. 新 Markdown と画像を含む tree を作成
6. previous content path が変更されていれば旧 Markdown を削除
7. previous asset paths のうち今回利用しないものを削除
8. 新 commit を作成
9. branch ref を `force=false` で更新

旧ファイル削除と新ファイル追加を同じ tree に含めるため、rename / move も atomic にする。

`previous.content_path` と `previous.asset_paths` は、対象 content root と旧記事の asset directory 内だけを許可する。任意 Repository path の削除指定には使わせない。

## 9. 競合と再試行

branch HEAD を読んだ後に他の commit が入った場合、ref 更新で force push しない。

HTTP 409:

```json
{
  "ok": false,
  "request_id": "publisher-...",
  "error": {
    "code": "branch_conflict",
    "message": "GitHub上のBranchが更新されています。もう一度公開してください。",
    "retryable": true
  }
}
```

クライアントは最新 HEAD を前提に Publish 全体を再実行する。

## 10. 成功 Response

```json
{
  "ok": true,
  "request_id": "publisher-...",
  "state": "published",
  "commit_sha": "...",
  "content_path": "content/diary/entry.md",
  "asset_paths": [
    "content/diary/files/article-.../tms-....jpg"
  ],
  "repository": "owner/repo",
  "branch": "main"
}
```

GitHub Actions / Pages の deploy 完了は本 API の成功条件に含めない。v1 の成功は Repository への commit と ref 更新完了までとする。

## 11. Error 契約

基本形:

```json
{
  "ok": false,
  "request_id": "...",
  "error": {
    "code": "invalid_request",
    "message": "...",
    "retryable": false
  }
}
```

主な code:

- `invalid_request` 400
- `invalid_path` 400
- `invalid_image` 400
- `auth_required` 401
- `grant_expired` 401
- `client_not_allowed` 403
- `repository_not_allowed` 403
- `repository_not_found` 404
- `branch_not_found` 404
- `branch_conflict` 409
- `payload_too_large` 413
- `github_error` 502
- `not_configured` 503

GitHub の raw error payload や credential をそのまま返さない。

## 12. Proxy 境界

現行 `proxy.php` は Workspace 互換のため当面維持する。

ただし新規クライアントには公開しない。

- Publisher: 高水準 Publish API のみ
- Write: 高水準 Publish API のみ
- Workspace: 移行完了までは proxy 利用可

任意 GitHub REST API proxy には拡張しない。

Workspace が高水準 API へ移行し、正式リリース後の互換要件がなくなった時点で `proxy.php` と `/workspace-api/github/` の廃止可否を判断する。

## 13. クライアント責務

### 共通

- ローカル Markdown を読む
- ローカル画像を解決する
- 送信用コピーだけを書き換える
- request_id を生成する
- API 成功後に content_path / asset_paths を次回更新用に保持する
- GitHub credential は扱わない

### Publisher 固有

- Obsidian wiki image syntax を解決する
- Core 版 Tomos URL + 投稿用トークン経路をそのまま維持する
- 投稿先として Core / GitHub を選択できるようにする
- GitHub の publish grant を plugin settings に保存する

### Write 固有

- 既存 Tomos Post handoff を維持する
- 公式サイト同一 origin では Browser Cookie を利用する
- GitHub token / grant を URL query や Markdown に埋め込まない

## 14. Workspace互換表

| 現行 Workspace | 共通 API v1 |
| --- | --- |
| GitHub App Cookie | Browser Cookie |
| Repository一覧 | `repositories.php` |
| branch接続テスト | status / Repository検証 |
| `contentRoot/folder/file.md` | 同一 |
| `files/article-*/tms-*` | 同一 |
| 画像5点・10MB | 同一 |
| blob / tree / commit / ref をJS実装 | サーバー側 Publish API |
| rename / move | previous + atomic tree |
| 不要画像削除 | previous.asset_paths |
| force=false ref update | 同一 |
| conflict時再実行 | HTTP 409 `branch_conflict` |

## 15. ログ

恒久的なユーザー行動DBは作らない。

サーバーログに残してよいもの:

- timestamp
- request_id
- client id
- Repository full name
- branch
- result code
- commit SHA（成功時）

残さないもの:

- GitHub App private key
- installation access token
- user access token
- PAT
- publish grant 全文
- Markdown 本文
- 画像本文
- code_verifier

handoff の replay 防止情報と publish upload staging は TTL 付き temporary data とし、期限後に削除する。

## 16. 実装順序

1. この契約をレビュー・確定
2. `tomos-official-site` に external client handoff / grant / high-level Publish API を実装
3. Publisher を最初の external client として接続
4. Publisher Core版 / GitHub版の両方を Human Gate
5. Workspace を high-level Publish API へ移行
6. Write を同じ契約へ接続
7. 互換入口の廃止条件を再評価

## 17. 参照する現行一次実装

- Workspace GitHub publisher: `tomosweb/tomos-workspace/src/publishers/githubPublisher.ts`
- Workspace GitHub auth: `tomosweb/tomos-workspace/src/githubAuth.ts`
- 共通 GitHub App API: `tomosweb/tomos-official-site/official-site/publish-api/github/`
- Publisher: `tomosweb/tomos-obsidian/main.ts`
- Core Publisher API: `tomosweb/tomos/core/PostInboxApi.php`
- Write handoff: `tomosweb/tomos-write/docs/handoff-protocol.md`
