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

## 旧Updaterからの移行bridge

旧Updater（alpha.17）は`minimum_version`を必須としますが、未知の追加キーは拒否しません。旧Updaterから新Updaterへ移る最初の1回だけ、`--legacy-bridge`を付けて次のmanifestを生成できます。

```json
{
  "product": "Tomos",
  "from_version": "0.1.0-alpha.17",
  "minimum_version": "0.1.0-alpha.17",
  "version": "0.1.0-alpha.18",
  "files": {
    "VERSION": "<sha256>"
  }
}
```

bridgeの`minimum_version`は別入力を受け付けず、必ず`from_version`と同じ値です。bridgeでも`from_version === VERSION`と`from_version < version`を要求するため、段階を飛ばすための互換機能ではありません。alpha.17→alpha.18をbridgeで移行した後は、alpha.18→alpha.19以降を通常の`from_version`だけのmanifestで生成します。`minimum_version`を恒久的な通常仕様へ戻しません。

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

ビルダーはmanifestを署名し、ZIPを再読込してmanifestと各ファイルのSHA-256を確認します。通常は`minimum_version`を生成しません。移行bridgeが必要な場合だけ、`--legacy-bridge`を明示します。

```sh
php tools/build-update-package.php \
  --from=0.1.0-alpha.17 \
  --version=0.1.0-alpha.18 \
  --legacy-bridge \
  --private-key=/safe/private.pem \
  --output=/safe/tomos-update-0.1.0-alpha.18.zip \
  --file=VERSION
```

## 旧形式ZIP

`minimum_version`だけを持つ旧manifestへのfallbackはありません。署名が正しくても、新仕様では必須の`from_version`がないため拒否されます。bridgeは`from_version`と`minimum_version`が一致する場合だけ受理され、両者が異なるmanifestも拒否されます。既存の旧形式Update ZIPは差し替えず、移行時だけbridgeを新規生成します。手動ZIP更新は恒久的な正式ルートですが、旧形式ZIPを新仕様の検証条件から除外するものではありません。

manifestの真正性は従来どおり`update/public-key.pem`による`manifest.sig`検証が根拠です。catalogのfrom/toやZIPのSHA-256は案内・配布整合性確認であり、署名検証を置き換えません。
