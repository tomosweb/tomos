<?php

declare(strict_types=1);

session_start();

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = dirname(__DIR__, 4) . '/core/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$rootDir = dirname(__DIR__, 4);
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

$themesDir = (string) (($config['paths']['theme_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'themes'));
$installer = new Tomos\ThemePackageInstaller($rootDir, $themesDir);
$installer->cleanupStaleTemporaryFiles();
$errors = [];
$result = null;
$packageId = (string) ($_SESSION['tomos_post_theme_upload_package'] ?? '');
$owner = (string) ($_SESSION['tomos_post_theme_upload_owner'] ?? '');
$token = (string) ($_POST['_token'] ?? '');
$sessionToken = (string) ($_SESSION['tomos_post_theme_upload_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $errors[] = 'テーマZIPをアップロードしてから確定してください。';
} elseif ($token === '' || !hash_equals($sessionToken, $token)) {
    if ($packageId !== '') {
        $installer->discard($packageId);
    }
    unset($_SESSION['tomos_post_theme_upload_package']);
    $errors[] = 'フォームの有効期限が切れました。テーマZIPを選び直してください。';
} elseif ($packageId === '' || $owner === '') {
    if ($packageId !== '') {
        $installer->discard($packageId);
        unset($_SESSION['tomos_post_theme_upload_package']);
    }
    $errors[] = '確認内容の有効期限が切れました。テーマZIPを選び直してください。';
} else {
    $_SESSION['tomos_post_theme_upload_token'] = bin2hex(random_bytes(32));
    unset($_SESSION['tomos_post_theme_upload_package']);
    try {
        $result = $installer->apply($packageId, $owner);
    } catch (Tomos\ThemePackageException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('[Tomos theme upload] endpoint stage=apply-unexpected');
        $errors[] = 'テーマを追加できませんでした。もう一度お試しください。';
    }
}

renderThemeAddResult($config, $errors, $result);

function renderThemeAddResult(array $config, array $errors, ?array $result): void
{
    $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    $themeUrl = Tomos\Security::publicUrl('/post/theme/', $publicBasePath);
    $addUrl = Tomos\Security::publicUrl('/post/theme/add/', $publicBasePath);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>テーマ追加結果</title>';
    echo '<style>body{background:#f6f4ef;color:#2f2f2f;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6;margin:0;padding:32px 16px}.wrap{background:#fcfbf8;border:1px solid #d9d6cf;border-radius:10px;box-sizing:border-box;margin:0 auto;max-width:720px;padding:28px}.errors{background:#f8ecea;border:1px solid #d9a39e;border-radius:6px;color:#8a2e26;padding:1rem}.success,.notice{background:#fbf4e8;border:1px solid #e5c998;border-radius:6px;padding:1rem}.button{background:#9a431c;border:1px solid #9a431c;border-radius:6px;color:#fff;display:inline-block;font-weight:700;padding:.7rem 1rem;text-decoration:none}.button.secondary{background:#fff;color:#2f2f2f;border-color:#d9d6cf}.actions{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1.5rem}code{background:#f1f1ee;border-radius:4px;padding:.1rem .25rem}</style></head><body><main class="wrap"><h1>テーマ追加結果</h1>';
    if ($errors !== []) {
        echo '<div class="errors"><strong>テーマを追加できませんでした。</strong><ul>';
        foreach ($errors as $error) {
            echo '<li>' . e((string) $error) . '</li>';
        }
        echo '</ul></div><div class="actions"><a class="button secondary" href="' . e($addUrl) . '">テーマZIPを選び直す</a><a class="button secondary" href="' . e($themeUrl) . '">テーマ選択へ戻る</a></div>';
    } elseif (is_array($result)) {
        echo '<div class="success"><strong>テーマを追加しました。</strong><ul><li>表示名: ' . e((string) $result['display_name']) . '</li><li>テーマID: <code>' . e((string) $result['theme_id']) . '</code></li><li>version: ' . e((string) $result['version']) . '</li></ul></div>';
        if (!empty($result['cleanup_warning'])) {
            echo '<div class="notice">テーマは追加されましたが、一時ファイルを削除できませんでした。サーバーの保存領域を確認してください。</div>';
        }
        echo '<p>追加したテーマはまだ有効になっていません。テーマ一覧から選択し、既存の確認画面で切り替えてください。</p>';
        echo '<div class="actions"><a class="button" href="' . e($themeUrl) . '">テーマ一覧へ戻る</a></div>';
    }
    echo '</main></body></html>';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
