<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/ui.php';

session_start();

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = dirname(__DIR__, 2) . '/core/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$rootDir = dirname(__DIR__, 2);
$configPath = $rootDir . '/config.php';
$config = [];
if (is_file($configPath)) {
    $loadedConfig = require $configPath;
    $config = is_array($loadedConfig) ? $loadedConfig : [];
}

$publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
$authRemember = new Tomos\PostAuthRememberToken($config, $rootDir);
if ($config === [] || !$authRemember->restoreSession()) {
    header('Location: ' . Tomos\Security::publicUrl('/post/', $publicBasePath) . '?section=settings&return_to=' . rawurlencode('/post/navigation/'));
    exit;
}

if (empty($_SESSION['tomos_post_settings_token'])) {
    $_SESSION['tomos_post_settings_token'] = bin2hex(random_bytes(32));
}

$themeSettingsPath = $rootDir . '/theme-settings.php';
$themeSettings = (new Tomos\ThemeSettings($rootDir))->settings();
$navigationSettings = is_array($themeSettings['navigation'] ?? null)
    ? $themeSettings['navigation']
    : ['mode' => 'auto', 'items' => []];
$navigationAutoItems = navigationAutoItems($config, $rootDir);
$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_token'] ?? '');
    if (class_exists(Tomos\UpdateLock::class) && Tomos\UpdateLock::isActive($rootDir)) {
        $errors[] = 'Tomosの更新中です。完了してからもう一度操作してください。';
    } elseif ($token === '' || !hash_equals((string) $_SESSION['tomos_post_settings_token'], $token)) {
        $errors[] = 'フォームの有効期限が切れました。もう一度送信してください。';
    } else {
        [$currentThemeSettings, $loadErrors] = Tomos\ThemeSettingsConfigWriter::load($themeSettingsPath);
        if ($loadErrors !== []) {
            $errors = array_merge($errors, $loadErrors);
        } else {
            [$newThemeSettings, $updateErrors] = Tomos\ThemeSettingsConfigWriter::update($currentThemeSettings, $_POST, $navigationAutoItems);
            if ($updateErrors !== []) {
                $errors = array_merge($errors, $updateErrors);
            } elseif (!Tomos\ThemeSettingsConfigWriter::write($themeSettingsPath, $currentThemeSettings, $newThemeSettings, $rootDir)) {
                $errors[] = 'テーマ設定を保存できませんでした。元の設定は維持されています。theme-settings.php または設置ディレクトリの書き込み権限を確認してください。';
            } else {
                $navigationSettings = $newThemeSettings['navigation'];
                $messages[] = 'ナビゲーション設定を保存しました。';
                $_SESSION['tomos_post_settings_token'] = bin2hex(random_bytes(32));
            }
        }
    }
}

renderNavigationPage($config, $navigationSettings, $navigationAutoItems, $errors, $messages, (string) $_SESSION['tomos_post_settings_token']);

