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
$authenticated = false;

if ($config !== []) {
    $remember = new Tomos\PostAuthRememberToken($config, $rootDir);
    $authenticated = $remember->restoreSession();
}

$environment = new Tomos\PasskeyEnvironment($config);
$store = new Tomos\PasskeyCredentialStore($config, $rootDir);
$management = new Tomos\PasskeyManagementService($environment, $store);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$authenticated) {
        $errors[] = 'パスキーを管理するにはTomos Postへ認証してください。';
    } else {
        $token = (string) ($_POST['_token'] ?? '');
        if ($token === '' || !hash_equals((string) ($_SESSION['tomos_post_token'] ?? ''), $token)) {
            $errors[] = 'フォームの有効期限が切れました。画面を再読み込みしてください。';
        } else {
            $action = (string) ($_POST['action'] ?? '');
            try {
                if ($action === 'rename') {
                    $management->rename(
                        (string) ($_POST['credential_id'] ?? ''),
                        (string) ($_POST['label'] ?? '')
                    );
                    $messages[] = 'パスキーの名称を更新しました。';
                } elseif ($action === 'delete') {
                    $management->delete((string) ($_POST['credential_id'] ?? ''));
                    $messages[] = 'パスキーを削除しました。';
                } else {
                    $errors[] = '操作を確認できませんでした。';
                }
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
            $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
        }
    }
}

$credentials = $authenticated ? $management->all() : [];
$token = (string) $_SESSION['tomos_post_token'];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatTimestamp(?int $timestamp, array $config): string
{
    if ($timestamp === null || $timestamp <= 0) {
        return '未使用';
    }
    try {
        $timezone = new DateTimeZone((string) ($config['site']['timezone'] ?? 'Asia/Tokyo'));
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('Y年n月j日 H:i');
    } catch (Throwable $exception) {
        return date('Y年n月j日 H:i', $timestamp);
    }
}
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>パスキー管理 - Tomos Post</title>
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:840px;margin:40px auto;padding:0 20px;line-height:1.7;color:#222}input,button{font:inherit}input[type=text]{box-sizing:border-box;width:100%;max-width:28rem;padding:.6rem;margin:.3rem 0 .7rem}button,.button{display:inline-block;padding:.65rem 1rem;border:1px solid #777;border-radius:.35rem;background:#fff;color:inherit;text-decoration:none;cursor:pointer}.danger{border-color:#b91c1c;color:#991b1b}.result{margin:1rem 0;padding:1rem;background:#f5f5f5}.ok{color:#166534}.ng{color:#991b1b}.hint{color:#666}.credential{border-top:1px solid #ddd;padding:1.25rem 0}.credential:first-of-type{border-top:0}.actions{display:flex;gap:.6rem;flex-wrap:wrap;align-items:end}.meta{font-size:.92rem;color:#555}code{word-break:break-all}
</style>
<link rel="stylesheet" href="../../assets/tomos-post-security.css">
</head>
<body>
<h1>Tomos Post</h1>
<h2>パスキー管理</h2>
<p>Tomos Postで使用する登録済みパスキーの名称変更と削除を行います。管理用合言葉による認証は、パスキーをすべて削除しても引き続き利用できます。</p>

<?php foreach ($messages as $message): ?>
<div class="result ok"><p><?= e((string) $message) ?></p></div>
<?php endforeach; ?>
<?php foreach ($errors as $error): ?>
<div class="result ng"><p><?= e((string) $error) ?></p></div>
<?php endforeach; ?>

<?php if (!$authenticated): ?>
<div class="result ng">
<p>パスキーを管理するにはTomos Postへの認証が必要です。</p>
<p><a class="button" href="<?= e($publicPath('post/passkey/login/')) ?>">パスキーで認証</a> <a class="button" href="<?= e($publicPath('post/')) ?>">管理用合言葉で認証</a></p>
</div>
<?php else: ?>
<p><a class="button" href="<?= e($publicPath('post/passkey/register/')) ?>">パスキーを追加</a></p>

<?php if ($credentials === []): ?>
<div class="result"><p>登録済みパスキーはありません。</p></div>
<?php else: ?>
<?php foreach ($credentials as $credential): ?>
<section class="credential">
<h2><?= e((string) (($credential['label'] ?? '') ?: '名称なし')) ?></h2>
<p class="meta">登録: <?= e(formatTimestamp((int) ($credential['created_at'] ?? 0), $config)) ?><br>最終利用: <?= e(formatTimestamp(isset($credential['last_used_at']) ? (int) $credential['last_used_at'] : null, $config)) ?><br>RP ID: <code><?= e((string) ($credential['rp_id'] ?? '')) ?></code></p>

<form method="post" action="">
<input type="hidden" name="action" value="rename">
<input type="hidden" name="_token" value="<?= e($token) ?>">
<input type="hidden" name="credential_id" value="<?= e((string) ($credential['credential_id'] ?? '')) ?>">
<label>名称
<input type="text" name="label" maxlength="100" required value="<?= e((string) ($credential['label'] ?? '')) ?>">
</label>
<div class="actions"><button type="submit">名称を変更</button></div>
</form>

<form method="post" action="" onsubmit="return confirm('このパスキーをTomosから削除します。よろしいですか？');">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="_token" value="<?= e($token) ?>">
<input type="hidden" name="credential_id" value="<?= e((string) ($credential['credential_id'] ?? '')) ?>">
<div class="actions"><button class="danger" type="submit">このパスキーを削除</button></div>
</form>
</section>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

<p><a class="button" href="<?= e($publicPath('post/security/')) ?>">セキュリティへ戻る</a> <a class="button" href="<?= e($publicPath('post/')) ?>">Tomos Postへ戻る</a></p>
</body>
</html>
