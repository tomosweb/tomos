# テーマ確認チェックリスト

テーマを作ったあと、setupで選ぶ前に確認するためのチェックリストです。

## 基本

- [ ] テーマフォルダを `themes/` 配下に置いた
- [ ] フォルダ名は英数字・ハイフン・アンダースコアのみ
- [ ] `theme.json` がある
- [ ] `theme.json` の `name` とフォルダ名が一致している
- [ ] `display_name` がある
- [ ] `version` がある
- [ ] `description` がある

## ファイル

- [ ] `templates/layout.html` がある
- [ ] トップ専用表示が必要な場合は `templates/home.html` がある
- [ ] `templates/page.html` がある
- [ ] `templates/list.html` がある
- [ ] `assets/style.css` がある
- [ ] PHPファイルが含まれていない
- [ ] 不要な巨大画像や動画を含めていない
- [ ] favicon / apple-touch-icon / OGP画像を入れる場合、推奨パスに置いた

## 表示

- [ ] トップページが表示される
- [ ] 下層ページが表示される
- [ ] スマホで読める
- [ ] ナビゲーションが崩れていない
- [ ] パンくずが読める
- [ ] タグページが読める
- [ ] 検索ページが読める
- [ ] 画像が本文幅からはみ出さない

## 安全

- [ ] `<?php` や `<?=` を使っていない
- [ ] `.php`, `.phtml`, `.phar`, `.php5`, `.php7` を含めていない
- [ ] `javascript:` を使っていない
- [ ] `data:text/html` を使っていない
- [ ] 外部scriptを読み込んでいない
- [ ] `onerror`, `onload`, `onclick` などのイベント属性を使っていない
- [ ] Tomos本体の `core/`, `setup/`, `config.php` を変更していない

## setupでの確認

- [ ] setup画面にテーマが表示される
- [ ] テーマが選択可能になっている
- [ ] 警告がある場合、内容を確認した
- [ ] 無効理由が出る場合、修正してから再確認した

## 設置後のテーマ切替確認

- [ ] `/post/` のサイト設定からテーマ切り替えに進める
- [ ] 未認証で `/post/theme/` にアクセスすると `/post/` へ戻る
- [ ] 有効テーマだけ選択できる
- [ ] 無効テーマは選択できない
- [ ] `theme.name` だけが変更される
- [ ] テーマ変更後にHTMLキャッシュが削除される

## サンプルテーマ確認

- [ ] `tomos-minimal` がsetupで選択できる
- [ ] `tomos-journal` がsetupで選択できる
- [ ] `tomos-dark` がsetupで選択できる
- [ ] `tomos-journal` でトップページが表示される
- [ ] `tomos-journal` で下層ページが表示される
- [ ] `tomos-journal` でスマホ表示が崩れない
- [ ] `tomos-dark` でトップページが表示される
- [ ] `tomos-dark` で下層ページが表示される
- [ ] `tomos-dark` でスマホ表示が崩れない
