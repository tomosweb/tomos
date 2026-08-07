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

if (empty($_SESSION['tomos_post_theme_upload_token'])) {
    $_SESSION['tomos_post_theme_upload_token'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['tomos_post_theme_upload_owner'])) {
    $_SESSION['tomos_post_theme_upload_owner'] = bin2hex(random_bytes(32));
}

$themesDir = (string) (($config['paths']['theme_dir'] ?? '') ?: ($rootDir . DIRECTORY_SEPARATOR . 'themes'));
$installer = new Tomos\ThemePackageInstaller($rootDir, $themesDir);
$installer->cleanupStaleTemporaryFiles();
$discardFailed = false;
$currentPackage = (string) ($_SESSION['tomos_post_theme_upload_package'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $currentPackage !== '') {
    $discardFailed = !$installer->discard($currentPackage);
    unset($_SESSION['tomos_post_theme_upload_package']);
}
$diagnosticErrors = $installer->diagnostics();
$errors = $diagnosticErrors;
if ($discardFailed) {
    $errors[] = '一時ファイルを削除できませんでした。サーバーの保存領域を確認してください。';
}
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_token'] ?? '');
    $sessionToken = (string) ($_SESSION['tomos_post_theme_upload_token'] ?? '');
    if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'テーマZIPの容量がサーバーの上限を超えています。より小さいZIPを選択してください。';
    } elseif ($token === '' || !hash_equals($sessionToken, $token)) {
        $errors[] = 'フォームの有効期限が切れました。もう一度送信してください。';
    } else {
        $previous = (string) ($_SESSION['tomos_post_theme_upload_package'] ?? '');
        if ($previous !== '' && !$installer->discard($previous)) {
            $errors[] = '一時ファイルを削除できませんでした。サーバーの保存領域を確認してください。';
        }
        if ($previous !== '') {
            unset($_SESSION['tomos_post_theme_upload_package']);
        }
        if ($errors === []) {
            try {
                $summary = $installer->stageUpload(
                    is_array($_FILES['theme_zip'] ?? null) ? $_FILES['theme_zip'] : [],
                    (string) $_SESSION['tomos_post_theme_upload_owner']
                );
                $_SESSION['tomos_post_theme_upload_package'] = (string) $summary['package_id'];
                $_SESSION['tomos_post_theme_upload_token'] = bin2hex(random_bytes(32));
            } catch (Tomos\ThemePackageException $exception) {
                $errors[] = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('[Tomos theme upload] endpoint stage=unexpected');
                $errors[] = 'テーマZIPを確認できませんでした。もう一度選択してください。';
            }
        }
    }
}

renderThemeAddPage(
    $config,
    $errors,
    $summary,
    (string) $_SESSION['tomos_post_theme_upload_token'],
    $installer->uploadLimit(),
    $diagnosticErrors === []
);

