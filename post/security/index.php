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

$messages = [];
$errors = [];
$authenticated = !empty($_SESSION['tomos_post_authenticated']);
$remember = null;
if ($config !== []) {
    $remember = new Tomos\PostAuthRememberToken($config, $rootDir);
    if (!$authenticated) {
        $remember->restoreSession();
        $authenticated = !empty($_SESSION['tomos_post_authenticated']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'passphrase_auth') {
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
            $_SESSION['tomos_post_authenticated'] = true;
            $authenticated = true;
            if ((string) ($_POST['remember_post_auth'] ?? '') === '1' && $remember instanceof Tomos\PostAuthRememberToken && !$remember->rememberCurrentBrowser()) {
                $messages[] = '認証には成功しましたが、このブラウザに30日間の認証情報を保存できませんでした。';
            }
            $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
            header('Location: ' . $publicPath('post/security/'));
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
$forgotUrl = $hasPasskey
    ? $publicPath('post/passkey/password-reset/')
    : $publicPath('post/passkey/recovery/');
$token = (string) $_SESSION['tomos_post_token'];
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
</head>
<body>
<h1>Tomos Post</h1>
<h2>セキュリティ</h2>
<p>Tomos Postで使うパスキーの管理と、管理用合言葉を忘れた場合の復旧を行います。</p>

<?php foreach ($messages as $message): ?>
<div class="result"><p><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></p></div>
<?php endforeach; ?>
<?php foreach ($errors as $error): ?>
<div class="result"><p><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></p></div>
<?php endforeach; ?>

<?php if (empty($status['available'])): ?>
<div class="result"><p>この環境ではパスキー機能を利用できません。管理用合言葉によるTomos Post認証は引き続き利用できます。</p></div>
<?php else: ?>
<div class="result">
<p>登録済みパスキー: <?= count($credentials) ?> 件</p>
</div>

<section class="card">
<h2>パスキーを管理する</h2>
<p>登録済みパスキーの確認、名称変更、削除、新しいパスキーの追加ができます。</p>
<?php if ($authenticated): ?>
<a class="button primary" href="<?= htmlspecialchars($publicPath('post/passkey/manage/'), ENT_QUOTES, 'UTF-8') ?>">登録済みパスキーを管理</a>
<a class="button" href="<?= htmlspecialchars($publicPath('post/passkey/register/'), ENT_QUOTES, 'UTF-8') ?>">パスキーを追加</a>
<?php elseif ($hasPasskey): ?>
<p class="hint">パスキーを管理するには、先にTomos Postへ認証してください。</p>
<a class="button primary" href="<?= htmlspecialchars($publicPath('post/passkey/login/'), ENT_QUOTES, 'UTF-8') ?>">パスキーで認証</a>
<form method="post" action="">
<input type="hidden" name="action" value="passphrase_auth">
<input type="hidden" name="_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
<label for="post_password">管理用合言葉</label>
<input id="post_password" type="password" name="post_password" autocomplete="current-password" required>
<label class="remember-auth"><input type="checkbox" name="remember_post_auth" value="1"> このブラウザで30日間、合言葉の入力を省略する</label>
<button type="submit">管理用合言葉で認証</button>
</form>
<?php else: ?>
<p class="hint">まだパスキーは登録されていません。管理用合言葉で認証すると、この画面から最初のパスキーを登録できます。</p>
<form method="post" action="">
<input type="hidden" name="action" value="passphrase_auth">
<input type="hidden" name="_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
<label for="post_password">管理用合言葉</label>
<input id="post_password" type="password" name="post_password" autocomplete="current-password" required>
<label class="remember-auth"><input type="checkbox" name="remember_post_auth" value="1"> このブラウザで30日間、合言葉の入力を省略する</label>
<button type="submit">管理用合言葉で認証</button>
</form>
<?php endif; ?>
</section>

<section class="card">
<h2>管理用合言葉を忘れた場合</h2>
<?php if ($hasPasskey): ?>
<p>登録済みパスキーで本人確認し、管理用合言葉を再設定できます。</p>
<a class="button primary" href="<?= htmlspecialchars($forgotUrl, ENT_QUOTES, 'UTF-8') ?>">パスキーで合言葉を再設定</a>
<?php else: ?>
<p>登録済みパスキーがないため、サーバーへの書き込み権限を確認して最初のパスキーを登録し、その後に管理用合言葉を再設定します。</p>
<a class="button primary" href="<?= htmlspecialchars($forgotUrl, ENT_QUOTES, 'UTF-8') ?>">Tomos Postを復旧</a>
<?php endif; ?>
</section>
<?php endif; ?>

<p><a class="button" href="<?= htmlspecialchars($publicPath('post/'), ENT_QUOTES, 'UTF-8') ?>">Tomos Postへ戻る</a></p>
</body>
</html>
