# テーマ

テーマは、Tomosの見た目を決めるファイル一式です。

## setup時に選ぶ

初回setupでは、`themes/` フォルダにある有効なテーマだけを選択できます。

同梱テーマ:

- `tomos-minimal`
- `tomos-journal`
- `tomos-dark`
- `tomos-note`
- `tomos-blog`
- `tomos-90s`

## 設置後に切り替える

設置後のテーマ切り替えは、Tomos Post内で行います。

1. `/post/` を開く
2. サイト設定の「テーマを切り替える」へ進む
3. 管理用合言葉を入力する
4. 利用できるテーマから選ぶ
5. 確認画面で変更する

テーマ変更後は、TomosがHTMLキャッシュを削除します。公開ページを開き、見た目が切り替わっているか確認してください。

## テーマZIPを追加する

Tomos Postのテーマ画面から、テーマZIPを新しいテーマとして追加できます。

1. 「テーマZIPを追加」を開く
2. テーマZIPを選び、「アップロードして確認」を押す
3. テーマID、表示名、version、ファイル数、容量、注意事項を確認する
4. 「このテーマを追加する」を押す
5. テーマ一覧へ戻り、通常のテーマ切り替え手順で有効にする

Tomos側のZIP上限は最大10 MBです。実際にアップロードできる容量は、サーバーの `upload_max_filesize` と `post_max_size` により小さくなる場合があります。

アップロードと確定追加は別操作です。確認前のテーマは一覧に表示されず、追加後も自動では有効になりません。同じテーマIDの上書き、テーマの更新・削除、JavaScriptファイルを含むテーマZIPには対応していません。

テーマの動作に必要なファイルは次の5件です。

```text
theme.json
templates/layout.html
templates/page.html
templates/list.html
assets/style.css
```

次の3件は配布時の推奨ファイルです。個人が自分のTomos用に制作したテーマでは、含まれていなくても追加できます。

```text
preview.png
README.md
LICENSE
```

不足時は検査結果に注意として表示しますが、追加処理は続行できます。`preview.png` を含める場合は、正しいPNG画像である必要があります。

macOSのFinderで作成したZIPに含まれる次のメタデータは、自動的に無視され、テーマフォルダへ展開されません。

```text
__MACOSX/
.DS_Store
._*
```

一方、危険なパス、PHP、JavaScript、symlink、特殊ファイル、許可外ファイルは従来どおり拒否します。
