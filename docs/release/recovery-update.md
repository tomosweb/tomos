# Recovery Update Policy

## 目的

Recovery Updateは、通常のversion更新とは分離された同一version修復のための経路である。

通常Updateのversion semanticsを壊さず、緊急時にUpdater、Core、runtime必須データなど限定された対象だけを安全に修復するために使用する。

## 基本原則

- 通常UpdateとRecovery Updateをcatalog上で分離する。
- `mode=recovery` の場合のみ `from` と `to` が同一versionであることを許可する。
- `from` は現在versionと完全一致しなければならない。
- Recovery artifactは署名必須とする。
- Recovery artifactは通常Update ZIPとは別artifactとする。
- 同じZIP URLを上書きしない。
- 緊急修正版ごとに新しい `repair_id` とimmutable URLを発行する。
- 成功した `repair_id` はruntimeへ記録し、同じrepairを再表示・再適用しない。
- 適用対象はallowlist方式とし、ユーザーデータやサイト固有データを含めない。

## Manifest例

```json
{
  "from": "0.6.3",
  "to": "0.6.3",
  "mode": "recovery",
  "repair_id": "0.6.3-repair-20260902-01",
  "package_url": "https://example.invalid/releases/0.6.3/repairs/0.6.3-repair-20260902-01.zip",
  "sha256": "..."
}
```

## 適用条件

Recovery Updateを適用できるのは次をすべて満たす場合だけとする。

1. `mode` が `recovery` である。
2. manifestの `from` が現在versionと完全一致する。
3. manifestの `to` が現在versionと同一である。
4. `repair_id` が存在し、一意である。
5. 署名検証が成功する。
6. SHA-256検証が成功する。
7. package内の全pathがRecovery allowlist内にある。
8. 同じ `repair_id` が適用済みではない。

いずれかを満たさない場合はfail-closedで拒否する。

## Recovery allowlist

Recovery Updateは、Updater、Core、runtime必須データなど、復旧に必要な限定領域のみを対象とする。

少なくとも次は対象外とする。

- `config.php`
- `content/`
- uploads
- custom Theme / site-specific Theme
- サイト固有設定
- 運用データ

Recovery package builderはallowlist外pathを含むpackageを生成してはならず、Updater側でも独立して同じ境界を検証する。

## Immutable artifact

同一versionの緊急修正であっても既存ZIPを差し替えない。

悪い例:

```text
/releases/0.6.3/tomos-update.zip
```

を内容だけ変更して再利用すること。

正しい例:

```text
/releases/0.6.3/repairs/0.6.3-repair-20260902-01.zip
/releases/0.6.3/repairs/0.6.3-repair-20260902-02.zip
```

catalogだけを新artifactへ向ける。

これにより、CDN cache、古いZIPと新SHAの不一致、GitHub asset差し替え、同一version再適用判定の混乱を避ける。

## repair_idの記録

Recovery成功後は `repair_id` をruntimeへ永続記録する。

同じ `repair_id` は以後、更新候補として表示せず再適用しない。

新たな修復が必要な場合は、新しい `repair_id` を持つ別artifactを発行する。

## 旧Updaterとの互換性境界

Recovery Updateは、それを理解するUpdaterにしか適用できない。

旧Updaterが `recovery_updates` または `mode=recovery` を理解できない場合、中央catalogの変更だけで同一version修復を開始することはできない。

したがって互換性境界は明示する。

- Recovery対応Updater: 同一version Recovery Updateを利用できる。
- 非対応の旧Updater: まずRecovery対応versionへ通常Updateする。
- 通常Update経路へ到達できない旧環境: 対応する通常Updateまたは手動bootstrapが必要となる。

中央側だけで、旧コードへ一度も到達できないクライアントを強制的にRecovery経路へ切り替えることはできない。

## Catalog検証

Updaterはcatalog全体を、現在のクライアントに無関係なentryまで旧semanticsで先に拒否してはならない。

原則として次の順序で処理する。

1. catalogの外形・署名等の共通安全性を検証する。
2. 現在versionとUpdater capabilityに対応する候補entryを選択する。
3. 選択されたentryを、そのmodeに対応するschemaで厳密検証する。
4. 無関係な将来version・別version・別modeのentryを、現在クライアントのversion遷移規則だけを理由にcatalog全体の取得失敗へしてはならない。

## Release Acceptance Gateとの関係

Recovery機能自体も、仕様・unit testだけでは完了としない。

公開artifactを使用し、対応する実Updaterから次を確認する。

- catalog取得
- Recovery候補選択
- PHP実取得
- SHA-256 / 署名 / manifest検証
- allowlist確認
- runtime反映
- 実サイト起動
- 保護対象保持
- `repair_id` 記録
- 同じ `repair_id` の再表示・再適用防止
- 意図的な破損packageでrollback / fail-closed

必須検証が `SKIP` の場合はPASSとして扱わない。
