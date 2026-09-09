# Browser Update regression gates

この文書は、2026-09-02のBrowser Update事故からv0.6.3で収束したUpdate経路を、現在のRelease AcceptanceとHistorical Update Regressionに分けて記録するものです。事故報告の正本は、公式サイトの[2026-09-02 Browser Update案内](https://tomoswords.org/news/2026-09-02-browser-update)です。

## Browser Update Incident lineage

### v0.6.1

公開v0.6.1 Distributionはpublic tag `v0.6.1`（private source merge `4816c5f`）に対応します。旧Updaterは更新対象をruntimeへ直接適用し、当時の`core/required-installed-files.txt`を検証しました。

### v0.6.2 original release

private source merge `9d71d9a` がv0.6.2 sourceです。public GitHub Release v0.6.2にはDistribution ZIPはありますが、v0.6.1→v0.6.2 Update ZIPはありません。そのため、v0.6.1からv0.6.2へのUpdateは、現在のsupported public update entryではありません。

### Incident and migration generation

`4659c84`では、v0.6.1旧Updaterとの互換性のため、Theme rulesを`core/updater-pending/`へ置き、後段のUpdaterSelfUpdateでruntimeへ配置するUpdate表現が導入されました。`270471b`と`ab48d0f`では、pending runtimeを検証時にmaterializeする処理と関連する更新整合性が追加されました。この段階を固定するのが`update_theme_rules_migration_check.php`です。

### Legacy bootstrap generation

`286a881`では、旧Updaterが新しいrequired-file listを先に検証してしまう問題を避けるlegacy bootstrap表現が追加されました。旧required-file listを最初の検証用に保持し、新しいlistとTheme rulesをpending finalizeへ送る方式です。この段階を固定するのが`update_legacy_browser_bootstrap_check.php`です。

### v0.6.3 final architecture

`0f48a60`でpending runtime、UpdaterSelfUpdate、manifest validation、rollback/fail-closed、同一version recoveryの修正群が統合されました。public v0.6.3 Releaseでは、実際に`v0.6.1→v0.6.3`と`v0.6.2→v0.6.3`のUpdate ZIPが公開され、`0aa3442`および`b8b9d64`のtransition matrixで両経路を検証します。catalog全体を先に拒否していた問題は`7f0b199`で修正されました。

## Gate classification

### Current Release Acceptance

毎回のReleaseで必須なのは、現在supportするsource versionからtarget versionへの実artifact更新です。v0.6.8では、v0.6.7実Release baselineからv0.6.8への更新、署名・SHA-256・manifest、runtime配置、保護データ保持、rollback、実サイト起動、公開URLからの再取得を必須とします。

### Historical Update Regression

次のtestは事故対応中の異なるUpdate generationを固定します。両者は同一v0.6.2 ZIPの別名ではありません。

| Test | Classification | Historical contract |
| --- | --- | --- |
| `update_theme_rules_migration_check.php` | Historical Update Regression | `4659c84`以降のpending Theme rulesをruntimeへmaterializeしてから検証する経路 |
| `update_legacy_browser_bootstrap_check.php` | Historical Update Regression | `286a881`の旧Updater向けrequired-file bootstrapと後段finalize |
| `update_v063_transition_matrix_check.php` | Historical Update Regression for published compatibility | public v0.6.3で公開されたv0.6.1/v0.6.2からのtransition |

Historical testは削除、SKIP、warning化しません。専用workflowで、各commit由来のartifactを明示的に生成またはpublic Releaseから取得し、`--strict`で実行します。Current Release Acceptanceとは別の判定として表示します。

## Artifact provenance

Historical fixture builderは、public v0.6.1 Distribution（public Release asset）と、次のprivate immutable source refsから入力を再現します。

- migration package: `ab48d0f256c79e9bb2d92b1c0aa62fd7d52ed7a2`
- legacy bootstrap package: `286a881b79fd1e79fca402a791367cb9cde75e16`
- published transition packages: public v0.6.3 Release assets

生成時のSHA-256とsource refはfixture outputの`provenance.json`へ記録します。現在mainから都合よく作ったZIPを、historical truthとして使用しません。
