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

$basePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
$publicPath = static function (string $path) use ($basePath): string {
    $prefix = '/' . trim($basePath, '/');
    if ($prefix === '/') {
        $prefix = '';
    }
    return preg_replace('#/+#', '/', $prefix . '/' . ltrim($path, '/')) ?: '/';
};

$authenticated = !empty($_SESSION['tomos_post_authenticated']);
if ($config !== []) {
    $remember = new Tomos\PostAuthRememberToken($config, $rootDir);
    if (!$authenticated) {
        $remember->restoreSession();
        $authenticated = !empty($_SESSION['tomos_post_authenticated']);
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
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>セキュリティ - Tomos Post</title>
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:760px;margin:40px auto;padding:0 20px;line-height:1.7;color:#222}.card{border-top:1px solid #ddd;padding:1.2rem 0}.card:first-of-type{border-top:0}.button{display:inline-block;padding:.7rem 1rem;margin:.2rem .35rem .2rem 0;border:1px solid #777;border-radius:.35rem;background:#fff;color:inherit;text-decoration:none}.primary{font-weight:700}.result{margin:1rem 0;padding:1rem;background:#f5f5f5}.hint{color:#666}code{word-break:break-all}
</style>
</head>
<body>
<h1>セキュリティ</h1>
<p>Tomos Postのログイン方法、パスキー、管理用合言葉の復旧を管理します。</p>

<?php if (empty($status['available'])): ?>
<div class="result"><p>この環境ではパスキー機能を利用できません。管理用合言葉によるTomos Post認証は引き続き利用できます。</p></div>
<?php else: ?>
<div class="result">
<p>登録済みパスキー: <?= count($credentials) ?> 件</p>
<?php if ($currentRpId !== ''): ?><p class="hint">RP ID: <code><?= htmlspecialchars($currentRpId, ENT_QUOTES, 'UTF-8') ?></code></p><?php endif; ?>
</div>
<?php endif; ?>

<section class="card">
<h2>Tomos Postを開く</h2>
<?php if ($authenticated): ?>
<p>このブラウザは現在認証済みです。</p>
<a class="button primary" href="<?= htmlspecialchars($publicPath('post/'), ENT_QUOTES, 'UTF-8') ?>">Tomos Postへ</a>
<?php else: ?>
<?php if (!empty($status['available']) && $hasPasskey): ?>
<a class="button primary" href="<?= htmlspecialchars($publicPath('post/passkey/login/'), ENT_QUOTES, 'UTF-8') ?>">パスキーで開く</a>
<?php endif; ?>
<a class="button" href="<?= htmlspecialchars($publicPath('post/'), ENT_QUOTES, 'UTF-8') ?>">管理用合言葉で開く</a>
<?php endif; ?>
</section>

<?php if (!empty($status['available'])): ?>
<section class="card">
<h2>パスキー</h2>
<?php if ($authenticated): ?>
<a class="button primary" href="<?= htmlspecialchars($publicPath('post/passkey/manage/'), ENT_QUOTES, 'UTF-8') ?>">登録済みパスキーを管理</a>
<a class="button" href="<?= htmlspecialchars($publicPath('post/passkey/register/'), ENT_QUOTES, 'UTF-8') ?>">パスキーを追加</a>
<?php elseif ($hasPasskey): ?>
<p class="hint">パスキーの追加・削除にはTomos Postへの認証が必要です。</p>
<?php else: ?>
<p class="hint">まだパスキーは登録されていません。管理用合言葉でTomos Postへ認証した後に登録できます。</p>
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
