# Tomos Social Publishing / Bluesky連携 v0.1 設計案

## 1. 位置づけ

Bluesky連携は Tomos Post 専用機能としない。

Tomos の基本である Markdown 公開に対して、記事公開後に必要に応じて外部SNSへ告知する共通機能 **Social Publishing** として実装する。

```text
Tomos Post
Tomos Publisher
Markdown直接配置
その他の公開手段
        |
        v
   Markdown記事公開
        |
        v
  Article Published
        |
        v
 Social Publishing
        |
        v
  BlueskyProvider
        |
        v
      Bluesky
```

v0.1 で対応するSNSは Bluesky のみとする。将来 Threads / Mastodon 等を追加できる構造とするが、未使用Providerは実装しない。

## 2. 基本原則

1. **Tomos記事が本体**
   - Blueskyへの全文転載を目的としない。
   - BlueskyはTomos記事への入口とする。

2. **Markdownを中心にする**
   - SNS投稿の意思と投稿文は Frontmatter で指定できる。
   - Tomos Post 固有のSNS投稿編集UIには依存しない。

3. **記事公開とSNS投稿を分離する**
   - Tomos記事公開を先に完了する。
   - Bluesky投稿失敗によって記事公開を失敗・ロールバックしない。

4. **ユーザーが書いた文章を勝手に変更しない**
   - Tomosが自動生成したSNS投稿文は上限内へ自動調整してよい。
   - ユーザーが明示指定したSNS投稿文は自動で削除・要約・書き換えない。

## 3. Frontmatter仕様

### 3.1 現行Tomos Frontmatterとの互換性

Step 0調査で、現行 `FrontMatterParser` は完全なYAMLパーサーではなく、**トップレベルのscalarとlistのみ**を扱う簡易仕様であることを確認した。

そのため、当初案のようなネストした

```yaml
social:
  bluesky: true
  text: |
    ...
```

は v0.1 では採用しない。

Social PublishingのためだけにFrontmatterパーサーを大きく拡張しない。ただし、SNS投稿文の複数行記述を自然に扱うため、YAML block scalarのうち `|` のみを限定的に追加対応する。

### 3.2 自動生成で投稿

```yaml
---
title: Small Webについて
social:
  - bluesky
---
```

`social` list に `bluesky` が含まれ、`social_text` 未指定の場合、Tomosが投稿文を自動生成する。

v0.1では `social: bluesky` のscalar表記も受理可能とするが、公式ドキュメントでは将来の複数Providerを考慮しlist表記を推奨する。

### 3.3 投稿文を手動指定

1行でよい場合は通常のscalarとして記述する。

```yaml
---
title: Small Webについて
social:
  - bluesky
social_text: AIの時代だからこそ、自分の言葉を置いておく場所について考えました。
---
```

この場合、`social_text` は1行文字列として扱う。

改行を含めたい場合は `|` を使う。

```yaml
---
title: Small Webについて
social:
  - bluesky
social_text: |
  AIの時代だからこそ、
  自分の言葉を置いておく場所について考えました。
---
```

`social_text: |` の次に続く、同じインデントレベルの本文行を複数行文字列として読み取り、改行を保持する。

v0.1では完全なYAML対応には広げず、block scalarは `|` のみを最小対応する。YAMLアンカー、参照、複雑なネスト等は対象外とする。

### 3.4 投稿しない

`social` 未指定、空、または `bluesky` を含まない場合、Bluesky投稿を行わない。

## 4. 投稿文決定ルール

```text
social に bluesky がない
    |
    v
投稿しない

social に bluesky がある
    |
    +-- social_text あり -> ユーザー指定文を使用
    |
    +-- social_text なし -> Tomosが自動生成
```

## 5. 自動生成ルール

基本形:

```text
記事タイトル

本文から抽出した文章

記事URL
```

本文抽出候補から原則除外するもの:

- Markdown見出し
- 空行
- 画像記法
- コードブロック
- HTMLのみの行
- URLのみの行
- その他、通常文章として不適切な要素

最初の有効な文章段落を優先する。

記事URLを投稿本文内へ含める方式と、Blueskyの外部リンクカードで提示する方式は Step 0 で現行実装・表示仕様を確認して決定する。

## 6. Bluesky文字数超過

文字数検証は Social Publishing 側で行う。単純なbyte数ではなく、Blueskyの投稿上限仕様に沿って判定する。

### 6.1 自動生成文

上限を超える場合は Tomos 側で自動短縮する。

- URL / リンクカードに必要な余地を確保する。
- 本文抜粋を短くする。
- 必要に応じて末尾を `…` とする。
- 自動生成文はTomos自身が生成した文章なので、自動調整してよい。

### 6.2 ユーザー指定文

`social_text` が上限を超える場合:

- 勝手に短縮しない。
- Bluesky投稿のみ失敗とする。
- Tomos記事公開は成功扱いのままとする。
- Markdown本文と `social_text` は変更しない。

例:

