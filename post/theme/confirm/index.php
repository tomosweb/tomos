<?php

declare(strict_types=1);

session_start();

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = dirname(__DIR__, 3) . '/core/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$rootDir = dirname(__DIR__, 3);
$configPath = $rootDir . '/config.php';
$config = [];
if (is_file($configPath)) {
    $loadedConfig = require $configPath;
    $config = is_array($loadedConfig) ? $loadedConfig : [];
}

$publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
if ($config === [] || empty($_SESSION['tomos_post_authenticated'])) {
    header('Location: ' . Tomos\Security::publicUrl('/post/', $publicBasePath));
    exit;
}

if (empty($_SESSION['tomos_post_theme_token'])) {
    $_SESSION['tomos_post_theme_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$messages = [];
$warnings = [];
$selectedTheme = (string) ($_POST['theme_name'] ?? '');
$action = (string) ($_POST['action'] ?? 'confirm');
$token = (string) ($_POST['_token'] ?? '');
$sessionToken = (string) ($_SESSION['tomos_post_theme_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $errors[] = 'テーマを選んでから確認へ進んでください。';
} elseif ($token === '' || !hash_equals($sessionToken, $token)) {
    $errors[] = 'フォームの有効期限が切れました。もう一度送信してください。';
}

$themesDir = (string) (($config['paths']['theme_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'themes'));
$repository = new Tomos\ThemeRepository($themesDir);
$themes = $repository->all();
$currentTheme = (string) ($config['theme']['name'] ?? 'tomos-minimal');
$selectedInfo = is_array($themes[$selectedTheme] ?? null) ? $themes[$selectedTheme] : null;

if ($errors === []) {
    if (!preg_match('/\A[A-Za-z0-9_-]+\z/', $selectedTheme)) {
        $errors[] = 'テーマ名が正しくありません。';
    } elseif (!is_array($selectedInfo) || empty($selectedInfo['valid'])) {
        $errors[] = '指定されたテーマは利用できません。';
    }
}

if ($errors === [] && $action === 'apply') {
    [$newConfig, $updateErrors] = Tomos\ThemeConfigWriter::updateTheme($config, $selectedTheme, $rootDir);
    if ($updateErrors !== []) {
        $errors = array_merge($errors, $updateErrors);
    } elseif (!Tomos\ConfigWriter::write($configPath, $newConfig, $rootDir)) {
        $errors[] = 'config.php を更新できませんでした。';
    } else {
        $config = $newConfig;
        $currentTheme = $selectedTheme;
        $cache = new Tomos\HtmlCache((string) ($config['paths']['cache_dir'] ?? ($rootDir . DIRECTORY_SEPARATOR . 'cache')), true);
        if (!$cache->clearGenerated()) {
            $warnings[] = 'HTMLキャッシュを削除できませんでした。表示が古い場合は cache/html/ を確認してください。';
        }
        $_SESSION['tomos_post_theme_token'] = bin2hex(random_bytes(32));
        $messages[] = 'テーマを変更しました。';
    }
}

renderConfirmPage($config, $themes, $currentTheme, $selectedTheme, $errors, $messages, $warnings, (string) $_SESSION['tomos_post_theme_token']);

function renderConfirmPage(array $config, array $themes, string $currentTheme, string $selectedTheme, array $errors, array $messages, array $warnings, string $token): void
{
    $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    $postUrl = Tomos\Security::publicUrl('/post/', $publicBasePath);
    $themeUrl = Tomos\Security::publicUrl('/post/theme/', $publicBasePath);
    $siteUrl = Tomos\Security::publicUrl('/', $publicBasePath);
    $confirmUrl = Tomos\Security::publicUrl('/post/theme/confirm/', $publicBasePath);
    $selectedLabel = themeLabel($themes[$selectedTheme] ?? null, $selectedTheme);
    $currentLabel = themeLabel($themes[$currentTheme] ?? null, $currentTheme);

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<link rel="icon" href="../../../themes/tomos-minimal/assets/favicon.png" type="image/png">';
    echo '<link rel="apple-touch-icon" href="../../../themes/tomos-minimal/assets/apple-touch-icon.png">';
    echo '<title>テーマ変更確認</title>';
    echo '<style>
:root{--tomos-bg:#f6f4ef;--tomos-surface:#fcfbf8;--tomos-input:#fff;--tomos-text:#2f2f2f;--tomos-muted:#6b6b6b;--tomos-border:#d9d6cf;--tomos-border-hover:#cfcbc3;--tomos-primary:#9a431c;--tomos-primary-hover:#853919;--tomos-primary-active:#713018;--tomos-notice-bg:#fbf4e8;--tomos-notice-text:#6f4b1d;--tomos-notice-border:#e5c998;--tomos-error-bg:#f8ecea;--tomos-error-border:#d9a39e;--tomos-danger-text:#8a2e26;--tomos-info-bg:#f7f7f4;--tomos-code-bg:#f1f1ee;--tomos-code-text:#555;--tomos-button-hover:#f7f5f0;--tomos-button-active:#efece6;--tomos-shadow:0 1px 2px rgba(47,47,47,0.04)}
html,body{width:100%;overflow-x:hidden}
body{background:var(--tomos-bg);color:var(--tomos-text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6;margin:0;padding:32px 16px}
.wrap{background:var(--tomos-surface);border:1px solid var(--tomos-border);border-radius:10px;box-shadow:var(--tomos-shadow);box-sizing:border-box;margin:0 auto;max-width:760px;padding:28px}
	h1{color:var(--tomos-text);font-size:1.8rem;margin:0 0 0.5rem}.hint{color:var(--tomos-muted);font-size:0.95rem}.theme-change-summary{background:var(--tomos-info-bg);border:1px solid #e2e1dd;border-radius:6px;color:var(--tomos-text);display:grid;gap:0.85rem;padding:1rem}.theme-change-summary p{margin:0}.theme-change-summary strong{display:block;margin-bottom:0.2rem}.errors{background:var(--tomos-error-bg);border:1px solid var(--tomos-error-border);border-radius:6px;color:var(--tomos-danger-text);padding:1rem}.success{background:var(--tomos-notice-bg);border:1px solid var(--tomos-notice-border);border-radius:6px;color:var(--tomos-notice-text);padding:1rem}.notice{background:var(--tomos-notice-bg);border:1px solid var(--tomos-notice-border);border-radius:6px;color:var(--tomos-notice-text);padding:1rem}
button,.button{background:var(--tomos-primary);border:1px solid var(--tomos-primary);border-radius:6px;color:#fff;display:inline-block;font:inherit;font-size:16px;font-weight:700;padding:0.7rem 1rem;text-decoration:none}button:hover,.button:hover{background:var(--tomos-primary-hover);border-color:var(--tomos-primary-hover)}button:active,.button:active{background:var(--tomos-primary-active);border-color:var(--tomos-primary-active)}button:focus-visible,.button:focus-visible{outline:3px solid rgba(164,74,29,0.28);outline-offset:2px}.button.secondary{background:var(--tomos-input);color:var(--tomos-text);border-color:var(--tomos-border)}.button.secondary:hover{background:var(--tomos-button-hover);border-color:var(--tomos-border-hover)}.button.secondary:active{background:var(--tomos-button-active)}.actions{display:flex;flex-wrap:wrap;gap:0.6rem;margin-top:1.5rem}code{background:var(--tomos-code-bg);border-radius:4px;color:var(--tomos-code-text);padding:0.1rem 0.25rem;overflow-wrap:anywhere;word-break:break-word}
</style></head><body><main class="wrap">';

    echo '<h1>テーマ変更確認</h1>';

    if ($errors !== []) {
        echo '<div class="errors"><strong>処理できませんでした。</strong><ul>';
        foreach ($errors as $error) {
            echo '<li>' . e((string) $error) . '</li>';
        }
        echo '</ul></div>';
        echo '<p><a class="button secondary" href="' . e($themeUrl) . '">テーマ選択へ戻る</a></p>';
        echo '</main></body></html>';
        return;
    }

    if ($messages !== []) {
        echo '<div class="success"><ul>';
        foreach ($messages as $message) {
            echo '<li>' . e((string) $message) . '</li>';
        }
        echo '</ul></div>';
        if ($warnings !== []) {
            echo '<div class="notice"><strong>注意</strong><ul>';
            foreach ($warnings as $warning) {
                echo '<li>' . e((string) $warning) . '</li>';
            }
            echo '</ul></div>';
        }
        echo themeChangeSummaryHtml($currentLabel, null);
        echo '<div class="actions"><a class="button" href="' . e($siteUrl) . '">公開サイトを開く</a><a class="button secondary" href="' . e($postUrl) . '">Tomos Postへ戻る</a></div>';
        echo '</main></body></html>';
        return;
    }

    echo '<p>テーマを変更しますか？</p>';
    echo themeChangeSummaryHtml($currentLabel, $selectedLabel);
    echo '<form method="post" action="' . e($confirmUrl) . '">';
    echo '<input type="hidden" name="action" value="apply">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<input type="hidden" name="theme_name" value="' . e($selectedTheme) . '">';
    echo '<div class="actions"><button type="submit">変更する</button><a class="button secondary" href="' . e($themeUrl) . '">戻る</a></div>';
    echo '</form>';
    echo '</main></body></html>';
}

function themeLabel(?array $theme, string $fallback): string
{
    return $fallback;
}

function themeChangeSummaryHtml(string $currentLabel, ?string $nextLabel): string
{
    $html = '<div class="theme-change-summary">';
    $html .= '<p><strong>現在のテーマ:</strong><code>' . e($currentLabel) . '</code></p>';
    if ($nextLabel !== null) {
        $html .= '<p><strong>変更後:</strong><code>' . e($nextLabel) . '</code></p>';
    }
    $html .= '</div>';

    return $html;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
