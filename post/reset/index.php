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
$enablePath = $rootDir . '/post-reset.enable';
$configPath = $rootDir . '/config.php';

if (!is_file($enablePath)) {
    renderDisabled();
    exit;
}

$config = [];
if (is_file($configPath)) {
    $loadedConfig = require $configPath;
    $config = is_array($loadedConfig) ? $loadedConfig : [];
}

if ($config === []) {
    renderPage('Tomos Post 合言葉の再発行', [], ['config.php が見つかりません。先にsetupを完了してください。'], '', true);
    exit;
}

if (empty($_SESSION['tomos_post_reset_token'])) {
    $_SESSION['tomos_post_reset_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$newPassword = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rateLimiter = new Tomos\PostRateLimiter($config, $rootDir, clientIp());
    $limit = $rateLimiter->checkResetAllowed();
    if (!$limit->allowed) {
        $errors[] = $limit->message;
    } else {
        $rateLimiter->recordResetAttempt();
        $token = (string) ($_POST['_token'] ?? '');
        if (!hash_equals((string) $_SESSION['tomos_post_reset_token'], $token)) {
            $errors[] = 'フォームの有効期限が切れました。もう一度送信してください。';
        } else {
            $newPassword = Tomos\PostPassword::generate();
            $newConfig = $config;
            if (!isset($newConfig['features']) || !is_array($newConfig['features'])) {
                $newConfig['features'] = [];
            }
            if (!isset($newConfig['security']) || !is_array($newConfig['security'])) {
                $newConfig['security'] = [];
            }
            $newConfig['features']['post'] = true;
            $newConfig['security']['post_password_hash'] = Tomos\PostPassword::hash($newPassword);

            if (Tomos\ConfigWriter::write($configPath, $newConfig, $rootDir)) {
                $config = $newConfig;
                (new Tomos\PostAuthRememberToken($config, $rootDir))->invalidateAll();
                $_SESSION['tomos_post_reset_token'] = bin2hex(random_bytes(32));
            } else {
                $newPassword = '';
                $errors[] = 'config.php を更新できませんでした。ファイルまたは設置ディレクトリの書き込み権限を確認してください。';
            }
        }
    }
}

renderPage('Tomos Post 合言葉の再発行', $config, $errors, $newPassword, false);

function renderDisabled(): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<link rel="icon" href="../../themes/tomos-minimal/assets/favicon.png" type="image/png">';
    echo '<link rel="apple-touch-icon" href="../../themes/tomos-minimal/assets/apple-touch-icon.png">';
    echo '<title>Tomos Post 合言葉の再発行は無効です</title>';
    echo '<style>:root{--tomos-bg:#f6f4ef;--tomos-surface:#fcfbf8;--tomos-text:#2f2f2f;--tomos-border:#d9d6cf;--tomos-code-bg:#f1f1ee;--tomos-code-text:#555;--tomos-shadow:0 1px 2px rgba(47,47,47,0.04)}html,body{width:100%;overflow-x:hidden}body{background:var(--tomos-bg);color:var(--tomos-text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.7;margin:0;padding:32px 16px}.wrap{background:var(--tomos-surface);border:1px solid var(--tomos-border);border-radius:10px;box-shadow:var(--tomos-shadow);box-sizing:border-box;margin:0 auto;max-width:760px;padding:28px}h1{color:var(--tomos-text)}code{background:var(--tomos-code-bg);border-radius:4px;color:var(--tomos-code-text);padding:0.1rem 0.25rem;overflow-wrap:anywhere;word-break:break-word}</style>';
    echo '</head><body><main class="wrap">';
    echo '<h1>Tomos Post 合言葉の再発行は現在無効です。</h1>';
    echo '<p>合言葉を再発行する場合は、設置ディレクトリ直下に <code>post-reset.enable</code> をアップロードしてください。</p>';
    echo '<p>再発行が終わったら、安全のため <code>post-reset.enable</code> を削除してください。</p>';
    echo '</main></body></html>';
}