```text
Tomos記事公開: 成功
Bluesky投稿: 失敗
理由: 投稿文が文字数上限を超えています
```

## 7. Tomos Post の役割

Tomos Post に Bluesky 投稿文専用編集機能は追加しない。

以下は Tomos Post に持たせない:

- Bluesky投稿文専用テキストエリア
- Bluesky文字数カウンター
- Blueskyプレビュー
- Bluesky API処理
- OAuth処理
- 投稿履歴管理
- Webカード生成
- 二重投稿判定

Tomos Post は従来どおり **Markdownを書く・保存する・公開する** ことを主責務とする。

SNS投稿指定は Frontmatter を通じて行う。

## 8. Tomos Publisher の役割

Tomos Publisher も Bluesky API を直接扱わない。

Publisherの責務:

1. MarkdownをTomosへ送信
2. Tomos側で記事公開
3. Social Publishing結果を受信
4. Tomos Publisher上で結果を通知

```text
Tomos Publisher
   |
Publisher
   |
TomosへMarkdown送信
   |
記事公開
   |
Social Publishing
   |
Bluesky
   |
結果をPublisherへ返す
   |
Tomos Publisher側の通知
```

Publisher側は Bluesky の文字数仕様・認証・APIエラーを独自判断しない。Tomos側から返された共通結果を表示するだけとする。

## 9. Tomos Publisher側の通知

Bluesky投稿結果は Tomos Publisher 側で通知する。

### 成功例

```text
Tomosへ公開しました。
Blueskyにも投稿しました。
```

### 投稿失敗例

```text
Tomosへの公開は完了しました。

Blueskyへの投稿に失敗しました。
投稿文が文字数上限を超えています。
```

### 未接続例

```text
Tomosへの公開は完了しました。

Blueskyへ投稿できませんでした。
Tomos側のBluesky接続設定を確認してください。
```

## 10. Markdown直接配置

Tomos Post / Tomos Publisher を使わず Markdown を直接配置する運用でも、Social Publishing を利用可能な構造とする。

直接配置からリアルタイム自動投稿するには、Tomos が「新規記事公開」を検出できる Publish Event が必要となる。

v0.1で直接配置まで実装するかは Step 0 で既存の記事検出処理を調査して決める。

設計上は Tomos Post / Tomos Publisher に依存しない。

## 11. Publish Event

Social Publishing の起点を Tomos Post の公開ボタンに置かない。

共通の `Article Published` を起点とする。

```text
Markdown
   |
Tomosが記事公開を確定
   |
Article Published
   |
Frontmatter確認
   |
social に bluesky がある ?
   |
SocialPostService
```

## 12. Social Publishing 共通結果

各呼び出し元へ共通形式で結果を返す。

基本ステータス:

```text
success
failed
skipped
```

概念例:

```text
status: failed
provider: bluesky
code: text_too_long
message: Bluesky投稿文が文字数上限を超えています
```

少なくとも以下の結果コードを想定する:

- `success`
- `skipped`
- `text_too_long`
- `not_connected`
- `authentication_failed`
- `network_error`
- `provider_error`
- `already_posted`
- `invalid_content`

外部APIの生エラーをそのままUIへ渡さず、ユーザー向けメッセージと内部エラー情報を分離する。

## 13. 呼び出し元ごとの通知

Social Publishing 自身は通知UIを持たない。結果だけ返す。

```text
Tomos Post
   -> Tomos画面上のメッセージ

Tomos Publisher
   -> Tomos Publisher側の通知

Markdown直接配置
   -> Tomos管理画面またはログ
```

Tomos Post経由でSNS投稿に失敗した場合は、記事公開成功を明示したうえでSNS投稿失敗を表示する。

Tomos Publisher経由の場合は、Tomosから返されたSocial Publishing結果をTomos Publisher側で必ず通知する。

## 14. 投稿履歴

投稿結果は Markdown へ書き戻さない。

Frontmatterは「投稿したいという著者の意思」と「任意の投稿文」だけを保持する。

実際の状態は Social Publishing 側で管理する。

概念データ:

```text
article_id
provider
post_text
remote_uri
remote_cid
status
posted_at
error_code
```

## 15. 二重投稿防止

基本キー:

```text
article_id + provider
```

成功済み投稿が存在する場合、通常の記事更新では再投稿しない。

`social` に `bluesky` が Frontmatter に残っていても、記事保存・更新のたびに投稿しない。

## 16. 初回公開と更新

### 初回公開

```text
記事公開
  |
Bluesky投稿対象なら投稿
```

### 記事更新

```text
記事更新
  |
既にBluesky投稿済み
  |
投稿しない
```

## 17. 再投稿

Frontmatter に `repost: true` のような永続的な再投稿命令は置かない。

保存のたびに意図せず投稿される危険があるため。

再投稿は将来、Tomos側の明示操作から実行する。

v0.1で再投稿UIまで提供するかは既存UIを調査して決定する。

## 18. Bluesky認証

Bluesky接続はTomos本体側で管理する。

