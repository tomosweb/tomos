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

if (empty($_SESSION['tomos_post_token'])) {
    $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
}

$basePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
$publicPath = static function (string $path) use ($basePath): string {
    $prefix = '/' . trim($basePath, '/');
    if ($prefix === '/') {
        $prefix = '';
    }
    return preg_replace('#/+#', '/', $prefix . '/' . ltrim($path, '/')) ?: '/';
};
$hasReturnTo = array_key_exists('return_to', $_GET) || array_key_exists('return_to', $_POST);
$returnTo = $hasReturnTo ? Tomos\PostAuthReturnTo::normalize($_GET['return_to'] ?? $_POST['return_to'] ?? null) : null;
$returnToField = '';
if ($returnTo !== null) {
    $returnToField = '<input type="hidden" name="return_to" value="' . htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') . '">';
}

function passkeyExistsForCurrentSite(array $config, string $rootDir): bool
{
    try {
        $environment = new Tomos\PasskeyEnvironment($config);
        if (!$environment->isAvailable()) return false;
        $rpId = $environment->rpId();
        $store = new Tomos\PasskeyCredentialStore($config, $rootDir);
        foreach ($store->all() as $record) {
            if ((string) ($record['rp_id'] ?? '') === $rpId) return true;
        }
    } catch (Throwable $exception) {
        return false;
    }
    return false;
}

