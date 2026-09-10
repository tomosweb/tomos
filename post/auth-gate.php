<?php

declare(strict_types=1);

session_start();

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$rootDir = dirname(__DIR__);
$configPath = $rootDir . '/config.php';
$config = [];
if (is_file($configPath)) {
    $loadedConfig = require $configPath;
    $config = is_array($loadedConfig) ? $loadedConfig : [];
}

function continueToStablePost(): void
{
    session_write_close();
    require __DIR__ . '/index.php';
    exit;
}

// Keep setup/disabled/error behavior exactly where it already lives.
if ($config === [] || empty($config['features']['post']) || empty($config['security']['post_password_hash'])) {
    continueToStablePost();
}

// Preserve the existing Post API and protected preview/download behavior.
$delegatedQueryKeys = [
    'post_api',
    'preview_inbox',
    'download_inbox_markdown',
    'download_inbox',
    'preview_draft',
    'download_draft_markdown',
];
foreach ($delegatedQueryKeys as $key) {
    if (array_key_exists($key, $_GET)) {
        continueToStablePost();
    }
}

if (empty($_SESSION['tomos_post_token'])) {
    $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
}

$publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
$postUrl = Tomos\Security::publicUrl('/post/', $publicBasePath);
$remember = new Tomos\PostAuthRememberToken($config, $rootDir);
$authenticated = $remember->restoreSession();
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';

// Logout belongs to the authentication boundary. Handle only the already
// existing logout action here so an unauthenticated Post body is never
// rendered after the credential is cleared. Invalid CSRF requests stay with
// the stable Post implementation and its existing error behavior.
if ($authenticated && $action === 'logout') {
    $token = (string) ($_POST['_token'] ?? '');
    if ($token !== '' && hash_equals((string) ($_SESSION['tomos_post_token'] ?? ''), $token)) {
        $remember->forgetCurrentBrowser();
        header('Location: ' . $postUrl);
        exit;
    }
    continueToStablePost();
}

if ($authenticated) {
    continueToStablePost();
}

$errors = [];
$warnings = [];
$hasReturnTo = array_key_exists('return_to', $_GET) || array_key_exists('return_to', $_POST);
$returnTo = $hasReturnTo
    ? Tomos\PostAuthReturnTo::normalize($_POST['return_to'] ?? $_GET['return_to'] ?? null)
    : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'auth_gate_login') {
    $token = (string) ($_POST['_token'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['tomos_post_token'] ?? ''), $token)) {
        $errors[] = 'フォームの有効期限が切れました。画面を再読み込みしてください。';
    } else {
        $rateLimiter = new Tomos\PostRateLimiter($config, $rootDir, clientIpForAuthGate());
        $limit = $rateLimiter->checkAuthAllowed();
        if (!$limit->allowed) {
            $errors[] = $limit->message;
        } elseif (!Tomos\PostPassword::verify((string) ($_POST['post_password'] ?? ''), (string) $config['security']['post_password_hash'])) {
            $rateLimiter->recordFailure();
            $errors[] = '管理用合言葉が正しくありません。';
        } else {
            $rateLimiter->clearFailures();
            $_SESSION['tomos_post_authenticated'] = true;
            if ((string) ($_POST['remember_post_auth'] ?? '') === '1') {
                $remember->rememberCurrentBrowser();
            }

            $destination = $returnTo !== null
                ? Tomos\PostAuthReturnTo::url($returnTo, $publicBasePath)
                : $postUrl;
            header('Location: ' . $destination);
            exit;
        }
    }
}

renderAuthGate($config, $errors, $warnings, $returnTo);

function clientIpForAuthGate(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
        return $ip;
    }
    return 'unknown';
}

function authGatePasskeyAvailable(array $config, string $rootDir): bool
{
    if (PHP_VERSION_ID < 80000 || $config === []) {
        return false;
    }

    $vendor = rtrim($rootDir, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'core'
        . DIRECTORY_SEPARATOR . 'webauthn'
        . DIRECTORY_SEPARATOR . 'vendor'
        . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_file($vendor)) {
        return false;
    }
    require_once $vendor;

    try {
        $environment = new Tomos\PasskeyEnvironment($config);
        if (!$environment->isAvailable()) {
            return false;
        }

        $rpId = $environment->rpId();
        if ($rpId === '') {
            return false;
        }

        $store = new Tomos\PasskeyCredentialStore($config, $rootDir);
        foreach ($store->all() as $record) {
            if ((string) ($record['rp_id'] ?? '') === $rpId) {
                return true;
            }
        }
    } catch (Throwable $exception) {
        return false;
    }

    return false;
}