Tomos PublisherやMarkdownファイルには認証情報を持たせない。

正式機能は OAuth を基本とする。

概念:

```text
Tomos設定
   |
Blueskyと接続
   |
認証完了
   |
SocialAccountとして保存
```

保存対象例:

```text
provider
DID
handle
OAuth session / token information
connected_at
```

秘密情報は公開コンテンツ領域から分離する。

## 19. Provider設計

v0.1:

```text
SocialPostService
        |
        +-- BlueskyProvider
```

将来:

```text
SocialPostService
        |
        +-- BlueskyProvider
        +-- ThreadsProvider
        +-- MastodonProvider
```

ただしv0.1では将来SNS用の未使用コードは実装しない。

## 20. SocialPostService の責務

- FrontmatterからSNS投稿指定を受け取る
- 投稿文決定
- 自動生成
- 文字数検証
- 二重投稿判定
- Provider呼び出し
- 投稿履歴保存
- 共通結果返却

SNS固有API処理は Provider へ委譲する。

## 21. BlueskyProvider の責務

- Bluesky認証情報利用
- Bluesky投稿形式への変換
- Facet / link処理
- 外部Webカード処理
- API通信
- APIレスポンス解析

Tomos Post / Tomos Publisher はこれらを知らない。

## 22. データモデル

概念モデル:

```text
Article
SocialAccount
SocialPost
```

### Article

Tomos記事。SNS固有情報を極力持たない。

### SocialAccount

```text
provider
account_identifier
handle
credentials
```

### SocialPost

```text
article_id
provider
post_text
remote_id
status
posted_at
error_code
```

## 23. Webカード

Bluesky投稿ではTomos記事へのリンクカードを可能な限り付与する。

利用候補:

- 記事URL
- 記事タイトル
- 記事概要
- OGP画像

Webカード生成失敗だけを理由にBluesky投稿全体を失敗させない。可能ならテキスト + URL で継続する。

## 24. エラー時の原則

常に以下を分離する。

```text
Tomos記事公開 != SNS投稿
```

SNS投稿失敗によって記事公開状態を変更しない。

## 24.1 開発順序

実装は次の順序で進める。

1. **Tomos Core / Tomos Post**
   - Frontmatter対応
   - Social Publishing共通基盤
   - Bluesky認証
   - 投稿文生成・文字数検証
   - SocialPost履歴
   - 二重投稿防止
   - Webカード
   - Tomos Postでの成功・失敗通知

2. **Tomos Publisher**
   - Tomos側で確定したSocial Publishing結果を取得する契約を追加
   - 投稿結果をTomos Publisher側で通知
   - Bluesky固有ロジックは持たせない
   - 既存のMarkdown送信・画像送信・競合処理との後方互換性を維持

3. **Markdown直接配置**
   - v0.1では即時自動SNS投稿の実装対象外
   - 将来、明示的なpublish scanまたは差分検出方式を別途設計する

Tomos Publisher連携は、Tomos本体側のSocial Publishing仕様が安定した後に着手する。

## 25. v0.1 対象範囲

### 実装対象

- Blueskyのみ
- Frontmatterによる投稿指定
- 投稿文自動生成
- 手動投稿文指定
- 文字数検証
- 自動生成文の自動短縮
- 手動指定文超過時のSNS投稿停止
- Bluesky投稿
- 投稿履歴
- 二重投稿防止
- 共通結果オブジェクト
- Tomos Postでの結果表示
- Tomos Publisherへの結果返却
- Tomos Publisher側Notice表示
- Bluesky認証管理
- Webカード

### 原則対象外

- Threads
- Mastodon
- X
- SNS分析
- 投稿予約
- 自動再試行
- SNS側投稿削除 / 編集
- SNS側反応取得
- AI投稿文生成
- 複数SNSアカウント
- SNS用画像投稿
- SNS管理ダッシュボード

## 26. Tomos Post 肥大化防止

既存Tomos Post主要PHPへ以下を直接実装しない。

- Bluesky認証
- Bluesky API
- SNS投稿文生成処理本体
- 投稿履歴
- 二重投稿判定
- Webカード処理
- Provider固有エラー処理

Tomos Postは既存の公開責務を維持し、必要な場合に共通結果を表示する程度に留める。

## 27. Tomos Publisher 肥大化防止

Publisherにも以下を持たせない。

- Bluesky API処理
- Bluesky認証情報
- Bluesky文字数仕様
- 二重投稿判定
- 投稿履歴

PublisherはTomos APIから返された結果をNoticeとして表示する。

## 28. v0.1 受入条件

