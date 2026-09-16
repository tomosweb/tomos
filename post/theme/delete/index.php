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
$authRemember = new Tomos\PostAuthRememberToken($config, $rootDir);
if ($config === [] || !$authRemember->restoreSession()) {
    header('Location: ' . Tomos\Security::publicUrl('/post/', $publicBasePath));
    exit;
}

if (empty($_SESSION['tomos_post_theme_token'])) {
    $_SESSION['tomos_post_theme_token'] = bin2hex(random_bytes(32));
}

$themesDir = (string) (($config['paths']['theme_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'themes'));
$repository = new Tomos\ThemeRepository($themesDir);
$themes = $repository->all();
$currentTheme = (string) ($config['theme']['name'] ?? 'tomos-minimal');
$selectedTheme = (string) ($_POST['delete_theme'] ?? '');
$action = (string) ($_POST['action'] ?? 'confirm');
$token = (string) ($_POST['_token'] ?? '');
$sessionToken = (string) ($_SESSION['tomos_post_theme_token'] ?? '');
$errors = [];
$messages = [];
$warnings = [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $errors[] = 'テーマ一覧から削除するテーマを選んでください。';
} elseif ($token === '' || !hash_equals($sessionToken, $token)) {
    $errors[] = 'フォームの有効期限が切れました。もう一度やり直してください。';
}

$selectedInfo = is_array($themes[$selectedTheme] ?? null) ? $themes[$selectedTheme] : null;
if ($errors === []) {
    if (!Tomos\ThemePackagePolicy::isThemeId($selectedTheme)) {
        $errors[] = 'テーマIDが正しくありません。';
    } elseif (!is_array($selectedInfo)) {
        $errors[] = '削除するテーマが見つかりません。';
    } elseif (Tomos\ThemePackageDeployment::isBundledTheme($selectedTheme)) {
        $errors[] = 'Tomos標準テーマは削除できません。';
    } elseif ($selectedTheme === $currentTheme) {
        $errors[] = '使用中のテーマは削除できません。先に別のテーマへ切り替えてください。';
    }
}

if ($errors === [] && $action === 'delete') {
    $owner = 'theme-delete:' . session_id();
    $deployment = new Tomos\ThemePackageDeployment($rootDir, $themesDir, $owner);
    try {
        $result = $deployment->delete($selectedTheme, $owner);
        $_SESSION['tomos_post_theme_token'] = bin2hex(random_bytes(32));
        $messages[] = 'テーマを削除しました。';
        if (!empty($result['cleanup_warning'])) {
            $warnings[] = 'テーマは一覧から削除されましたが、退避したファイルの削除が完了していません。次回のテーマ管理時に再度クリーンアップされます。';
        }
    } catch (Tomos\ThemePackageException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('[Tomos theme delete] endpoint stage=unexpected theme=' . $selectedTheme);
        $errors[] = 'テーマを削除できませんでした。もう一度お試しください。';
    }
}

renderDeletePage($config, $selectedInfo, $selectedTheme, $errors, $messages, $warnings, (string) $_SESSION['tomos_post_theme_token']);

function renderDeletePage(array $config, ?array $theme, string $themeId, array $errors, array $messages, array $warnings, string $token): void
{
    $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    $themeUrl = Tomos\Security::publicUrl('/post/theme/', $publicBasePath);
    $deleteUrl = Tomos\Security::publicUrl('/post/theme/delete/', $publicBasePath);
    $label = themeLabel($theme, $themeId);

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>テーマ削除確認</title>';
    echo '<style>body{background:#f6f4ef;color:#2f2f2f;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6;margin:0;padding:32px 16px}.wrap{background:#fcfbf8;border:1px solid #d9d6cf;border-radius:10px;box-sizing:border-box;margin:0 auto;max-width:720px;padding:28px}.summary{background:#f7f7f4;border:1px solid #e2e1dd;border-radius:6px;padding:1rem}.errors{background:#f8ecea;border:1px solid #d9a39e;border-radius:6px;color:#8a2e26;padding:1rem}.success,.notice{background:#fbf4e8;border:1px solid #e5c998;border-radius:6px;padding:1rem}.button,button{background:#9a431c;border:1px solid #9a431c;border-radius:6px;color:#fff;display:inline-block;font:inherit;font-size:16px;font-weight:700;padding:.7rem 1rem;text-decoration:none}.button.secondary{background:#fff;color:#2f2f2f;border-color:#d9d6cf}.danger{background:#8a2e26;border-color:#8a2e26}.actions{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1.5rem}code{background:#f1f1ee;border-radius:4px;padding:.1rem .25rem}</style></head><body><main class="wrap"><h1>テーマ削除確認</h1>';

    if ($errors !== []) {
        echo '<div class="errors"><strong>削除できませんでした。</strong><ul>';
        foreach ($errors as $error) {
            echo '<li>' . e((string) $error) . '</li>';
        }
        echo '</ul></div><div class="actions"><a class="button secondary" href="' . e($themeUrl) . '">テーマ一覧へ戻る</a></div></main></body></html>';
        return;
    }

    if ($messages !== []) {
        echo '<div class="success"><strong>' . e((string) $messages[0]) . '</strong></div>';
        foreach ($warnings as $warning) {
            echo '<div class="notice">' . e((string) $warning) . '</div>';
        }
        echo '<div class="actions"><a class="button" href="' . e($themeUrl) . '">テーマ一覧へ戻る</a></div></main></body></html>';
        return;
    }

    echo '<p>次の追加テーマを削除します。元に戻す操作はありません。</p>';
    echo '<div class="summary"><p><strong>テーマ:</strong> ' . e($label) . '</p><p><strong>テーマID:</strong> <code>' . e($themeId) . '</code></p></div>';
    echo '<form method="post" action="' . e($deleteUrl) . '">';
    echo '<input type="hidden" name="action" value="delete">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<input type="hidden" name="delete_theme" value="' . e($themeId) . '">';
    echo '<div class="actions"><button class="danger" type="submit">削除する</button><a class="button secondary" href="' . e($themeUrl) . '">戻る</a></div>';
    echo '</form></main></body></html>';
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
