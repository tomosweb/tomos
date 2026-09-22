<?php

declare(strict_types=1);

session_start();

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__, 3) . '/core/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$rootDir = dirname(__DIR__, 3);
$configPath = $rootDir . '/config.php';
$config = is_file($configPath) ? require $configPath : [];
$config = is_array($config) ? $config : [];
$basePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
$publicPath = static function (string $path) use ($basePath): string {
    $prefix = '/' . trim($basePath, '/');
    if ($prefix === '/') {
        $prefix = '';
    }
    return preg_replace('#/+#', '/', $prefix . '/' . ltrim($path, '/')) ?: '/';
};

if (empty($_SESSION['tomos_post_token'])) {
    $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
}

$authenticated = !empty($_SESSION['tomos_post_authenticated']);
if (!$authenticated && $config !== []) {
    $remember = new Tomos\PostAuthRememberToken($config, $rootDir);
    $remember->restoreSession();
    $authenticated = !empty($_SESSION['tomos_post_authenticated']);
}
if (!$authenticated) {
    header('Location: ' . $publicPath('post/security/') . '?return_to=' . rawurlencode('/post/social/bluesky/'));
    exit;
}

$errors = [];
$messages = [];
$accountStore = new Tomos\BlueskyAccountStore($rootDir);
$account = $accountStore->load();
$accountScope = is_array($account) ? trim((string) ($account['scope'] ?? '')) : '';
$grantedScopes = $accountScope !== '' ? (preg_split('/\\s+/', $accountScope) ?: []) : [];
$hasBlobPermission = in_array('blob:*/*', $grantedScopes, true);

$oauthStatus = (string) ($_GET['oauth'] ?? '');
if ($oauthStatus === 'connected') {
    $messages[] = 'Blueskyとの接続が完了しました。';
} elseif ($oauthStatus === 'denied') {
    $errors[] = 'Blueskyとの接続はキャンセルされました。';
} elseif ($oauthStatus === 'failed') {
    $errors[] = 'Blueskyとの接続を完了できませんでした。もう一度接続してください。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_token'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['tomos_post_token'] ?? ''), $token)) {
        $errors[] = 'フォームの有効期限が切れました。画面を再読み込みしてください。';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'connect') {
            try {
                $identifier = trim((string) ($_POST['identifier'] ?? ''));
                $result = (new Tomos\BlueskyOAuthFlow($config, $rootDir))->start($identifier);
                header('Location: ' . $result->authorizationUrl);
                exit;
            } catch (Throwable $exception) {
                $detail = trim($exception->getMessage());
                if ($detail === '') {
                    $detail = get_class($exception);
                }
                $errors[] = 'Blueskyとの接続を開始できませんでした。';
                $errors[] = '診断: ' . $detail;
            }
        } elseif ($action === 'disconnect') {
            if ($accountStore->delete()) {
                $messages[] = 'Blueskyとの接続を解除しました。';
                $account = null;
                $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
            } else {
                $errors[] = 'Blueskyとの接続情報を削除できませんでした。';
            }
        }
    }
}

$token = (string) $_SESSION['tomos_post_token'];
$settingsUrl = $publicPath('post/?section=settings');
$securityCssUrl = $publicPath('post/assets/tomos-post-security.css');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bluesky連携 - Tomos Post</title>
<link rel="stylesheet" href="<?= htmlspecialchars($securityCssUrl, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<h1>Bluesky連携</h1>
<p class="hint">Tomosで公開した記事を、Frontmatterの指定に応じてBlueskyへ告知します。記事公開とBluesky投稿は別処理です。</p>

<?php if ($errors !== []): ?>
<div class="result ng"><strong>Bluesky連携を完了できませんでした。</strong><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($messages !== []): ?>
<div class="result ok"><ul><?php foreach ($messages as $message): ?><li><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if (is_array($account)): ?>
<h2>接続済み</h2>
<?php if (!$hasBlobPermission): ?>
<div class="result ng">
<strong>画像投稿の権限が不足しています。</strong>
<p>OGP画像をBlueskyカードに付けるには再接続が必要です。いったん接続を解除し、もう一度Blueskyと接続してください。</p>
</div>
<?php else: ?>
<p class="hint">Blueskyアカウントと接続されています。</p>
<?php endif; ?>
<div class="result">
<strong>接続中のアカウント</strong><br>
<?php if ((string) ($account['handle'] ?? '') !== ''): ?>
<code>@<?= htmlspecialchars((string) $account['handle'], ENT_QUOTES, 'UTF-8') ?></code>
<?php else: ?>
<code><?= htmlspecialchars((string) ($account['did'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
<?php endif; ?>
</div>

<form method="post">
<input type="hidden" name="_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="action" value="disconnect">
<div class="actions">
<button class="danger" type="submit">接続を解除する</button>
<a class="button" href="<?= htmlspecialchars($settingsUrl, ENT_QUOTES, 'UTF-8') ?>">設定へ戻る</a>
</div>
</form>
<?php else: ?>
<h2>Blueskyと接続</h2>
<p class="hint">Blueskyのハンドル（例: <code>name.bsky.social</code>）またはDIDを入力してください。TomosにはBlueskyのパスワードを保存しません。</p>

<form method="post">
<input type="hidden" name="_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="action" value="connect">
<label for="identifier">BlueskyハンドルまたはDID</label>
<input id="identifier" type="text" name="identifier" autocomplete="off" spellcheck="false" required>
<div class="actions">
<button type="submit">Blueskyと接続</button>
<a class="button" href="<?= htmlspecialchars($settingsUrl, ENT_QUOTES, 'UTF-8') ?>">設定へ戻る</a>
</div>
</form>
<?php endif; ?>
</body>
</html>