1. Markdown FrontmatterだけでBluesky投稿を指定できる
2. `social_text` 未指定なら投稿文を自動生成する
3. `social_text` は1行scalarと `|` による複数行指定の双方を使用できる
4. 自動生成文が上限超過した場合、自動調整される
5. 手動指定文が上限超過した場合、文章を変更せずSNS投稿のみ失敗する
6. Tomos記事公開はSNS投稿成否から独立している
7. Blueskyへの二重投稿が通常更新では発生しない
8. 投稿履歴をMarkdownへ書き戻さない
9. Tomos Post固有のBluesky投稿編集UIを必要としない
10. Tomos Publisherから公開した場合も同じSocial Publishing経路を通る
11. Tomos PublisherへSNS投稿結果が返却される
12. Tomos Publisher側で成功・失敗がNotice表示される
13. Bluesky認証情報をTomos Publisher側に保持しない
14. Social PublishingとBlueskyProviderがTomos Postから分離されている
15. 将来別Providerを追加可能である
16. SNS連携を使わない既存ユーザーの動作を変更しない

## 29. 実装前 Step 0

実装開始前に既存Tomosを調査する。

確認対象:

- Markdown読み込み処理
- Frontmatter解析処理
- 新規記事 / 更新記事の判定方法
- 記事IDの決定方法
- Tomos Postの公開処理
- Tomos Post主要PHPの責務と肥大化状況
- Tomos Publisherの送信 / レスポンス仕様
- 記事直接配置時の検出方法
- 現在の設定保存場所
- 非公開データ保存領域
- OGP生成処理
- 既存API構造
- 認証 / CSRF等のセキュリティ機構
- PHP最低対応バージョン
- 外部HTTP通信方式

Step 0 の調査後に以下を確定する:

- 実ファイル構成
- APIレスポンス形式
- データ保存方式
- Publish Event の実装位置
- Markdown直接配置をv0.1実装対象に含めるか
- 再投稿UIをv0.1に含めるか
- URL本文付与と外部Webカードの最終仕様

## 30. 設計上の最終原則

この機能を **「Tomos PostからBlueskyへ投稿する機能」** とは定義しない。

正しくは、

> **Tomosで公開されたMarkdown記事を、必要に応じて外部SNSへ通知するSocial Publishing基盤**

とする。

Blueskyは最初のProviderである。

Tomos Post、Tomos Publisher、Markdown直接運用は、同じSocial Publishingを利用する複数の入口として扱う。


## 31. Step 0 調査結果（2026-09-18）

### 31.1 Frontmatter

確認済み:

- `core/FrontMatterParser.php` はトップレベルscalar/listを扱う独自簡易パーサー。
- ネストしたobjectは扱わない。
- 現行実装ではYAMLの `|` block scalarを扱わない。
- 未知のトップレベルキー自体は `parse()` のmetadataに保持できる。
- `buildPageMetadata()` の正規化対象は既存の標準メタデータに限定される。

結論:

- v0.1では `social` list + `social_text` scalar / block scalar を採用する。
- Social Publishingは `parse()` のraw metadataからsocial指定を読む。
- ネストYAML対応は追加しない。
- `FrontMatterParser` へ `key: |` 形式だけを限定追加し、続くインデント行を改行保持の文字列として返す。
- `social_text: 文章` は従来どおり1行scalarとして扱う。
- `social_text` のlist表現はSNS投稿文仕様として採用しない。

### 31.2 公開処理の共通入口

確認済み:

- Tomos Postの通常投稿、下書き公開、Publisher由来の自動公開は最終的に `PostUpload::handleContent()` 系へ集約されている。
- `PostUpload` はさらに `PostPublisher` を利用してcontentへ公開する。
- `PostUploadResult` には `contentPath`、`internalUrl`、`absoluteUrl`、`operation` 等が既にある。

結論:

- Social Publishingのフック候補は `post/index.php` ではなく、**公開成功が確定するCore層**に置く。
- `post/index.php` からBluesky APIを直接呼ばない。
- 実装時に `PostPublisher` と `PostUpload` の責務境界をさらに確認し、「ファイル保存成功」と「公開成功」が確定する単一箇所へArticle Published相当の呼び出しを置く。

### 31.3 Tomos Publisher / Inbox API

確認済み:

- `post/inbox/api/index.php` -> `PostInboxApi` は、PublisherからMarkdown/画像を受信箱へ保存するAPI。
- 現在のAPIレスポンスは「受信成功」であり「公開成功」ではない。
- `PostInboxAutoPublisher` は受信済みMarkdownを後から `PostUpload::handleContent()` へ渡して公開する。
- 自動公開処理は Tomos Post 画面だけでなく `App::run()` 冒頭でもbest-effort実行される。
- したがって現状のPublisher送信HTTPリクエストだけでは、その場で公開結果やBluesky投稿結果をPublisherへ返せない。

結論:

- Tomos PublisherにBluesky結果を通知するためには、既存Inbox APIに「受信結果」と「公開/Social Publishing結果」を混同して足さない。
- v0.1実装では、Publisherから明示的に公開結果を取得できる**後方互換な結果取得契約**を追加する。
- 実装候補は以下のどちらかを比較して決定する。
  1. finalize/送信完了時に同期公開まで実行し、その結果を返す新しいopt-in action。
  2. submission/upload IDを使ったstatus endpointを追加し、Publisherが短時間pollして公開/Social Publishing結果を受け取る。
