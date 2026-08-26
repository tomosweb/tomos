# Navigation Settings v1

Themeの`theme-settings.php`では、テーマ固有の分岐を作らずに主要ナビゲーションを設定できます。

```php
'navigation' => [
    'mode' => 'manual', // auto または manual
    'items' => [
        ['path' => '/research/', 'label' => 'Our Research'],
        ['path' => '/members/'],
        ['path' => '/publications/', 'hidden' => true],
    ],
],
```

`mode`の既定値は`auto`です。`auto`では、既存の主要ナビゲーションの順序・ラベルをそのまま使用します。`manual`では、`items`に書いた順序で、`hidden`でない項目だけを表示します。

各項目の`path`はTomos内部の絶対パスで、外部URL、query、fragment、traversalは無効として無視されます。`label`は任意です。空または省略時は、auto navigationが同じ宛先に持つ既存ラベルだけを採用します。auto navigationで宛先を解決できず、`label`も省略されている項目は、表示名を推測せず安全に無視します。`label`だけでauto navigationに存在しない宛先を追加することもありません。`hidden`は主要ナビゲーションから隠すだけで、対象ページのrouting・公開状態・直接URLへの到達性は変更しません。

この設定は`nav.primary_items`と`nav.primary_links`の両方へ適用されます。標準Themeは共通Core APIを使用するため、設定処理をテーマごとに追加する必要はありません。