function renderThemeAddPage(array $config, array $errors, ?array $summary, string $token, array $limit, bool $canUpload): void
{
    $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    $themeUrl = Tomos\Security::publicUrl('/post/theme/', $publicBasePath);
    $addUrl = Tomos\Security::publicUrl('/post/theme/add/', $publicBasePath);
    $confirmUrl = Tomos\Security::publicUrl('/post/theme/add/confirm/', $publicBasePath);

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<link rel="icon" href="../../../themes/tomos-minimal/assets/favicon.png" type="image/png">';
    echo '<link rel="apple-touch-icon" href="../../../themes/tomos-minimal/assets/apple-touch-icon.png">';
    echo '<title>テーマZIPを追加</title>';
    echo '<style>
:root{--bg:#f6f4ef;--surface:#fcfbf8;--input:#fff;--text:#2f2f2f;--muted:#6b6b6b;--border:#d9d6cf;--primary:#9a431c;--primary-hover:#853919;--notice:#fbf4e8;--notice-border:#e5c998;--error:#f8ecea;--error-border:#d9a39e;--info:#f7f7f4}
html,body{width:100%;overflow-x:hidden}body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6;margin:0;padding:32px 16px}.wrap{background:var(--surface);border:1px solid var(--border);border-radius:10px;box-sizing:border-box;margin:0 auto;max-width:760px;padding:28px}h1{font-size:1.8rem;margin:0 0 .5rem}h2{font-size:1.2rem;margin:1.7rem 0 .7rem}.hint{color:var(--muted);font-size:.95rem}.errors{background:var(--error);border:1px solid var(--error-border);border-radius:6px;color:#8a2e26;padding:1rem}.summary,.notice{border:1px solid var(--notice-border);border-radius:6px;padding:1rem}.summary{background:var(--info);border-color:#e2e1dd}.notice{background:var(--notice)}input[type=file]{background:var(--input);border:1px solid var(--border);border-radius:6px;box-sizing:border-box;font:inherit;max-width:100%;padding:.65rem;width:100%}button,.button{background:var(--primary);border:1px solid var(--primary);border-radius:6px;color:#fff;display:inline-block;font:inherit;font-weight:700;padding:.7rem 1rem;text-decoration:none}button:hover{background:var(--primary-hover)}.button.secondary{background:var(--input);color:var(--text);border-color:var(--border)}.actions{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1.5rem}code{background:#f1f1ee;border-radius:4px;padding:.1rem .25rem;overflow-wrap:anywhere}.meta{display:grid;gap:.5rem}.meta p{margin:0}
</style></head><body><main class="wrap">';
    echo '<h1>テーマZIPを追加</h1>';

    if ($errors !== []) {
        echo '<div class="errors"><strong>処理できませんでした。</strong><ul>';
        foreach ($errors as $error) {
            echo '<li>' . e((string) $error) . '</li>';
        }
        echo '</ul></div>';
    }

    if (is_array($summary)) {
        echo '<p>テーマZIPの検査が完了しました。この時点ではテーマはまだ追加されていません。</p>';
        echo '<div class="summary meta">';
        echo '<p><strong>表示名:</strong> ' . e((string) $summary['display_name']) . '</p>';
        echo '<p><strong>テーマID:</strong> <code>' . e((string) $summary['theme_id']) . '</code></p>';
        echo '<p><strong>version:</strong> ' . e((string) $summary['version']) . '</p>';
        echo '<p><strong>ファイル数:</strong> ' . e((string) $summary['file_count']) . '</p>';
        echo '<p><strong>展開後容量:</strong> ' . e(formatBytes((int) $summary['expanded_bytes'])) . '</p>';
        echo '</div>';
        if (!empty($summary['warnings']) && is_array($summary['warnings'])) {
            echo '<div class="notice"><strong>注意</strong><ul>';
            foreach ($summary['warnings'] as $warning) {
                echo '<li>' . e((string) $warning) . '</li>';
            }
            echo '</ul></div>';
        }
        echo '<form method="post" action="' . e($confirmUrl) . '">';
        echo '<input type="hidden" name="_token" value="' . e($token) . '">';
        echo '<div class="actions"><button type="submit">このテーマを追加する</button><a class="button secondary" href="' . e($addUrl) . '">選び直す</a></div></form>';
    } else {
        echo '<p>公式サイト等から取得したテーマZIPを選択します。検査後の確認画面で確定するまで、テーマは追加されません。</p>';
        echo '<div class="notice"><ul>';
        echo '<li>テーマZIPの上限：最大10 MB（実際の上限はサーバー設定により小さくなる場合があります）</li><li>新しいテーマIDだけを追加できます。</li><li>同じテーマIDは追加できず、既存テーマは上書きされません。</li><li>ZIP検査後に確定操作が必要です。</li>';
        echo '</ul></div>';
        if (!empty($limit['below_tomos_limit'])) {
            echo '<p class="hint">このサーバーでアップロードできる上限は約 ' . e(formatBytes((int) $limit['bytes'])) . ' です。</p>';
        } elseif (empty($limit['settings_known'])) {
            echo '<p class="hint">TomosテーマZIPの上限は10 MBです。サーバー側の上限により、それより小さくなる場合があります。</p>';
        }
        if ($canUpload) {
            echo '<form method="post" action="' . e($addUrl) . '" enctype="multipart/form-data">';
            echo '<input type="hidden" name="_token" value="' . e($token) . '">';
            echo '<input type="hidden" name="MAX_FILE_SIZE" value="' . e((string) Tomos\ThemePackagePolicy::MAX_ZIP_BYTES) . '">';
            echo '<label for="theme_zip"><strong>テーマZIP</strong></label><input id="theme_zip" name="theme_zip" type="file" accept=".zip,application/zip" required>';
            echo '<div class="actions"><button type="submit">アップロードして確認</button><a class="button secondary" href="' . e($themeUrl) . '">テーマ選択へ戻る</a></div></form>';
        }
    }
    echo '</main></body></html>';
}

function formatBytes(int $bytes): string
{
    return number_format($bytes / 1048576, 2) . ' MB';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
