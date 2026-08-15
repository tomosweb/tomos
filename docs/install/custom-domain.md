# 既存Tomosを独自ドメイン直下で公開する

既存のTomosをサブディレクトリで運用している場合でも、Tomos一式を別の場所へ複製せず、独自ドメインのWEB公開フォルダーをそのTomosディレクトリへ直接向けることで、独自ドメイン直下から公開できます。

## 前提

たとえば、既存Tomosが次の場所にあるとします。

```text
~/www/example/user/example/
```

既存URLは次のような構成です。

```text
https://example.org/user/example/
```

これを次の独自ドメイン直下で公開します。

```text
https://example.com/
```

## 基本構成

サーバー側で `example.com` のWEB公開フォルダーを、既存Tomosのディレクトリへ直接指定します。

```text
example.com
  ↓
~/www/example/user/example/
  ↓
Tomos
```

この方法では、Tomos本体、テーマ、content、cacheなどを別ディレクトリへ複製する必要はありません。

## config.php

独自ドメイン直下を正式な公開URLにする場合、`site` 設定を次のようにします。

```php
'site' => array (
    'name' => 'Example Site',
    'description' => 'Example description',
    'url' => 'https://example.com',
    'base_path' => '',
    'public_base_path' => '',
    'language' => 'ja',
    'timezone' => 'Asia/Tokyo',
),
```

重要なのは次の3点です。

- `url`: 独自ドメインの正式URLを指定する
- `base_path`: 独自ドメイン直下で公開するため空文字にする
- `public_base_path`: 通常構成では空文字にする

既存URLが `/user/example/` だった場合でも、独自ドメインのWEB公開フォルダー自体が既存Tomosディレクトリを指しているなら、独自ドメイン側ではTomosはドメイン直下に設置されている扱いになります。そのため `base_path` に旧URLの `/user/example` を残しません。

`base_path` を旧サブディレクトリのままにすると、ルーターが独自ドメイン直下の `/` を期待どおりに解決できない場合があります。また、CSSや画像などのテーマ資産URLにも旧パスが付与され、表示崩れの原因になります。

## 移行手順

1. 独自ドメインをWebサーバーへ追加する
2. 独自ドメインのWEB公開フォルダーを既存Tomosのディレクトリへ指定する
3. DNSをWebサーバーへ向ける
4. HTTPS証明書を発行する
5. `config.php` の `site.url`、`base_path`、`public_base_path` を独自ドメイン用に変更する
6. 独自ドメイン直下でトップページ、記事、画像、テーマCSSが正常表示されることを確認する
7. Search、Tags、RSS、sitemapなど利用中の機能を確認する
8. 必要に応じて旧URLから独自ドメインへ301リダイレクトする

## Cloudflareを利用する場合

CloudflareをDNS管理とリバースプロキシに使う場合、オリジンサーバー側でLet's Encryptなどの証明書を発行する際には、ホスティング事業者の証明書発行条件に従ってください。

証明書発行時にCloudflareプロキシを経由するとホスティング事業者側の自動確認が成立しない構成では、一時的に対象レコードを `DNS only` にし、証明書発行後に `Proxied` へ戻します。

Cloudflareとオリジンサーバーの双方でHTTPSを有効にした構成では、Cloudflare側のSSL/TLSモードはオリジン証明書を検証する `Full (strict)` を推奨します。

## wwwの扱い

`www.example.com` も利用する場合は、どちらを正規URLにするか決めます。

独自ドメインのルートを正規URLにする例:

```text
https://www.example.com/ → 301 → https://example.com/
```

検索エンジンや共有URLの分散を避けるため、正規URLは1つに統一してください。

## キャッシュ

設定変更後に旧パスを含む表示が残る場合は、TomosのHTMLキャッシュを確認してください。

`cache/html/` のキャッシュを削除して再生成すると、旧 `base_path` を前提に生成されたHTMLを解消できます。

## セキュリティ上の注意

実運用中の `config.php` をそのままGitリポジトリへ登録しないでください。

環境によっては、次のような秘密情報が含まれます。

- `post_password_hash`
- `rate_limit_salt`
- `inbox_api_token_hash`

ドキュメントやIssueへ設定例を残す場合は、秘密情報を削除またはダミー値へ置き換えてください。

## 実機確認例

2026-08-15に、次の構成で既存Tomosを独自ドメイン直下へ切り替え、正常表示を確認しました。

- DNS / Proxy: Cloudflare
- Hosting: さくらのレンタルサーバ
- 既存Tomos: サブディレクトリ配置
- 独自ドメインのWEB公開フォルダー: 既存Tomosディレクトリを直接指定
- HTTPS: オリジンサーバー側で証明書発行
- Tomos本体の複製: なし
- Tomos Coreの改修: なし

この確認から、Tomosはサーバー側の公開フォルダー設定と `config.php` の公開URL設定を適切に組み合わせることで、既存配置のまま独自ドメイン直下で運用できます。