- 既存Publisherの受信契約は壊さない。
- 公開が別リクエストでbest-effort実行される現行挙動を前提に、Bluesky通知だけをPublisher側で推測してはならない。

推奨は **2. status endpoint方式**。理由は画像投稿・競合・下書き化など現行Publisherの非同期的な公開経路と整合し、受信APIを長時間の外部SNS通信でブロックしないため。

### 31.4 Markdown直接配置

確認済み:

- Tomosは `content/` 内のMarkdownを直接ページとして扱う。
- 直接配置にはTomos Post / Publisherのような明示的な「公開操作」が存在しない。
- 公開ページリクエスト時にInbox自動公開処理は走るが、contentへ直接置かれた新規Markdown専用のPublish Eventは存在しない。

結論:

- **直接配置からの自動Bluesky投稿はv0.1の即時実装対象から外す**。
- ただしFrontmatter仕様とSocial Publishing serviceは直接配置でも利用可能な形を維持する。
- 将来、content index更新時の差分検出または明示CLI/管理操作によるpublish scanを設計する。
- ページ閲覧をトリガーにSNS投稿する設計は採用しない。閲覧行為で外部投稿が発生するのは予測困難で危険なため。

### 31.5 認証情報・非公開保存

確認済み:

- 現行 `config.php` には `security.inbox_api_token_hash` 等の秘密/認証関連設定がある。
- `ConfigWriter` はロック・CAS相当の保護を持つ。
- 一方、OAuth refresh token等をconfig.phpへ直接増やすと、設定更新との責務混在と秘密情報の露出範囲拡大につながる。

結論:

- Bluesky OAuthのセッション/トークン本体は `config.php` に保存しない。
- `storage/` 配下などWeb非公開の専用SocialAccount storeを新設する方向で実装設計する。
- configには必要なら「接続済み/機能有効」など秘密ではない設定だけを保持する。
- 実際のディレクトリ保護方式は既存 `storage/inbox`、remember token等の保護実装を再利用できるか実装Stepで確認する。

### 31.6 外部HTTP通信

確認済み:

- `core/ExternalUrlHttpClient.php` はHTTPS、host allowlist、timeout、TLS検証、response size制限を持つ。
- ただし現在はGET/HEAD専用。
- Bluesky APIにはPOSTが必要。

結論:

- BlueskyProvider内で生の `curl_*` を散在させない。
- POST JSONに対応したSocial/AT Protocol用HTTP clientを分離する。
- TLS検証、timeout、response上限、redirect禁止、許可host検証の考え方は `ExternalUrlHttpClient` と揃える。
- 既存clientを無理に汎用化して既存リンクカード等へ回帰を起こすより、共有可能な最小transport抽出か専用client追加を比較する。

### 31.7 記事IDと二重投稿防止

確認済み:

- 公開結果には `contentPath` と `absoluteUrl` が存在する。
- TomosのMarkdown記事はcontent内相対パスが安定した識別子として既に多くの処理で使われている。
- 更新時は既存content pathを維持する処理がある。

結論:

- v0.1の `article_id` はまず **正規化済みcontent relative path** を基準とする。
- `article_id + provider` を投稿済み判定キーとする。
- URLやtitleだけをキーにしない。
- rename/move時の扱いは将来課題とし、v0.1では「別記事として扱われ得る」ことを既知制約として明記する。

## 32. Step 0 後のv0.1実装境界

Step 0を踏まえ、v0.1は以下に絞る。

### v0.1に含める

- Frontmatter `social` listによるBluesky指定
- `social_text` scalar / `|` block scalarによる手動文指定
- 未指定時の自動生成
- Tomos Post経由公開
- Tomos Publisher経由公開
- 共通Social Publishing service
- BlueskyProvider
- OAuth接続
- SocialAccount専用非公開store
- SocialPost履歴
- 二重投稿防止
- Tomos Postでの結果通知
- Publisher向けstatus/result契約
- Tomos Publisher側の通知
- Webカード
- 文字数検証

### v0.1から外す

- contentへ直接配置したMarkdownを自動検出して即時SNS投稿
- Threads / Mastodon / X
- AI投稿文生成
- 予約投稿
- 自動再試行
- SNS分析
- Provider固有の投稿管理ダッシュボード

## 33. Step 1へ進む条件

実装開始前に次を確定する。

1. FrontMatterParserの `|` block scalar最小対応の受入条件と回帰テストを確定する。
2. Article Published hookを `PostPublisher` / `PostUpload` のどちらに置くか.
3. Publisher status endpointのID、保持期間、レスポンスschema。
4. SocialAccount / SocialPost storeの物理配置とatomic write/lock方式。
5. Bluesky OAuthのcallback endpointとCSRF/state管理。
6. Bluesky文字数判定の正確な実装方式。
7. Webカード作成時のOGP画像取得/再利用方法。
8. 既存Publisher側の互換バージョン条件。

これらをStep 1設計として先に固定し、その後コード実装へ進む。