function renderAuthGate(array $config, array $errors, array $warnings, ?string $returnTo): void
{
    $rootDir = dirname(__DIR__);
    $publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
    $postUrl = Tomos\Security::publicUrl('/post/', $publicBasePath);
    $passkeyUrl = Tomos\Security::publicUrl('/post/passkey/login/', $publicBasePath);
    if ($returnTo !== null) {
        $passkeyUrl .= '?return_to=' . rawurlencode($returnTo);
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');

    $token = (string) ($_SESSION['tomos_post_token'] ?? '');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Tomos Post</title>';
    echo '<style>';
    echo ':root{--bg:#f6f4ef;--surface:#fcfbf8;--text:#2f2f2f;--muted:#6b6b6b;--border:#d9d6cf;--accent:#9a431c;--accent-hover:#853919;--error-bg:#f8ecea;--error-border:#d9a39e;--error-text:#8a2e26;--notice-bg:#fbf4e8;--notice-border:#e5c998;--notice-text:#6f4b1d}';
    echo 'body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6;margin:0;padding:32px 16px}';
    echo '.wrap{background:var(--surface);border:1px solid var(--border);border-radius:10px;box-sizing:border-box;margin:0 auto;max-width:620px;padding:28px}';
    echo 'h1{font-size:1.8rem;margin:0 0 .5rem}.hint{color:var(--muted)}.methods{display:grid;gap:1.25rem;margin-top:1.5rem}.method{border-top:1px solid var(--border);padding-top:1.25rem}.method:first-child{border-top:0;padding-top:0}';
    echo 'label{display:block;font-weight:700;margin:.75rem 0 .35rem}input[type=password]{background:#fff;border:1px solid var(--border);border-radius:6px;box-sizing:border-box;font:inherit;font-size:16px;padding:.65rem;width:100%}.remember{align-items:flex-start;display:flex;font-weight:400;gap:.5rem}.remember input{margin-top:.35rem}';
    echo 'button,.button{background:var(--accent);border:1px solid var(--accent);border-radius:6px;color:#fff;cursor:pointer;display:inline-block;font:inherit;font-weight:700;padding:.7rem 1rem;text-decoration:none}button:hover,.button:hover{background:var(--accent-hover);border-color:var(--accent-hover)}.actions{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1rem}';
    echo '.errors{background:var(--error-bg);border:1px solid var(--error-border);border-radius:6px;color:var(--error-text);margin-top:1rem;padding:1rem}.notice{background:var(--notice-bg);border:1px solid var(--notice-border);border-radius:6px;color:var(--notice-text);margin-top:1rem;padding:1rem}';
    echo '@media(max-width:560px){body{padding:16px 10px}.wrap{padding:20px 16px}button,.button{box-sizing:border-box;min-height:44px}}';
    echo '</style></head><body><main class="wrap">';
    echo '<h1>Tomos Post</h1>';
    echo '<p class="hint">管理画面を開くには認証してください。</p>';

    if ($errors !== []) {
        echo '<div class="errors"><ul>';
        foreach ($errors as $error) {
            echo '<li>' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul></div>';
    }
    if ($warnings !== []) {
        echo '<div class="notice"><ul>';
        foreach ($warnings as $warning) {
            echo '<li>' . htmlspecialchars((string) $warning, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul></div>';
    }

    echo '<div class="methods">';
    if (authGatePasskeyAvailable($config, $rootDir)) {
        echo '<section class="method">';
        echo '<h2>パスキー</h2>';
        echo '<div class="actions"><a class="button" href="' . htmlspecialchars($passkeyUrl, ENT_QUOTES, 'UTF-8') . '">パスキーで開く</a></div>';
        echo '</section>';
    }

    echo '<section class="method">';
    echo '<h2>管理用合言葉</h2>';
    echo '<form method="post" action="' . htmlspecialchars($postUrl, ENT_QUOTES, 'UTF-8') . '">';
    echo '<input type="hidden" name="action" value="auth_gate_login">';
    echo '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    if ($returnTo !== null) {
        echo '<input type="hidden" name="return_to" value="' . htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') . '">';
    }
    echo '<label for="post_password">管理用合言葉</label>';
    echo '<input id="post_password" type="password" name="post_password" autocomplete="current-password">';
    echo '<label class="remember"><input type="checkbox" name="remember_post_auth" value="1"> このブラウザで30日間、合言葉の入力を省略する</label>';
    echo '<div class="actions"><button type="submit">開く</button></div>';
    echo '</form>';
    echo '</section>';
    echo '</div>';
    echo '</main></body></html>';
}