function renderPage(string $title, array $config, array $errors, string $newPassword, bool $disabled): void
{
    header('Content-Type: text/html; charset=utf-8');
    $token = (string) ($_SESSION['tomos_post_reset_token'] ?? '');

    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<link rel="icon" href="../../themes/tomos-minimal/assets/favicon.png" type="image/png">';
    echo '<link rel="apple-touch-icon" href="../../themes/tomos-minimal/assets/apple-touch-icon.png">';
    echo '<title>' . e($title) . '</title>';
    echo '<style>
:root{--tomos-bg:#f6f4ef;--tomos-surface:#fcfbf8;--tomos-text:#2f2f2f;--tomos-border:#d9d6cf;--tomos-primary:#9a431c;--tomos-primary-hover:#853919;--tomos-primary-active:#713018;--tomos-notice-bg:#fbf4e8;--tomos-notice-text:#6f4b1d;--tomos-notice-border:#e5c998;--tomos-error-bg:#f8ecea;--tomos-error-border:#d9a39e;--tomos-danger-text:#8a2e26;--tomos-code-bg:#f1f1ee;--tomos-code-text:#555;--tomos-shadow:0 1px 2px rgba(47,47,47,0.04)}
html,body{width:100%;overflow-x:hidden}
body{background:var(--tomos-bg);color:var(--tomos-text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6;margin:0;padding:32px 16px}
.wrap{background:var(--tomos-surface);border:1px solid var(--tomos-border);border-radius:10px;box-shadow:var(--tomos-shadow);box-sizing:border-box;margin:0 auto;max-width:780px;padding:28px}
h1{color:var(--tomos-text);font-size:1.8rem;margin:0 0 0.5rem}.notice{background:var(--tomos-notice-bg);border:1px solid var(--tomos-notice-border);border-radius:6px;color:var(--tomos-notice-text);padding:1rem}.errors{background:var(--tomos-error-bg);border:1px solid var(--tomos-error-border);border-radius:6px;color:var(--tomos-danger-text);padding:1rem}.success{background:var(--tomos-notice-bg);border:1px solid var(--tomos-notice-border);border-radius:6px;color:var(--tomos-notice-text);padding:1rem}.secret{font-size:1.1rem;font-weight:700;word-break:break-all}
.actions{margin-top:1.5rem}button{background:var(--tomos-primary);border:1px solid var(--tomos-primary);border-radius:6px;color:#fff;font:inherit;font-size:16px;font-weight:700;padding:0.7rem 1rem}button:hover{background:var(--tomos-primary-hover);border-color:var(--tomos-primary-hover)}button:active{background:var(--tomos-primary-active);border-color:var(--tomos-primary-active)}button:focus-visible{outline:3px solid rgba(164,74,29,0.28);outline-offset:2px}code{background:var(--tomos-code-bg);border-radius:4px;color:var(--tomos-code-text);padding:0.1rem 0.25rem;overflow-wrap:anywhere;word-break:break-word}
</style></head><body><main class="wrap">';
    echo '<h1>' . e($title) . '</h1>';

    if ($errors !== []) {
        echo '<div class="errors"><strong>再発行できませんでした。</strong><ul>';
        foreach ($errors as $error) {
            echo '<li>' . e((string) $error) . '</li>';
        }
        echo '</ul></div>';
    }

    if ($newPassword !== '') {
        echo '<div class="success">';
        echo '<p>新しい管理用合言葉を作成しました。</p>';
        echo '<p>この合言葉は、記事ファイルを投稿する時に必要です。あとから画面では確認できません。安全な場所に控えてください。</p>';
        echo '<p>新しい管理用合言葉:</p><p class="secret"><code>' . e($newPassword) . '</code></p>';
        echo '<p>安全のため、サーバー上の <code>post-reset.enable</code> を削除してください。</p>';
        echo '</div>';
        echo '</main></body></html>';
        return;
    }

    if ($disabled) {
        echo '</main></body></html>';
        return;
    }

    echo '<div class="notice"><p>この操作を行うと、古い管理用合言葉は使えなくなります。新しい合言葉はこの画面で一度だけ表示されます。</p></div>';
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';
    echo '<div class="actions"><button type="submit">新しい合言葉を作成する</button></div>';
    echo '</form>';
    echo '<p>再発行が終わったら、安全のため <code>post-reset.enable</code> を削除してください。</p>';
    echo '</main></body></html>';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clientIp(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
        return $ip;
    }

    return 'unknown';
}
