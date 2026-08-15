# Update ZIP manifest仕様

Tomos Update ZIPの`manifest.json`は、適用元と適用後を1つの更新経路として明示します。

```json
{
  "product": "Tomos",
  "from_version": "0.1.0-alpha.17",
  "version": "0.1.0-alpha.18",
  "files": {
    "VERSION": "<sha256>"
  }
}
```

`from_version`は、このZIPを適用できる唯一の現在Tomosバージョンです。`version`は適用後のバージョンです。両者はTomos version形式で、`from_version < version`でなければなりません。

`UpdateService`は署名検証後、`from_version === VERSION`を必須条件としてmanifestを検証します。オンライン更新と手動ZIP更新は同じ`inspectStaged()`を通るため、段階を飛ばしたZIPや、別の現在版向けのZIPはどちらの経路でも拒否されます。確認画面のsummaryには`from_version`、`current_version`、`version`を保持します。

## ZIP生成

署名鍵はTomosプロジェクト外で管理し、既存のUpdate ZIPビルダーへ`--from`と`--version`を渡します。

```sh
php tools/build-update-package.php \
  --from=0.1.0-alpha.17 \
  --version=0.1.0-alpha.18 \
  --private-key=/safe/private.pem \
  --output=/safe/tomos-update-0.1.0-alpha.18.zip \
  --file=VERSION
```

ビルダーはmanifestを署名し、ZIPを再読込してmanifestと各ファイルのSHA-256を確認します。`--minimum`は廃止され、manifestに`minimum_version`は生成されません。

## 旧形式ZIP

`minimum_version`だけを持つ旧manifestへのfallbackはありません。署名が正しくても、新仕様では必須の`from_version`がないため拒否されます。既存の旧形式Update ZIPは差し替えず、次の正式リリース用に新形式のUpdate ZIPを生成します。手動ZIP更新は恒久的な正式ルートですが、旧形式ZIPを新仕様の検証条件から除外するものではありません。

manifestの真正性は従来どおり`update/public-key.pem`による`manifest.sig`検証が根拠です。catalogのfrom/toやZIPのSHA-256は案内・配布整合性確認であり、署名検証を置き換えません。