## 34. Phase 1 実装到達点（Tomos Post）

Tomos Post経由のSocial Publishingについて、実サーバー試験直前まで実装済み。

実装済み:

- Frontmatter `social` / `social_text`
- 1行 `social_text: テキスト`
- Tomos簡易複数行記法（`social_text:` の次行から、行末 `|` まで。インデント不要）
- 従来の `social_text: |` block scalarも互換維持
- 自動投稿文生成
- 自動生成文のみBluesky上限内へ短縮
- 手動 `social_text` は変更しない
- Social Publishing共通結果
- SocialPost履歴
- `article_id + provider` による二重投稿防止
- PostUpload公開成功後hook
- 下書き保存時はSNS投稿しない
- Tomos Postで公開結果とSNS結果を分離表示
- AT Protocol OAuth client metadata / JWKS
- handle / DID解決
- PDS / Authorization Server discovery
- PKCE / PAR / DPoP / private_key_jwt(ES256)
- OAuth callback / state検証 / issuer検証 / DID検証
- access token / refresh token / DPoP keyの非公開保存
- refresh tokenの排他更新と同時401時の二重refresh防止
- SSRF対策
- Bluesky `com.atproto.repo.createRecord` 投稿
- AT URI / CIDの履歴保存
- `app.bsky.embed.external` による記事カード
- Tomos正規化済みtitle / descriptionのカード利用
- Tomos Post設定画面からBluesky接続 / 解除
- Distribution / Installer / Update対象への組み込み
- Core regression / Installer dry-run等のCI

Phase 1完了後の未実施:

- Tomos Publisherのstatus/result API連携
- Tomos Publisher側Notice
- Markdown直接配置からの自動Social Publishing
- 外部カードthumbnail/blob upload

2026-09-19に公開HTTPS環境でPhase 1の実サーバー試験を完了し、OAuth接続、実投稿、external card、手動/自動投稿文、文字数超過、二重投稿防止、非投稿時の通知まで確認した。

## 35. 人間による実サーバー試験

### 35.1 事前条件

- 公開HTTPS環境でTomosが稼働している
- `site.url` が実際の公開HTTPS URLと一致している
- Tomos Postへ管理認証できる
- テスト用Blueskyアカウントを使用できる
- テスト記事を削除または非公開化できる

### 35.2 公開OAuth endpoint確認

ブラウザまたはHTTPクライアントで以下を確認する。

1. `/oauth-client-metadata.json`
   - HTTP 200
   - JSON
   - `client_id` がそのURL自身
   - `redirect_uris` が実Tomosのcallback URL
   - `token_endpoint_auth_method = private_key_jwt`
   - `token_endpoint_auth_signing_alg = ES256`
   - `scope` に `atproto repo:app.bsky.feed.post?action=create`
2. `/.well-known/tomos-bluesky-jwks.json`
   - OAuth接続開始後にHTTP 200
   - `keys` にP-256公開鍵
   - 秘密鍵情報が含まれない

### 35.3 Bluesky接続

Tomos Post:

`設定 -> Bluesky連携`

1. Bluesky handleを入力
2. 「Blueskyと接続」
3. Bluesky側の認可画面へ遷移
4. 認可
5. Tomos callbackへ戻る
6. 「Blueskyとの接続が完了しました。」を確認
7. 接続済みhandleが表示されることを確認

確認事項:

- BlueskyのパスワードをTomosへ入力しない
- callback URLにtoken等の秘密情報が表示されない
- Tomosログや画面にaccess token / refresh tokenが表示されない

### 35.4 自動投稿文

テストMarkdown:

```yaml
---
title: Social Publishing テスト
social:
  - bluesky
---
```

本文に通常の段落を書く。

期待結果:

- Tomos記事公開: 成功
- Bluesky投稿: 成功
- Tomos PostにBluesky成功メッセージ
- Bluesky本文はtitle + 本文抜粋を基礎に自動生成
- 記事へのexternal cardが付く
- card title / descriptionがTomos記事に対応
- SocialPost履歴にAT URI / CIDが保存される

### 35.5 手動1行投稿文

```yaml
social:
  - bluesky
social_text: 自分で指定した投稿文です。
```

期待結果:

- Bluesky本文が指定文と完全一致する
- Tomosが勝手に短縮・言い換え・URL追記をしない
- 記事URLはexternal cardとして付く

### 35.6 手動複数行投稿文

```yaml
social:
  - bluesky
social_text: |
  1行目です。
  2行目です。
```

期待結果:

- 改行が保持される
- 文面を書き換えない

### 35.7 手動文の上限超過

300 graphemeを超える `social_text` を指定する。

期待結果:

- Tomos記事公開: 成功
- Bluesky投稿: 失敗
- Tomos PostにBluesky文字数超過の警告
- 手動投稿文をTomosが短縮しない
- 記事公開をrollbackしない

### 35.8 自動生成文の上限超過

長いtitle / description相当本文で自動投稿を行う。

期待結果:

