# Tomos Lab sample site

このディレクトリは開発・制作・QA用の基準サイトです。Theme ZIPには含めません。

Tomosサイトへ適用するときは、`theme-settings.php`、`theme-assets/`、`content/`をサイトルートへ配置し、`package/`から作成した`tomos-lab` Theme ZIPをTomos Postで追加します。

サンプル文言・画像は公開用素材ではありません。実案件では研究室固有の内容へ差し替えてください。

## Navigation Settings v1の例

`theme-settings.php`の`navigation`で、サイトの主要ナビゲーションを手動設定しています。ResearchとMembersを先頭へ並べ替え、Research / Membersの表示名を変更し、Aboutは表示名を省略してauto navigationの既存ラベルを使い、Publicationsは`hidden`にしています。`hidden`はナビゲーションから隠すだけで、`/publications/`の公開ページ自体は削除・非公開にしません。auto navigationで解決できない宛先は、labelを推測せず無視されます。
