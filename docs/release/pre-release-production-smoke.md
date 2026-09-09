# Pre-Release Production Smoke Gate

## 目的

正式Release前に、Release予定コードがTomos公式サイトの実production環境で正常に動作することを、更新対象ファイルだけの一時差し替えで確認する。

このGateは正式Update経路のAcceptance Gateを置き換えない。目的は、Apache/PHP/shared hosting/browser等の実環境差による不具合を、正式artifact作成・公開前に検出することである。

## 原則

- 差し替えるのはReleaseで更新対象となるファイルだけとする。
- 差し替え対象を事前に固定し、開始前に記録する。
- バックアップは差し替え対象の現行ファイルだけでよい。サイト全体バックアップは必須としない。
- `config.php`、`content/`、uploads、custom Theme、サイト固有設定、運用データは差し替え対象に含めない。
- 実環境確認後は必ず元ファイルへ戻す。
- 復元後に公式サイトが元の状態で正常に動作することを確認する。
- 人間のブラウザ操作が必要な項目はHuman verificationとして明示し、未確認のままPASS扱いにしない。

## 標準工程

1. Release予定差分からproduction差し替え対象ファイルを確定する。
2. 対象ファイル一覧、検証開始時刻、検証目的を記録する。
3. production上の対象ファイルだけを退避する。
4. Release予定ファイルへ差し替える。
5. 対象機能を実ブラウザ・実HTTP・実PHP環境で確認する。
6. 関連する主要画面にregressionがないことを確認する。
7. 問題があれば正式Releaseへ進まず、productionを元へ戻した上でsource側を修正する。
8. PASSした場合もproduction上の対象ファイルを元の退避ファイルへ戻す。
9. 復元後に主要画面と対象機能の既存挙動を再確認する。
10. 差し替え終了時刻と結果を記録する。
11. その後に正式Update artifactを生成し、旧version実環境からのUpdate Acceptance Gateへ進む。

## PASS条件

- 差し替え対象以外を変更していない。
- productionで対象修正が再現・確認できる。
- console error、PHP fatal、HTTP 5xx等の新規障害がない。
- 関連する既存主要機能にregressionがない。
- 検証後に差し替え前の現行ファイルへ戻っている。
- 復元後の公式サイトが正常である。

## BLOCKED条件

次のいずれかに該当する場合はReleaseをBLOCKEDとする。

- 差し替え対象を確定できない。
- 現行ファイルを退避できない。
- production確認でFAILする。
- 人間確認が必要なのに未確認である。
- 元ファイルへの復元を確認できない。
- 復元後の公式サイトでregressionが発生する。

## 正式Update Acceptanceとの関係

Pre-Release Production Smoke Gate PASS後も、正式Release完了には別途、Release用に生成した実Update artifactを使った更新確認が必要である。

```text
Pre-Release Production Smoke
  -> 元ファイルへ復元
  -> 正式Update artifact生成
  -> 旧version実環境
  -> 旧Updater
  -> catalog / Update ZIP
  -> SHA-256 / 署名 / manifest
  -> 展開
  -> runtime完成
  -> HTTP起動
  -> 保護データ保持
  -> 公開後artifact再取得テスト
  -> PUBLIC RELEASE COMPLETE
```

productionへの一時差し替え確認だけで正式Release完了と判断してはならない。