- Tomos記事公開: 成功
- Bluesky投稿: 成功
- 自動生成文のみ上限内へ短縮される
- 末尾に `…`

### 35.9 二重投稿防止

一度Bluesky投稿成功した記事を通常編集して再公開する。

期待結果:

- Tomos記事更新: 成功
- 新しいBluesky投稿は作成されない
- 既存SocialPost成功履歴をもとに `already_posted` 扱い

### 35.10 下書き

`draft: true` で保存する。

期待結果:

- 下書き保存: 成功
- Bluesky投稿は行わない

その後、下書きを公開する。

期待結果:

- 公開時に初めてBluesky投稿する

### 35.11 Bluesky未接続

Bluesky接続を解除後、`social: [bluesky]` 相当の記事を公開する。

期待結果:

- Tomos記事公開: 成功
- Bluesky投稿のみ失敗
- Tomos Postに接続設定確認の警告
- 記事公開状態は維持

### 35.12 接続解除

Tomos PostのBluesky連携画面から解除する。

期待結果:

- 接続済み表示が消える
- 保存済みOAuth account sessionが削除される
- 過去の記事・SocialPost履歴は削除しない

### 35.13 試験合格条件

以下をすべて満たしたらPhase 1を実サーバー試験合格とする。

- OAuth接続成功
- 実投稿成功
- external card表示
- 手動文保持
- 複数行保持
- 手動超過時に記事公開のみ成功
- 自動文短縮
- 二重投稿防止
- 下書き非投稿
- 未接続時も記事公開維持
- 接続解除成功
- token等の秘密情報が画面・Markdown・公開HTMLへ露出しない

2026-09-19に上記試験を合格。Phase 2としてTomos Publisherのstatus/result契約とNotice実装へ進む。


## 36. Phase 2 Tomos Publisher status/result契約

### 36.1 対象

Tomos Publisher本体:

- repository: `tomosweb/tomos-obsidian-dev`
- 現行送信先: `/post/inbox/api/`
- 既存PublisherはMarkdown/画像をInboxへ送信し、受信成功時点でNoticeを出す。
- Inboxから実際の記事公開は `PostInboxAutoPublisher` の別処理で行われる。

このため、受信HTTPレスポンスへSocial Publishing結果を混在させず、後方互換なstatus照会を追加する。

### 36.2 request_id

新Publisherは各送信ごとに一意な `request_id` を生成する。

条件:

- 16〜128文字
- ASCII英数字、`.`、`_`、`-` のみ
- Markdownのみ送信、画像付き送信の両方で使用
- Tomosはrequest_idを公開Markdownへ書き戻さない
- Inboxの非公開Publisher metadataへ関連付ける

request_id未指定の旧Publisherは従来どおり受け付ける。

### 36.3 status API

既存Inbox APIと同じURLを使う。

```text
GET /post/inbox/api/
X-Tomos-Token: <publisher token>
X-Tomos-Action: status
X-Tomos-Request-Id: <request_id>
```

status照会は既存のInbox AutoPublisherをbest-effortで1回進め、その後保存済みstatusを返す。

認証は既存Inbox API tokenと同一。

### 36.4 status schema

中間状態:

- `received`
- `receiving_images`

最終状態:

- `published`
- `already_published`
- `draft`
- `needs_attention`
- `error`

`published` の例:

```json
{
  "ok": true,
  "request_id": "publisher-...",
  "state": "published",
  "filename": "article.md",
  "message": "記事を公開しました。",
  "article_url": "https://example.com/article/",
  "social": {
    "status": "success",
    "provider": "bluesky",
    "code": "success",
    "message": "Blueskyへ投稿しました。",
    "remote_uri": "at://...",
    "remote_cid": "..."
  }
}
```

`social` は記事公開結果とは独立する。

- Social Publishing成功: `success`
- Social Publishing失敗: `failed`
- 二重投稿防止等: `skipped`
- social指定なし: `skipped / not_requested`

SNS失敗で `state: published` を失敗へ変えない。

### 36.5 Publisher Notice

Tomos Publisherはstatus APIの最終状態を確認してNoticeを出す。

代表表示:

- 記事 + Bluesky成功:
  - `Tomosへ公開し、Blueskyにも投稿しました。`
- 記事成功 + Bluesky失敗:
  - `Tomosへ公開しました。Blueskyには投稿していません。＜理由＞`
- 記事成功 + already_posted:
  - `Tomosへ公開しました。Blueskyには再投稿していません。＜理由＞`
- 下書き:
  - `Tomosへ下書きとして送信しました。`
- 要確認:
  - Tomos Postでの確認を案内する

旧Tomosがstatus schemaを返さない場合は、従来の `Tomosへ送信しました。` へフォールバックする。

### 36.6 保持場所

Publisher statusは公開領域へ置かない。

```text
storage/inbox/.publisher-status/
```

- request_idをそのままファイル名に使わずhash化する
- atomic temp write + rename
- Webから直接取得させない
- status API経由でのみ返す

### 36.7 Phase 2受入条件