function renderNavigationPage(array $config, array $settings, array $autoItems, array $errors, array $messages, string $token): void
{
    $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    $postSettingsUrl = Tomos\Security::publicUrl('/post/?section=settings', $publicBasePath);
    $configured = [];
    foreach (is_array($settings['items'] ?? null) ? $settings['items'] : [] as $item) {
        if (is_array($item)) {
            $key = navigationPathKey($item['path'] ?? '');
            if ($key !== '') {
                $configured[$key] = $item;
            }
        }
    }

    $orderedItems = $configured;
    foreach ($autoItems as $item) {
        $key = navigationPathKey($item['path'] ?? '');
        if ($key !== '' && !isset($orderedItems[$key])) {
            $orderedItems[$key] = ['path' => $item['path'], 'label' => '', 'hidden' => false];
        }
    }

    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>ナビゲーション設定</title><link rel="stylesheet" href="' . e(Tomos\Security::publicUrl('/post/assets/tomos-post-ui.css', $publicBasePath)) . '"></head><body><main class="wrap">';
    echo '<header class="page-heading"><h1>' . tomosPostIcon('navigation') . '<span>ナビゲーション</span></h1><p class="hint">サイトナビゲーションの構成を設定します。</p></header>';

    if ($errors !== []) {
        echo '<div class="errors" role="alert"><strong>設定を保存できませんでした。</strong><ul>';
        foreach ($errors as $error) {
            echo '<li>' . e((string) $error) . '</li>';
        }
        echo '</ul></div>';
    } elseif ($messages !== []) {
        echo '<div class="success" role="status" aria-live="polite"><ul>';
        foreach ($messages as $message) {
            echo '<li>' . e((string) $message) . '</li>';
        }
        echo '</ul></div>';
    }

    $mode = ($settings['mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<fieldset class="navigation-mode"><legend>表示モード</legend><div class="mode-switch">';
    echo '<label><input type="radio" name="navigation_mode" value="auto"' . ($mode === 'auto' ? ' checked' : '') . '><span>自動</span></label>';
    echo '<label><input type="radio" name="navigation_mode" value="manual"' . ($mode === 'manual' ? ' checked' : '') . '><span>手動</span></label>';
    echo '</div><p class="hint">自動ではThemeの順序・ラベルを使います。手動では項目ごとに設定できます。</p></fieldset>';
    echo '<div id="navigation-items" data-navigation-mode="' . e($mode) . '">';
    $index = 0;
    foreach ($orderedItems as $item) {
        $path = navigationPathKey($item['path'] ?? '');
        $auto = navigationAutoItem($autoItems, $path);
        if ($path === '' || $auto === null) {
            continue;
        }
        $label = is_string($item['label'] ?? null) ? $item['label'] : '';
        $hidden = !empty($item['hidden']);
        $displayLabel = $label !== '' ? $label : (string) ($auto['label'] ?? '');
        echo '<div class="navigation-item" data-navigation-item>';
        echo '<span class="nav-drag-handle" aria-hidden="true">' . tomosPostIcon('more') . '</span>';
        echo '<span class="nav-row-title">' . e($displayLabel) . '</span>';
        echo '<span class="nav-row-status' . ($hidden ? ' is-hidden' : '') . '">' . ($hidden ? '非表示' : '表示中') . '</span>';
        echo '<div class="navigation-item-controls"><button class="icon-button" type="button" data-nav-move="up" aria-label="上へ移動" title="上へ移動">' . tomosPostIcon('arrow-up') . '</button><button class="icon-button" type="button" data-nav-move="down" aria-label="下へ移動" title="下へ移動">' . tomosPostIcon('arrow-down') . '</button></div>';
        echo '<input type="hidden" name="navigation_items[' . $index . '][path]" value="' . e($path) . '">';
        echo '<details class="navigation-item-details"><summary>項目設定</summary><div class="navigation-fields"><small class="hint"><code>' . e($path) . '</code></small>';
        echo '<label for="navigation-label-' . $index . '">表示ラベル（空欄はauto label）</label>';
        echo '<input id="navigation-label-' . $index . '" type="text" name="navigation_items[' . $index . '][label]" value="' . e($label) . '" maxlength="120">';
        echo '<label class="checkbox"><input type="checkbox" name="navigation_items[' . $index . '][hidden]" value="1"' . ($hidden ? ' checked' : '') . '><span>非表示</span></label></div></details>';
        echo '</div>';
        $index++;
    }
    echo '</div><p class="hint">非表示にしてもページの公開状態や直接URLへの到達性は変わりません。</p>';
    echo '<div class="actions"><button type="submit">ナビゲーション設定を保存する</button></div></form>';
    echo '<script>(function(){var list=document.getElementById("navigation-items"),form=list&&list.closest("form"),modeInputs=document.querySelectorAll("input[name=\"navigation_mode\"]");if(!list||!form)return;var update=function(){var manual=Array.prototype.some.call(modeInputs,function(input){return input.checked&&input.value==="manual";});list.hidden=!manual;};Array.prototype.forEach.call(modeInputs,function(input){input.addEventListener("change",update);});list.addEventListener("click",function(event){var button=event.target.closest("[data-nav-move]");if(!button)return;var item=button.closest("[data-navigation-item]"),sibling=item&&(button.getAttribute("data-nav-move")==="up"?item.previousElementSibling:item.nextElementSibling);if(!item||!sibling)return;if(button.getAttribute("data-nav-move")==="up"){list.insertBefore(item,sibling);}else{list.insertBefore(sibling,item);}});form.addEventListener("submit",function(){Array.prototype.forEach.call(list.querySelectorAll("[data-navigation-item]"),function(item,index){Array.prototype.forEach.call(item.querySelectorAll("[name^=\"navigation_items[\"]"),function(field){field.name=field.name.replace(/navigation_items\\[[0-9]+\\]/,"navigation_items["+index+"]");});});});update();})();</script>';
    echo tomosPostReturnLink($postSettingsUrl);
    echo '</main></body></html>';
}

function navigationAutoItems(array $config, string $rootDir): array
{
    try {
        $contentDir = (string) (($config['paths']['content_dir'] ?? '') ?: ($rootDir . '/content'));
        $cacheDir = (string) (($config['paths']['cache_dir'] ?? '') ?: ($rootDir . '/cache'));
        $index = new Tomos\MetadataIndex($contentDir, $cacheDir);
        $pages = $index->loadFresh();
        if ($pages === null) {
            $pages = $index->build();
        }
        $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
        $items = (new Tomos\NavigationBuilder($publicBasePath))->primaryItems($pages, '/', !empty($config['features']['rss']));
        foreach ($items as $index => $item) {
            if (!is_array($item) || isset($item['path'])) {
                continue;
            }
            $items[$index]['path'] = navigationPathForAutoItem($item);
        }
        return $items;
    } catch (Throwable $exception) {
        return [];
    }
}

function navigationPathForAutoItem(array $item): string
{
    $type = (string) ($item['type'] ?? '');
    if ($type === 'home') return '/';
    if ($type === 'about') return '/about';
    if ($type === 'search') return '/search/';
    if ($type === 'tags') return '/tags/';
    return $type === 'rss' ? '/feed.xml' : '';
}

function navigationPathKey($value): string
{
    if (!is_string($value) || $value === '') return '';
    $result = Tomos\Security::validateUrlPath(trim($value));
    if (empty($result['is_valid'])) return '';
    $path = (string) $result['path'];
    return $path === '/' ? '/' : rtrim($path, '/');
}

function navigationAutoItem(array $items, string $path): ?array
{
    foreach ($items as $item) {
        if (is_array($item) && navigationPathKey($item['path'] ?? '') === $path) return $item;
    }
    return null;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
