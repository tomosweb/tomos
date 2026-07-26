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
if ($config === [] || empty($_SESSION['tomos_post_authenticated'])) {
    header('Location: ' . Tomos\Security::publicUrl('/post/?section=settings', $publicBasePath));
    exit;
}

if (empty($_SESSION['tomos_post_settings_token'])) {
    $_SESSION['tomos_post_settings_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$messages = [];
$form = formValues($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = submittedFormValues($_POST);
    $token = (string) ($_POST['_token'] ?? '');
    if (class_exists(Tomos\UpdateLock::class) && Tomos\UpdateLock::isActive($rootDir)) {
        $errors[] = 'Tomosの更新中です。完了してからもう一度操作してください。';
    } elseif ($token === '' || !hash_equals((string) $_SESSION['tomos_post_settings_token'], $token)) {
        $errors[] = 'フォームの有効期限が切れました。もう一度送信してください。';
    } else {
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
    (string) $_SESSION['tomos_post_settings_token']
);

function formValues(array $config): array
{
    return [
        'site_name' => (string) ($config['site']['name'] ?? ''),
        'site_description' => (string) ($config['site']['description'] ?? ''),
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
        'timezone' => is_string($input['timezone'] ?? null) ? $input['timezone'] : '',
        'feature_rss' => isset($input['feature_rss']) && $input['feature_rss'] === '1',
        'rss_path_prefix' => is_string($input['rss_path_prefix'] ?? null) ? $input['rss_path_prefix'] : '',
        'feature_sitemap' => isset($input['feature_sitemap']) && $input['feature_sitemap'] === '1',
    ];
}

function renderSettingsPage(array $config, array $form, array $errors, array $messages, string $token): void
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
label{display:block;font-weight:700;margin:1rem 0 0.35rem}input[type=text]{background:var(--tomos-input);border:1px solid var(--tomos-border);border-radius:6px;box-sizing:border-box;color:var(--tomos-text);font:inherit;font-size:16px;padding:0.65rem;width:100%}input[type=text]:focus{border-color:var(--tomos-accent);box-shadow:0 0 0 3px rgba(164,74,29,0.12);outline:none}
.checkbox{align-items:center;display:flex;font-weight:700;gap:0.55rem;margin:0.75rem 0}.checkbox input{accent-color:var(--tomos-accent);margin:0}.hint{color:var(--tomos-muted);font-size:0.95rem}.errors{background:var(--tomos-error-bg);border:1px solid var(--tomos-error-border);border-radius:6px;color:var(--tomos-danger-text);padding:1rem}.success{background:var(--tomos-notice-bg);border:1px solid var(--tomos-notice-border);border-radius:6px;color:var(--tomos-notice-text);padding:1rem}.result{background:var(--tomos-info-bg);border:1px solid #e2e1dd;border-radius:6px;padding:1rem}
.actions{display:flex;flex-wrap:wrap;gap:0.6rem;margin-top:1.5rem}button,.button{background:var(--tomos-primary);border:1px solid var(--tomos-primary);border-radius:6px;color:#fff;display:inline-block;font:inherit;font-weight:700;padding:0.7rem 1rem;text-decoration:none}button:hover,.button:hover{background:var(--tomos-primary-hover);border-color:var(--tomos-primary-hover)}button:active,.button:active{background:var(--tomos-primary-active);border-color:var(--tomos-primary-active)}button:focus-visible,.button:focus-visible,input:focus-visible{outline:3px solid rgba(164,74,29,0.28);outline-offset:2px}.button.secondary{background:var(--tomos-input);border-color:var(--tomos-border);color:var(--tomos-text)}.button.secondary:hover{background:var(--tomos-button-hover);border-color:var(--tomos-border-hover)}.button.secondary:active{background:var(--tomos-button-active)}
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

    echo '<form method="post" action="">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<h2>サイト情報</h2>';
    echo '<label for="site_name">サイト名</label>';
    echo '<input id="site_name" type="text" name="site_name" value="' . e((string) $form['site_name']) . '" maxlength="100" required>';
    echo '<label for="site_description">サイト説明（任意）</label>';
    echo '<input id="site_description" type="text" name="site_description" value="' . e((string) $form['site_description']) . '" maxlength="200">';
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

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
