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
    header('Location: ' . Tomos\Security::publicUrl('/post/', $publicBasePath));
    exit;
}

if (empty($_SESSION['tomos_post_theme_token'])) {
    $_SESSION['tomos_post_theme_token'] = bin2hex(random_bytes(32));
}

$themesDir = (string) (($config['paths']['theme_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'themes'));
$themePackageInstaller = new Tomos\ThemePackageInstaller($rootDir, $themesDir);
$themePackageInstaller->cleanupStaleTemporaryFiles();
$repository = new Tomos\ThemeRepository($themesDir);
$themes = $repository->all();
$currentTheme = (string) ($config['theme']['name'] ?? 'tomos-minimal');
$currentLabel = themeLabel($themes[$currentTheme] ?? null, $currentTheme);

renderThemePage($config, $themes, $currentTheme, $currentLabel, (string) $_SESSION['tomos_post_theme_token']);

function renderThemePage(array $config, array $themes, string $currentTheme, string $currentLabel, string $token): void
{
    $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    $postUrl = Tomos\Security::publicUrl('/post/', $publicBasePath) . '?section=settings';
    $confirmUrl = Tomos\Security::publicUrl('/post/theme/confirm/', $publicBasePath);
    $addUrl = Tomos\Security::publicUrl('/post/theme/add/', $publicBasePath);

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<link rel="icon" href="../../themes/tomos-minimal/assets/favicon.png" type="image/png">';
    echo '<link rel="apple-touch-icon" href="../../themes/tomos-minimal/assets/apple-touch-icon.png">';
    echo '<title>テーマを切り替える</title>';
    echo '<style>
:root{--tomos-bg:#f6f4ef;--tomos-surface:#fcfbf8;--tomos-input:#fff;--tomos-text:#2f2f2f;--tomos-muted:#6b6b6b;--tomos-border:#d9d6cf;--tomos-border-soft:#e7e3dc;--tomos-border-hover:#cfcbc3;--tomos-accent:#a44a1d;--tomos-primary:#9a431c;--tomos-primary-hover:#853919;--tomos-primary-active:#713018;--tomos-notice-bg:#fbf4e8;--tomos-notice-text:#6f4b1d;--tomos-notice-border:#e5c998;--tomos-error-bg:#f8ecea;--tomos-info-bg:#f7f7f4;--tomos-code-bg:#f1f1ee;--tomos-code-text:#555;--tomos-button-hover:#f7f5f0;--tomos-button-active:#efece6;--tomos-shadow:0 1px 2px rgba(47,47,47,0.04)}
html,body{width:100%;overflow-x:hidden}
body{background:var(--tomos-bg);color:var(--tomos-text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6;margin:0;padding:32px 16px}
.wrap{background:var(--tomos-surface);border:1px solid var(--tomos-border);border-radius:10px;box-shadow:var(--tomos-shadow);box-sizing:border-box;margin:0 auto;max-width:860px;padding:28px}
h1{color:var(--tomos-text);font-size:1.8rem;margin:0 0 0.5rem}h2{border-top:1px solid var(--tomos-border-soft);color:var(--tomos-text);font-size:1.2rem;margin:2rem 0 1rem;padding-top:1.5rem}
.hint{color:var(--tomos-muted);font-size:0.95rem}.notice{background:var(--tomos-notice-bg);border:1px solid var(--tomos-notice-border);border-radius:6px;color:var(--tomos-notice-text);padding:1rem}.result{background:var(--tomos-info-bg);border:1px solid #e2e1dd;border-radius:6px;color:var(--tomos-text);padding:1rem}
.theme{background:var(--tomos-input);border:1px solid var(--tomos-border);border-radius:8px;margin:0.75rem 0;padding:1rem}.theme.current{border-color:var(--tomos-accent);background:var(--tomos-button-hover)}.theme.invalid{background:#f7f7f4;color:var(--tomos-muted)}
label{color:var(--tomos-text);display:block;font-weight:700}input[type=radio]{accent-color:var(--tomos-accent);margin-right:0.45rem}button,.button{background:var(--tomos-primary);border:1px solid var(--tomos-primary);border-radius:6px;color:#fff;display:inline-block;font:inherit;font-size:16px;font-weight:700;padding:0.7rem 1rem;text-decoration:none}button:hover,.button:hover{background:var(--tomos-primary-hover);border-color:var(--tomos-primary-hover)}button:active,.button:active{background:var(--tomos-primary-active);border-color:var(--tomos-primary-active)}button:focus-visible,.button:focus-visible,input[type=radio]:focus-visible{outline:3px solid rgba(164,74,29,0.28);outline-offset:2px}.button.secondary{background:var(--tomos-input);color:var(--tomos-text);border-color:var(--tomos-border)}.button.secondary:hover{background:var(--tomos-button-hover);border-color:var(--tomos-border-hover)}.button.secondary:active{background:var(--tomos-button-active)}.actions{display:flex;flex-wrap:wrap;gap:0.6rem;margin-top:1.5rem}code{background:var(--tomos-code-bg);border-radius:4px;color:var(--tomos-code-text);padding:0.1rem 0.25rem;overflow-wrap:anywhere;word-break:break-word}
</style></head><body><main class="wrap">';

    echo '<h1>テーマを切り替える</h1>';
    echo '<p class="hint">登録済みのテーマから、サイトの見た目を選びます。</p>';
    echo '<div class="result"><strong>現在のテーマ:</strong><br><code>' . e($currentLabel) . '</code></div>';
    echo '<div class="actions"><a class="button secondary" href="' . e($addUrl) . '">テーマZIPを追加</a></div>';

    echo '<h2>利用できるテーマ</h2>';
    echo '<form method="post" action="' . e($confirmUrl) . '">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';

    $hasValid = false;
    foreach ($themes as $directory => $theme) {
        $valid = !empty($theme['valid']);
        if ($valid) {
            $hasValid = true;
        }
        $id = (string) $directory;
        $label = themeLabel($theme, $id);
        $classes = 'theme' . ($id === $currentTheme ? ' current' : '') . (!$valid ? ' invalid' : '');
        echo '<div class="' . e($classes) . '">';
        if ($valid) {
            echo '<label><input type="radio" name="theme_name" value="' . e($id) . '"' . ($id === $currentTheme ? ' checked' : '') . '> ' . e($label) . '</label>';
        } else {
            echo '<strong>' . e($label) . '</strong> <span class="hint">（選択できません）</span>';
        }
        echo '<p class="hint">ディレクトリ: <code>' . e($id) . '</code> / version ' . e((string) ($theme['version'] ?? '')) . '</p>';
        if ((string) ($theme['description'] ?? '') !== '') {
            echo '<p>' . e((string) $theme['description']) . '</p>';
        }
        if ((string) ($theme['author'] ?? '') !== '') {
            echo '<p class="hint">作者: ' . e((string) $theme['author']) . '</p>';
        }
        if (!empty($theme['warnings'])) {
            echo '<div class="notice"><strong>注意</strong><ul>';
            foreach ($theme['warnings'] as $warning) {
                echo '<li>' . e((string) $warning) . '</li>';
            }
            echo '</ul></div>';
        }
        if (!$valid && !empty($theme['errors'])) {
            echo '<ul>';
            foreach ($theme['errors'] as $error) {
                echo '<li>' . e((string) $error) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    if (!$hasValid) {
        echo '<div class="notice">利用できるテーマがありません。themes/ フォルダに有効なテーマを配置してください。</div>';
    } else {
        echo '<div class="actions"><button type="submit">確認へ進む</button><a class="button secondary" href="' . e($postUrl) . '">Tomos Postへ戻る</a></div>';
    }
    echo '</form>';
    echo '</main></body></html>';
}

function themeLabel(?array $theme, string $fallback): string
{
    $displayName = trim((string) ($theme['display_name'] ?? ''));

    return $displayName !== '' ? $displayName : $fallback;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