$messages = [];
$errors = [];
$issuedInboxApiToken = '';
$authenticated = !empty($_SESSION['tomos_post_authenticated']);
$remember = null;
if ($config !== []) {
    $remember = new Tomos\PostAuthRememberToken($config, $rootDir);
    if (!$authenticated) {
        $remember->restoreSession();
    }
    $authenticated = Tomos\PostAuthPolicy::isAuthenticated($config);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'change_passphrase') {
    $token = (string) ($_POST['_token'] ?? '');
    $current = (string) ($_POST['current_passphrase'] ?? '');
    $new = (string) ($_POST['new_passphrase'] ?? '');
    $confirmation = (string) ($_POST['confirm_passphrase'] ?? '');
    if (!$authenticated || $token === '' || !hash_equals((string) ($_SESSION['tomos_post_token'] ?? ''), $token)) {
        $errors[] = '再認証してから操作してください。';
    } elseif ($new === '' || strlen($new) > 4096 || !hash_equals($new, $confirmation)) {
        $errors[] = '新しい管理用合言葉と確認入力を確認してください。';
    } else {
        $rateLimiter = new Tomos\PostRateLimiter($config, $rootDir, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $limit = $rateLimiter->checkAuthAllowed();
        if (!$limit->allowed) {
            $errors[] = $limit->message;
        } elseif (!Tomos\PostAuthPolicy::recentlyVerifiedPasskey($config) && (!Tomos\PostAuthPolicy::allowsPassphrase($config) || !Tomos\PostPassword::verify($current, (string) ($config['security']['post_password_hash'] ?? '')))) {
            $rateLimiter->recordFailure();
            $errors[] = '現在の合言葉またはパスキー再認証を確認できませんでした。';
        } else {
            $rateLimiter->clearFailures();
            try {
                $updatedConfig = $config;
                $updatedConfig['security']['post_password_hash'] = Tomos\PostPassword::hash($new);
                $updatedConfig['security']['post_auth_epoch'] = bin2hex(random_bytes(16));
                $updatedConfig = Tomos\ConfigWriter::expectUnchangedSource($config, $updatedConfig);
                if (!Tomos\ConfigWriter::write($configPath, $updatedConfig, $rootDir)) {
                    throw new RuntimeException('認証セッションの更新に失敗しました。');
                }
                (new Tomos\PostAuthRememberToken($updatedConfig, $rootDir))->invalidateAll();
                $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
                $authenticated = false;
                $config = $updatedConfig;
                $messages[] = '管理用合言葉を変更しました。もう一度認証してください。';
            } catch (Throwable $exception) {
                $errors[] = '管理用合言葉の変更を完了できませんでした。';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'change_login_mode') {
    $token = (string) ($_POST['_token'] ?? '');
    $nextMode = (string) ($_POST['login_mode'] ?? '');
    if (!$authenticated || $token === '' || !hash_equals((string) ($_SESSION['tomos_post_token'] ?? ''), $token)) {
        $errors[] = '再認証してから操作してください。';
    } elseif (!in_array($nextMode, [Tomos\PostAuthPolicy::BOTH, Tomos\PostAuthPolicy::PASSKEY_ONLY], true)) {
        $errors[] = '認証方式を確認できませんでした。';
    } elseif (!Tomos\PostAuthPolicy::recentlyVerifiedPasskey($config)) {
        $errors[] = '認証方式の変更前にパスキーで再認証してください。';
    } elseif ($nextMode === Tomos\PostAuthPolicy::PASSKEY_ONLY && !passkeyExistsForCurrentSite($config, $rootDir)) {
        $errors[] = '登録済みパスキーが必要です。';
    } else {
        $newConfig = $config;
        $newConfig['security']['post_login_mode'] = $nextMode;
        $newConfig['security']['post_auth_epoch'] = bin2hex(random_bytes(16));
        $newConfig = Tomos\ConfigWriter::expectUnchangedSource($config, $newConfig);
        $modeStore = new Tomos\PasskeyCredentialStore($config, $rootDir);
        try {
            $saved = $modeStore->withManagementLock(static function () use ($nextMode, $config, $rootDir, $configPath, $newConfig): bool {
                if ($nextMode === Tomos\PostAuthPolicy::PASSKEY_ONLY && !passkeyExistsForCurrentSite($config, $rootDir)) return false;
                return Tomos\ConfigWriter::write($configPath, $newConfig, $rootDir);
            });
        } catch (Throwable $exception) { $saved = false; }
        if ($saved) {
            (new Tomos\PostAuthRememberToken($newConfig, $rootDir))->invalidateAll();
            $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
            $config = $newConfig;
            $authenticated = false;
            $messages[] = 'ログイン方式を変更しました。新しい設定で再認証してください。';
        } else {
            $errors[] = '設定を保存できませんでした。再度お試しください。';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'issue_inbox_api_token' && $authenticated) {
    $token = (string) ($_POST['_token'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['tomos_post_token'] ?? ''), $token)) {
        $errors[] = 'フォームの有効期限が切れました。画面を再読み込みしてください。';
    } elseif (passkeyExistsForCurrentSite($config, $rootDir) && !Tomos\PostAuthPolicy::recentlyVerifiedPasskey($config)) {
        $errors[] = '投稿用トークンを発行するには、パスキーで再認証してください。';
    } elseif (!passkeyExistsForCurrentSite($config, $rootDir) && !verifyTokenIssuePassphrase($config, $rootDir)) {
        $errors[] = '投稿用トークンを発行するには、現在の管理用合言葉で再認証してください。';
    } else {
        try {
            $issuedInboxApiToken = bin2hex(random_bytes(32));
            $newConfig = $config;
            $newConfig['security']['inbox_api_token_hash'] = Tomos\PostPassword::hash($issuedInboxApiToken);
            if (!Tomos\ConfigWriter::write($configPath, Tomos\ConfigWriter::expectUnchangedSource($config, $newConfig), $rootDir)) {
                $issuedInboxApiToken = '';
                $errors[] = '投稿用トークンを保存できませんでした。';
            } else {
                $config = $newConfig;
                $messages[] = '投稿用トークンを発行しました。この画面を離れる前にObsidianへ設定してください。';
                $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
            }
        } catch (Throwable $exception) {
            $issuedInboxApiToken = '';
            $errors[] = '投稿用トークンを発行できませんでした。';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'passphrase_auth') {
    if (!Tomos\PostAuthPolicy::allowsPassphrase($config)) {
        http_response_code(403);
        exit;
    }
    $token = (string) ($_POST['_token'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['tomos_post_token'] ?? ''), $token)) {
        $errors[] = 'フォームの有効期限が切れました。画面を再読み込みしてください。';
    } else {
        $postPasswordHash = (string) ($config['security']['post_password_hash'] ?? '');
        $rateLimiter = new Tomos\PostRateLimiter($config, $rootDir, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $limit = $rateLimiter->checkAuthAllowed();
        if (!$limit->allowed) {
            $errors[] = $limit->message;
        } elseif (!Tomos\PostPassword::verify((string) ($_POST['post_password'] ?? ''), $postPasswordHash)) {
            $rateLimiter->recordFailure();
            $errors[] = '管理用合言葉が正しくありません。';
        } else {
            $rateLimiter->clearFailures();
            Tomos\PostAuthPolicy::markAuthenticated($config, 'passphrase');
            $remember->refreshSessionCookiePath();
            $authenticated = true;
            if ((string) ($_POST['remember_post_auth'] ?? '') === '1' && $remember instanceof Tomos\PostAuthRememberToken && !$remember->rememberCurrentBrowser()) {
                $messages[] = '認証には成功しましたが、このブラウザに30日間の認証情報を保存できませんでした。';
            }
            $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
            $destination = $returnTo !== null
                ? Tomos\PostAuthReturnTo::url($returnTo, $basePath)
                : $publicPath('post/security/');
            header('Location: ' . $destination);
            exit;
        }
    }
}

$environment = new Tomos\PasskeyEnvironment($config);
$status = $environment->diagnose();
$credentials = [];
$currentRpId = '';
if (!empty($status['available'])) {
    try {
        $currentRpId = $environment->rpId();
        $store = new Tomos\PasskeyCredentialStore($config, $rootDir);
        foreach ($store->all() as $record) {
            if ((string) ($record['rp_id'] ?? '') === $currentRpId) {
                $credentials[] = $record;
            }
        }
    } catch (Throwable $exception) {
        $credentials = [];
    }
}

$hasPasskey = $credentials !== [];
$passkeyUrl = $publicPath('post/passkey/login/');
if ($returnTo !== null) {
    $passkeyUrl .= '?return_to=' . rawurlencode($returnTo);
}
$forgotUrl = $hasPasskey
    ? $publicPath('post/passkey/password-reset/')
    : $publicPath('post/passkey/recovery/');
$token = (string) $_SESSION['tomos_post_token'];
$settingsUrl = $publicPath('post/?section=settings');
function verifyTokenIssuePassphrase(array $config, string $rootDir): bool
{
    if (!Tomos\PostAuthPolicy::allowsPassphrase($config)) return false;
    $limiter = new Tomos\PostRateLimiter($config, $rootDir, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!$limiter->checkAuthAllowed()->allowed) return false;
    if (!Tomos\PostPassword::verify((string) ($_POST['current_passphrase'] ?? ''), (string) ($config['security']['post_password_hash'] ?? ''))) {
        $limiter->recordFailure();
        return false;
    }
    $limiter->clearFailures();
    return true;
}
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>セキュリティ - Tomos Post</title>
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:760px;margin:40px auto;padding:0 20px;line-height:1.7;color:#222}.card{border-top:1px solid #ddd;padding:1.2rem 0}.card:first-of-type{border-top:0}.button,button{display:inline-block;padding:.7rem 1rem;margin:.2rem .35rem .2rem 0;border:1px solid #777;border-radius:.35rem;background:#fff;color:inherit;text-decoration:none;font:inherit;cursor:pointer}.primary{font-weight:700}.result{margin:1rem 0;padding:1rem;background:#f5f5f5}.hint{color:#666}input[type=password]{box-sizing:border-box;width:100%;max-width:36rem;padding:.65rem;margin:.3rem 0 1rem;font:inherit}.remember-auth{display:flex;gap:.5rem;align-items:flex-start;margin:.6rem 0 1rem}.remember-auth input{margin-top:.35rem}
</style>
<link rel="stylesheet" href="../assets/tomos-post-security.css">
<link rel="stylesheet" href="../assets/tomos-post-ui.css">
</head>
<body class="post-standalone">
<main class="wrap security-page">
<header class="page-heading"><h1><?= tomosPostIcon('security') ?><span>セキュリティ</span></h1><p class="hint">認証、パスキー、API関連</p></header>

<?php foreach ($messages as $message): ?>
<div class="result"><p><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></p></div>
<?php endforeach; ?>
<?php foreach ($errors as $error): ?>
<div class="result"><p><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></p></div>
<?php endforeach; ?>

<?php if ($issuedInboxApiToken !== ''): ?>
<div class="result">
<p>この投稿用トークンは今回だけ表示されます。</p>
<pre style="white-space:pre-wrap;word-break:break-all;user-select:all;"><?= htmlspecialchars($issuedInboxApiToken, ENT_QUOTES, 'UTF-8') ?></pre>
</div>
<?php endif; ?>

<?php if ($authenticated && !$hasPasskey && !empty($status['available'])): ?>
<section class="card security-section">
<h2>パスキーを設定しましょう</h2>
<p>パスキーを登録すると、Face ID・Touch IDや端末の画面ロックでTomos Postを開けます。</p>
<a class="button primary" href="<?= htmlspecialchars($publicPath('post/passkey/register/'), ENT_QUOTES, 'UTF-8') ?>">最初のパスキーを登録</a>
</section>
<?php endif; ?>
<?php if ($authenticated): ?>
<section class="card security-section">
<h2>ログイン方法</h2>
<?php if (empty($status['available'])): ?>
<p>この環境ではパスキーを利用できないため、ログイン方法を変更できません。パスキーの利用にはPHP 8.0以上などの動作要件を満たす必要があります。</p>
<?php else: ?>
<p>ログイン方式の変更には、5分以内のパスキー再認証が必要です。</p>
<?php if (!Tomos\PostAuthPolicy::recentlyVerifiedPasskey($config)): ?>
<a class="button primary" href="<?= htmlspecialchars($passkeyUrl, ENT_QUOTES, 'UTF-8') ?>">パスキーで再認証</a>
<?php else: ?>
<form method="post">
<input type="hidden" name="action" value="change_login_mode">
<input type="hidden" name="_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
<label><input type="radio" name="login_mode" value="both" <?= Tomos\PostAuthPolicy::mode($config) === 'both' ? 'checked' : '' ?>> 合言葉とパスキー</label>
<label><input type="radio" name="login_mode" value="passkey_only" <?= Tomos\PostAuthPolicy::mode($config) === 'passkey_only' ? 'checked' : '' ?>> パスキーのみ（合言葉ログインを禁止）</label>
<p class="hint">パスキーを失った場合は、サーバー管理者による復旧が必要です。別のパスキーの登録も推奨します。</p>
<button type="submit">ログイン方法を保存</button>
</form>
<?php endif; ?>
<?php endif; ?>
</section>
<section class="card security-section">
<h2>管理用合言葉を変更</h2>
<?php if (!empty($status['available'])): ?>
<p class="hint">パスキーで直近に認証済みの場合は現在の合言葉の入力を省略できます。</p>
<?php endif; ?>
<form method="post" autocomplete="off">
<input type="hidden" name="action" value="change_passphrase">
<input type="hidden" name="_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
<?php if (!Tomos\PostAuthPolicy::recentlyVerifiedPasskey($config) && Tomos\PostAuthPolicy::allowsPassphrase($config)): ?>
<label for="current_passphrase">現在の管理用合言葉</label>
<input id="current_passphrase" name="current_passphrase" type="password" autocomplete="current-password" required>
<?php elseif (!Tomos\PostAuthPolicy::recentlyVerifiedPasskey($config) && !empty($status['available'])): ?>
<p><a class="button" href="<?= htmlspecialchars($passkeyUrl, ENT_QUOTES, 'UTF-8') ?>">パスキーで再認証</a></p>
<?php endif; ?>
<label for="new_passphrase">新しい管理用合言葉</label>
<input id="new_passphrase" name="new_passphrase" type="password" autocomplete="new-password" required>
<label for="confirm_passphrase">新しい合言葉（確認）</label>
<input id="confirm_passphrase" name="confirm_passphrase" type="password" autocomplete="new-password" required>
<button type="submit">管理用合言葉を変更</button>
</form>
</section>
<section class="card security-section">
<h2><?= tomosPostIcon('edit') ?><span>Obsidian投稿用トークン</span></h2>
<p class="hint">ObsidianからTomosへHTTPS投稿するための専用トークンです。再発行すると以前のトークンは無効になります。</p>
<?php if ($hasPasskey && !Tomos\PostAuthPolicy::recentlyVerifiedPasskey($config)): ?>
<p><a class="button" href="<?= htmlspecialchars($publicPath('post/passkey/login/') . '?return_to=' . rawurlencode('/post/security/'), ENT_QUOTES, 'UTF-8') ?>">パスキーで再認証してトークン発行を有効にする</a></p>
<?php else: ?>
<form method="post" action="">
<input type="hidden" name="action" value="issue_inbox_api_token">
<?php if (!$hasPasskey): ?>
<label for="token_current_passphrase">現在の管理用合言葉</label>
<input id="token_current_passphrase" name="current_passphrase" type="password" autocomplete="current-password" required>
<?php endif; ?>
<input type="hidden" name="_token" value="<?= htmlspecialchars((string) ($_SESSION['tomos_post_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<button type="submit">投稿用トークンを発行・再発行</button>
</form>
<?php endif; ?>
</section>
<?php endif; ?>

<?php if (empty($status['available'])): ?>
<div class="result"><p>この環境ではパスキー機能を利用できません。パスキー専用モードの場合は、サーバー管理者による復旧が必要です。</p></div>
<?php if (Tomos\PostAuthPolicy::allowsPassphrase($config)): ?>
<form method="post" action="">
<input type="hidden" name="action" value="passphrase_auth">
<input type="hidden" name="_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
<?= $returnToField ?>
<label for="post_password">管理用合言葉</label>
<input id="post_password" type="password" name="post_password" autocomplete="current-password" required>
<label class="remember-auth"><input type="checkbox" name="remember_post_auth" value="1"> このブラウザで30日間、合言葉の入力を省略する</label>
<button type="submit">管理用合言葉で認証</button>
</form>
<?php endif; ?>
<?php else: ?>
<div class="result">
<p>登録済みパスキー: <?= count($credentials) ?> 件</p>
</div>

<section class="card security-section">
<h2><?= tomosPostIcon('security') ?><span>パスキーを管理する</span></h2>
<p>登録済みパスキーの確認、名称変更、削除、新しいパスキーの追加ができます。</p>
<?php if ($authenticated): ?>
<a class="button primary" href="<?= htmlspecialchars($publicPath('post/passkey/manage/'), ENT_QUOTES, 'UTF-8') ?>">登録済みパスキーを管理</a>
<a class="button" href="<?= htmlspecialchars($publicPath('post/passkey/register/'), ENT_QUOTES, 'UTF-8') ?>">パスキーを追加</a>
<?php elseif ($hasPasskey): ?>
<p class="hint">パスキーを管理するには、先にTomos Postへ認証してください。</p>
<a class="button primary" href="<?= htmlspecialchars($passkeyUrl, ENT_QUOTES, 'UTF-8') ?>">パスキーで認証</a>
<?php if (Tomos\PostAuthPolicy::allowsPassphrase($config)): ?>
<form method="post" action="">
<input type="hidden" name="action" value="passphrase_auth">
<input type="hidden" name="_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
<?= $returnToField ?>
<label for="post_password">管理用合言葉</label>
<input id="post_password" type="password" name="post_password" autocomplete="current-password" required>
<label class="remember-auth"><input type="checkbox" name="remember_post_auth" value="1"> このブラウザで30日間、合言葉の入力を省略する</label>
<button type="submit">管理用合言葉で認証</button>
</form>
<?php endif; ?>
<?php else: ?>
<p class="hint">まだパスキーは登録されていません。管理用合言葉で認証すると、この画面から最初のパスキーを登録できます。</p>
<?php if (Tomos\PostAuthPolicy::allowsPassphrase($config)): ?>
<form method="post" action="">
<input type="hidden" name="action" value="passphrase_auth">
<input type="hidden" name="_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
<?= $returnToField ?>
<label for="post_password">管理用合言葉</label>
<input id="post_password" type="password" name="post_password" autocomplete="current-password" required>
<label class="remember-auth"><input type="checkbox" name="remember_post_auth" value="1"> このブラウザで30日間、合言葉の入力を省略する</label>
<button type="submit">管理用合言葉で認証</button>
</form>
<?php endif; ?>
<?php endif; ?>
</section>

<section class="card security-section">
<h2><?= tomosPostIcon('security') ?><span>管理用合言葉を忘れた場合</span></h2>
<?php if ($hasPasskey): ?>
<p>登録済みパスキーで本人確認し、管理用合言葉を再設定できます。</p>
<a class="button primary" href="<?= htmlspecialchars($forgotUrl, ENT_QUOTES, 'UTF-8') ?>">パスキーで合言葉を再設定</a>
<?php else: ?>
<p>登録済みパスキーがないため、サーバーへの書き込み権限を確認して最初のパスキーを登録し、その後に管理用合言葉を再設定します。</p>
<a class="button primary" href="<?= htmlspecialchars($forgotUrl, ENT_QUOTES, 'UTF-8') ?>">Tomos Postを復旧</a>
<?php endif; ?>
</section>
<?php endif; ?>

<?= tomosPostReturnLink($settingsUrl) ?>
</main>
</body>
</html>