- Markdownのみ送信でstatus取得
- 画像付き送信でstatus取得
- 記事公開成功をPublisherへ通知
- Bluesky成功をPublisherへ通知
- Bluesky失敗時も記事公開成功を維持
- already_postedを再投稿なしとして通知
- draftを明示
- conflict/自動公開失敗時にneeds_attention
- request_idなし旧Publisher互換
- 旧TomosへのPublisherフォールバック
- token/request_id/status storeが公開HTMLやMarkdownへ露出しない


## 37. Phase 2 実装・実サーバー試験完了（2026-09-19）

Tomos PublisherからTomosへ送信した記事について、公開結果とSocial Publishing結果をPublisherへ返すPhase 2を実装し、実サーバー + Obsidian + Blueskyの組み合わせで確認した。

### 37.1 実装済み

- Publisherが送信ごとに一意な `request_id` を生成
- Markdownのみ / 画像付き送信の双方でrequest_idを送信可能
- Inbox APIに後方互換なstatus照会を追加
- status照会時にInbox AutoPublisherをbest-effortで進行
- 公開結果を `storage/inbox/.publisher-status/` に非公開保存
- 記事公開結果とSocial Publishing結果を分離
- Publisher側で最終結果をpollし、Obsidian Noticeへ表示
- 旧Tomosがstatus schemaを返さない場合は従来Noticeへフォールバック
- request_id未指定の旧Publisher互換を維持

### 37.2 Publisher管理記事の安全な自動更新

Phase 2実サーバー試験で、Publisherから同じ記事を再送した場合に既存記事との競合として下書きへ回ることを確認した。

これに対して、Publisherから新規公開された記事の `content_path` を非公開storeへ記録し、以後その記事だけをPublisher管理記事として扱う方式を追加した。

- Publisher管理記事の再送: 既存記事を自動更新
- 更新後のSocial Publishing: 通常の二重投稿防止を適用し `already_posted`
- Publisher管理記録のない既存記事: 自動上書きせず `needs_attention`
- 一般の競合処理は変更しない
- Publisher管理情報はMarkdownへ書き戻さない

保存先:

```text
storage/inbox/.publisher-articles/
```

### 37.3 実サーバー試験結果

2026-09-19に以下を確認した。

1. **新規記事**
   - Tomos Publisherから送信成功
   - Tomos記事公開成功
   - Bluesky投稿成功
   - Publisher Notice:
     - `Tomosへ公開し、Blueskyにも投稿しました。`

2. **Publisher管理記事の更新**
   - 同じPublisher記事を修正して再送
   - Tomos記事更新成功
   - 下書きへ回らない
   - Blueskyへ再投稿しない
   - Social Publishing結果は `already_posted`
   - Publisher Noticeで再投稿なしを確認

3. **下書き**
   - `draft: true` のMarkdownをPublisherから送信
   - Tomosで下書きとして保持
   - Blueskyへ投稿しない
   - Publisher Notice:
     - `Tomosへ下書きとして送信しました。`

Phase 2の主要実利用経路は実サーバー試験合格とする。

### 37.4 Phase 3 Markdown直接配置

Markdownを `content/` へ直接配置した場合のSocial Publishingは技術的には可能だが、直接配置には明示的なArticle Publishedイベントがない。

候補となる差分スキャン / CLI / 管理操作等は、Tomosのシンプルさに対して追加複雑性が大きい。

そのためv0.1では **Phase 3を見送る**。

- ページ閲覧をSNS投稿トリガーにはしない
- 自動スキャンもv0.1では追加しない
- 将来、実需要が生じた場合に別設計として再検討する

### 37.5 GitHub Actions / Local Release Gate

2026-09-19時点で月間GitHub Actions利用枠の制約により、Phase 2終盤のworkflowを通常どおり利用できない状態となった。

これを契機に、GitHub ActionsをReleaseの唯一の必須Gateとせず、既存の `tools/` / `tests/` を再利用する正式なActions-independent Local Release Gateを追加した。

- `tools/release-local.sh`
- `docs/release/local-release.md`
- Core regression / Distribution / Test Update / Installer candidate / checksum / PHP compatibilityをローカルで再現する
- 本番署名private keyは従来どおりrepository外で管理する
- Local Gate PASSだけで `PUBLIC RELEASE COMPLETE` にはしない
- production smoke / public artifact再取得 / Browser Update / Installer smoke等のHuman/Public Artifact Gateを必須とする
- GitHub Actionsは利用可能時の独立した追加確認として残す
- Actions quotaのリセットを待つこと自体をRelease条件にはしない

### 37.6 Phase 2完了判定

機能実装と実サーバー試験は完了。

リリース前のUI確認でBluesky連携設定画面をTomos Post共通UIへ統一し、実画面確認も完了した。

残作業は最終HEADでのLocal Release Gate、正式artifact生成・公開、Human/Public Artifact Gate、およびmergeである。

対象PR:

- Tomos Core: PR #252
- Tomos Publisher: PR #8
