# 1ファイルインストーラー代表実サーバー検証

## 準備

本番サイトでは実行しない。空の検証directoryと、公式サイトmirrorに置いた検証用versioned資産を使用する。検証者がPHPコードを編集したり、URL queryへ失敗パラメータを入力したりしない手順にする。

upload対象は、候補から分離して作成したstandalone `install.php` 1ファイルである。開始URLは次の形式とする。

```text
https://<検証host>/<検証path>/install.php
```

## 正常系

1. HTTPSでURLを開き、環境診断が利用可能と表示されることを確認する。
2. 「この場所に設置」を選び、インストールする。
3. 完了画面から`./setup/`が開き、Tomos初期設定画面へ到達することを確認する。
4. 検証directoryへ`install.php`が残った場合、再アクセスして使用済み表示、再実行不可、Tomos利用可能を確認する。
5. 初期化は検証directory全体を検証用に作り直して行う。既存本番directoryへ上書きしない。

## 新しいフォルダ

1. 初期化後、「新しいフォルダに設置」を選ぶ。
2. `blog`など半角英数字の子directory名を入力する。
3. 完了画面から`./blog/setup/`へ進めることを確認する。
4. `blog/`が完成状態で存在し、installer root直下へTomos本体が配置されていないことを確認する。

## 最低限の失敗・復旧

- 既存`index.php`を先に置いてAを実行し、開始前に中止されることを確認する。
- 既存`blog/`を先に作ってBを実行し、中止されることを確認する。
- Phase 3で用意した検証環境のjournal recovery手順を1ケースだけ実行し、既存物を削除せず再実行可能になることを確認する。
- self-deleteが失敗する環境では、disabled marker、使用済み表示、Tomos利用可能、手動削除案内を確認する。

## 記録

| 項目 | 結果 | 備考 |
|---|---|---|
| HTTPS / session / CSRF | 未実施 | |
| 環境診断 | 未実施 | |
| A方式 | 未実施 | |
| A方式 `setup/`遷移 | 未実施 | |
| B方式 rename | 未実施 | |
| B方式 `blog/setup/`遷移 | 未実施 | |
| journal recovery | 未実施 | |
| disabled marker / 再実行防止 | 未実施 | |
| self-delete | 未実施 | |
| timeout / permission | 未実施 | |

実サーバー確認なしに正式Release Goとは判定しない。
