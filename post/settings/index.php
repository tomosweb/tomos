<?php

declare(strict_types=1);

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
    header('Location: ' . Tomos\Security::publicUrl('/post/', $publicBasePath) . '?section=settings&return_to=' . rawurlencode('/post/site-settings.php'));
    exit;
}

if (empty($_SESSION['tomos_post_settings_token'])) {
    $_SESSION['tomos_post_settings_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$messages = [];
$form = formValues($config);
$themeSettingsPath = $rootDir . '/theme-settings.php';
$themeSettings = (new Tomos\ThemeSettings($rootDir))->settings();
$navigationSettings = is_array($themeSettings['navigation'] ?? null) ? $themeSettings['navigation'] : ['mode' => 'auto', 'items' => []];
$navigationAutoItems = navigationAutoItems($config, $rootDir);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_token'] ?? '');
    if (class_exists(Tomos\UpdateLock::class) && Tomos\UpdateLock::isActive($rootDir)) {
        $errors[] = 'Tomosの更新中です。完了してからもう一度操作してください。';
    } elseif ($token === '' || !hash_equals((string) $_SESSION['tomos_post_settings_token'], $token)) {
        $errors[] = 'フォームの有効期限が切れました。もう一度送信してください。';
    } elseif (($_POST['settings_section'] ?? '') === 'navigation') {
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
    } else {
        $form = submittedFormValues($_POST);
        [$newConfig, $updateErrors] = Tomos\SiteSettingsConfigWriter::update($config, $_POST);
        if ($updateErrors !== []) {
            $errors = array_merge($errors, $updateErrors);
        } elseif (!Tomos\ConfigWriter::write($configPath, $newConfig, $rootDir)) {
            $errors[] = '設定を保存できませんでした。元の設定は維持されています。config.php または設置ディレクトリの書き込み権限を確認してください。';
        } else {
            $config = $newConfig;
            $form = formValues($config);
            $messages[] = 'サイト設定を保存しました。';
            $_SESSION['tomos_post_settings_token'] = bin2hex(random_bytes(32));
        }
    }
}

renderSettingsPage(
    $config,
    $form,
    $errors,
    $messages,
    (string) $_SESSION['tomos_post_settings_token'],
    $navigationSettings,
    $navigationAutoItems
);

function formValues(array $config): array
{
    return [
        'site_name' => (string) ($config['site']['name'] ?? ''),
        'site_description' => (string) ($config['site']['description'] ?? ''),
        'language' => Tomos\LanguageTag::fallback($config['site']['language'] ?? null),
        'language_custom' => '',
        'timezone' => (string) ($config['site']['timezone'] ?? 'Asia/Tokyo'),
        'feature_rss' => !empty($config['features']['rss']),
        'rss_path_prefix' => (string) ($config['feed']['path_prefix'] ?? ''),
        'feature_sitemap' => !empty($config['features']['sitemap']),
    ];
}

function submittedFormValues(array $input): array
{
    return [
        'site_name' => is_string($input['site_name'] ?? null) ? $input['site_name'] : '',
        'site_description' => is_string($input['site_description'] ?? null) ? $input['site_description'] : '',
        'language' => is_string($input['language'] ?? null) ? $input['language'] : '',
        'language_custom' => is_string($input['language_custom'] ?? null) ? $input['language_custom'] : '',
        'timezone' => is_string($input['timezone'] ?? null) ? $input['timezone'] : '',
        'feature_rss' => isset($input['feature_rss']) && $input['feature_rss'] === '1',
        'rss_path_prefix' => is_string($input['rss_path_prefix'] ?? null) ? $input['rss_path_prefix'] : '',
        'feature_sitemap' => isset($input['feature_sitemap']) && $input['feature_sitemap'] === '1',
    ];
}

function renderSettingsPage(array $config, array $form, array $errors, array $messages, string $token, array $navigationSettings, array $navigationAutoItems): void
{
    $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    $postSettingsUrl = Tomos\Security::publicUrl('/post/?section=settings', $publicBasePath);
    $analyticsUrl = Tomos\Security::publicUrl('/post/?section=settings#analytics-settings', $publicBasePath);
    $themeUrl = Tomos\Security::publicUrl('/post/theme/', $publicBasePath);
    $siteUrl = Tomos\Security::publicUrl('/', $publicBasePath);

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<link rel="icon" href="../../themes/tomos-minimal/assets/favicon.png" type="image/png">';
    echo '<link rel="apple-touch-icon" href="../../themes/tomos-minimal/assets/apple-touch-icon.png">';
    echo '<title>サイト設定</title>';
    echo '<style>
:root{--tomos-bg:#f6f4ef;--tomos-surface:#fcfbf8;--tomos-input:#fff;--tomos-text:#2f2f2f;--tomos-muted:#6b6b6b;--tomos-border:#d9d6cf;--tomos-border-soft:#e7e3dc;--tomos-border-hover:#cfcbc3;--tomos-accent:#a44a1d;--tomos-primary:#9a431c;--tomos-primary-hover:#853919;--tomos-primary-active:#713018;--tomos-notice-bg:#fbf4e8;--tomos-notice-text:#6f4b1d;--tomos-notice-border:#e5c998;--tomos-error-bg:#f8ecea;--tomos-error-border:#d9a39e;--tomos-danger-text:#8a2e26;--tomos-info-bg:#f7f7f4;--tomos-button-hover:#f7f5f0;--tomos-button-active:#efece6;--tomos-shadow:0 1px 2px rgba(47,47,47,0.04)}
html,body{width:100%;overflow-x:hidden}
body{background:var(--tomos-bg);box-sizing:border-box;color:var(--tomos-text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6;margin:0;padding:32px 16px}
.wrap{background:var(--tomos-surface);border:1px solid var(--tomos-border);border-radius:10px;box-shadow:var(--tomos-shadow);box-sizing:border-box;margin:0 auto;max-width:860px;padding:28px}
h1{font-size:1.8rem;margin:0 0 0.5rem}h2{border-top:1px solid var(--tomos-border-soft);font-size:1.2rem;margin:2rem 0 1rem;padding-top:1.5rem}
label{display:block;font-weight:700;margin:1rem 0 0.35rem}input[type=text],select{background:var(--tomos-input);border:1px solid var(--tomos-border);border-radius:6px;box-sizing:border-box;color:var(--tomos-text);font:inherit;font-size:16px;padding:0.65rem;width:100%}input[type=text]:focus,select:focus{border-color:var(--tomos-accent);box-shadow:0 0 0 3px rgba(164,74,29,0.12);outline:none}
.checkbox{align-items:center;display:flex;font-weight:700;gap:0.55rem;margin:0.75rem 0}.checkbox input{accent-color:var(--tomos-accent);margin:0}.hint{color:var(--tomos-muted);font-size:0.95rem}.errors{background:var(--tomos-error-bg);border:1px solid var(--tomos-error-border);border-radius:6px;color:var(--tomos-danger-text);padding:1rem}.success{background:var(--tomos-notice-bg);border:1px solid var(--tomos-notice-border);border-radius:6px;color:var(--tomos-notice-text);padding:1rem}.result{background:var(--tomos-info-bg);border:1px solid #e2e1dd;border-radius:6px;padding:1rem}
.actions{display:flex;flex-wrap:wrap;gap:0.6rem;margin-top:1.5rem}button,.button{background:var(--tomos-primary);border:1px solid var(--tomos-primary);border-radius:6px;color:#fff;display:inline-block;font:inherit;font-weight:700;padding:0.7rem 1rem;text-decoration:none}button:hover,.button:hover{background:var(--tomos-primary-hover);border-color:var(--tomos-primary-hover)}button:active,.button:active{background:var(--tomos-primary-active);border-color:var(--tomos-primary-active)}button:focus-visible,.button:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid rgba(164,74,29,0.28);outline-offset:2px}.button.secondary{background:var(--tomos-input);border-color:var(--tomos-border);color:var(--tomos-text)}.button.secondary:hover{background:var(--tomos-button-hover);border-color:var(--tomos-border-hover)}.button.secondary:active{background:var(--tomos-button-active)}
.navigation-item{border:1px solid var(--tomos-border-soft);border-radius:8px;margin:0.8rem 0;padding:1rem}.navigation-item-header{align-items:center;display:flex;gap:0.6rem;justify-content:space-between}.navigation-item-header strong{font-size:1rem}.navigation-item-controls{display:flex;gap:0.35rem}.navigation-item-controls button{background:var(--tomos-input);border-color:var(--tomos-border);color:var(--tomos-text);font-size:0.9rem;padding:0.35rem 0.55rem}.navigation-item-controls button:hover{background:var(--tomos-button-hover)}
@media (max-width:560px){body{padding:16px 10px}.wrap{padding:20px 16px}.actions button,.actions .button{box-sizing:border-box;min-height:44px;max-width:100%}}
</style></head><body><main class="wrap">';

    echo '<h1>サイト設定</h1>';
    echo '<p class="hint">公開サイトの基本情報とRSS・Sitemapを変更します。</p>';

    if ($errors !== []) {
        echo '<div class="errors"><strong>設定を保存できませんでした。</strong><ul>';
        foreach ($errors as $error) {
            echo '<li>' . e((string) $error) . '</li>';
        }
        echo '</ul></div>';
    } elseif ($messages !== []) {
        echo '<div class="success"><ul>';
        foreach ($messages as $message) {
            echo '<li>' . e((string) $message) . '</li>';
        }
        echo '</ul><p><a href="' . e($siteUrl) . '">公開サイトを確認する</a></p></div>';
    }

    renderNavigationSettingsSection($token, $navigationSettings, $navigationAutoItems);
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<h2>サイト情報</h2>';
    echo '<label for="site_name">サイト名</label>';
    echo '<input id="site_name" type="text" name="site_name" value="' . e((string) $form['site_name']) . '" maxlength="100" required>';
    echo '<label for="site_description">サイト説明（任意）</label>';
    echo '<input id="site_description" type="text" name="site_description" value="' . e((string) $form['site_description']) . '" maxlength="200">';
    echo '<label for="language">サイトの言語</label><select id="language" name="language">';
    $currentLanguage = (string) ($form['language'] ?? 'ja');
    $commonLanguages = ['ja' => '日本語 (ja)', 'en' => 'English (en)', 'fr' => 'Français (fr)', 'de' => 'Deutsch (de)', 'zh-Hans' => '简体中文 (zh-Hans)', 'zh-Hant' => '繁體中文 (zh-Hant)', 'ko' => '한국어 (ko)'];
    if (!isset($commonLanguages[$currentLanguage]) && $currentLanguage !== '') {
        $commonLanguages = [$currentLanguage => $currentLanguage] + $commonLanguages;
    }
    foreach ($commonLanguages as $value => $label) {
        echo '<option value="' . e($value) . '"' . ($currentLanguage === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select><p class="hint">ページ側で <code>language</code> を指定しない場合、この言語が使用されます。</p>';
    echo '<label for="language_custom">カスタム言語コード（任意）</label>';
    echo '<input id="language_custom" type="text" name="language_custom" value="' . e((string) ($form['language_custom'] ?? '')) . '" placeholder="en-US" autocomplete="off" spellcheck="false">';
    echo '<p class="hint">一覧にない言語はBCP 47形式で入力できます。入力した場合は選択欄より優先されます。</p>';
    echo '<label for="timezone">タイムゾーン</label>';
    echo '<input id="timezone" type="text" name="timezone" value="' . e((string) $form['timezone']) . '" placeholder="Asia/Tokyo" autocomplete="off" spellcheck="false">';
    echo '<p class="hint">空欄で保存すると <code>Asia/Tokyo</code> を使用します。</p>';

    echo '<h2>RSS・Sitemap</h2>';
    echo '<label class="checkbox"><input type="checkbox" name="feature_rss" value="1"' . (!empty($form['feature_rss']) ? ' checked' : '') . '>RSSを有効にする</label>';
    echo '<label for="rss_path_prefix">RSS対象パス（任意）</label>';
    echo '<input id="rss_path_prefix" type="text" name="rss_path_prefix" value="' . e((string) $form['rss_path_prefix']) . '" placeholder="/news" autocomplete="off" spellcheck="false">';
    echo '<p class="hint">空欄ではすべての公開ページを対象にします。<code>/news</code> のように指定すると、そのパスより下のページだけを含めます。</p>';
    echo '<label class="checkbox"><input type="checkbox" name="feature_sitemap" value="1"' . (!empty($form['feature_sitemap']) ? ' checked' : '') . '>Sitemapを有効にする</label>';

    echo '<div class="actions"><button type="submit">設定を保存する</button><a class="button secondary" href="' . e($postSettingsUrl) . '">Tomos Postへ戻る</a></div>';
    echo '</form>';

    echo '<h2>その他の設定</h2>';
    echo '<div class="result"><div class="actions">';
    echo '<a class="button secondary" href="' . e($analyticsUrl) . '">GA4設定を開く</a>';
    echo '<a class="button secondary" href="' . e($themeUrl) . '">テーマ変更を開く</a>';
    echo '</div></div>';

    echo '</main></body></html>';
}

function renderNavigationSettingsSection(string $token, array $settings, array $autoItems): void
{
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

    echo '<h2 id="navigation-settings">ナビゲーション</h2>';
    echo '<p class="hint">現在のauto navigationにある項目だけを、順序・表示名・表示状態として編集できます。外部URLや任意のパスは追加できません。</p>';
    echo '<form method="post" action="#navigation-settings" id="navigation-settings-form">';
    echo '<input type="hidden" name="settings_section" value="navigation">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    $mode = ($settings['mode'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
    echo '<fieldset><legend>表示モード</legend>';
    echo '<label class="checkbox"><input type="radio" name="navigation_mode" value="auto"' . ($mode === 'auto' ? ' checked' : '') . '>自動（auto navigationの順序・ラベル）</label>';
    echo '<label class="checkbox"><input type="radio" name="navigation_mode" value="manual"' . ($mode === 'manual' ? ' checked' : '') . '>手動（下の順序・設定を使用）</label></fieldset>';
    echo '<div id="navigation-items">';
    $index = 0;
    foreach ($orderedItems as $item) {
        $path = navigationPathKey($item['path'] ?? '');
        $auto = navigationAutoItem($autoItems, $path);
        if ($path === '' || $auto === null) {
            continue;
        }
        $label = is_string($item['label'] ?? null) ? $item['label'] : '';
        $hidden = !empty($item['hidden']);
        echo '<div class="navigation-item" data-navigation-item>';
        echo '<div class="navigation-item-header"><strong>' . e((string) ($auto['label'] ?? '')) . '</strong><div class="navigation-item-controls">';
        echo '<button type="button" data-nav-move="up">上へ</button><button type="button" data-nav-move="down">下へ</button></div></div>';
        echo '<small class="hint"><code>' . e($path) . '</code></small>';
        echo '<input type="hidden" name="navigation_items[' . $index . '][path]" value="' . e($path) . '">';
        echo '<label for="navigation-label-' . $index . '">表示ラベル（空欄はauto label）</label>';
        echo '<input id="navigation-label-' . $index . '" type="text" name="navigation_items[' . $index . '][label]" value="' . e($label) . '" maxlength="120">';
        echo '<label class="checkbox"><input type="checkbox" name="navigation_items[' . $index . '][hidden]" value="1"' . ($hidden ? ' checked' : '') . '>非表示</label>';
        echo '</div>';
        $index++;
    }
    echo '</div><p class="hint">非表示にしてもページの公開状態や直接URLへの到達性は変わりません。</p>';
    echo '<div class="actions"><button type="submit">ナビゲーション設定を保存する</button></div></form>';
    echo '<script>(function(){var list=document.getElementById("navigation-items"),form=document.getElementById("navigation-settings-form");if(!list||!form)return;list.addEventListener("click",function(event){var button=event.target.closest("[data-nav-move]");if(!button)return;var item=button.closest("[data-navigation-item]");if(!item)return;var sibling=button.getAttribute("data-nav-move")==="up"?item.previousElementSibling:item.nextElementSibling;if(!sibling)return;if(button.getAttribute("data-nav-move")==="up"){list.insertBefore(item,sibling);}else{list.insertBefore(sibling,item);}});form.addEventListener("submit",function(){Array.prototype.forEach.call(list.querySelectorAll("[data-navigation-item]"),function(item,index){Array.prototype.forEach.call(item.querySelectorAll("[name^=\"navigation_items[\"]"),function(field){field.name=field.name.replace(/navigation_items\\[[0-9]+\\]/,"navigation_items["+index+"]");});});});})();</script>';
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
    if ($type === 'home') {
        return '/';
    }
    if ($type === 'about') {
        return '/about';
    }
    if ($type === 'search') {
        return '/search/';
    }
    if ($type === 'tags') {
        return '/tags/';
    }
    return $type === 'rss' ? '/feed.xml' : '';
}

function navigationPathKey($value): string
{
    if (!is_string($value) || $value === '') {
        return '';
    }
    $result = Tomos\Security::validateUrlPath(trim($value));
    if (empty($result['is_valid'])) {
        return '';
    }
    $path = (string) $result['path'];
    return $path === '/' ? '/' : rtrim($path, '/');
}

function navigationAutoItem(array $items, string $path): ?array
{
    foreach ($items as $item) {
        if (is_array($item) && navigationPathKey($item['path'] ?? '') === $path) {
            return $item;
        }
    }
    return null;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
