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
$config = is_file($configPath) ? require $configPath : [];
$config = is_array($config) ? $config : [];
$errors = [];
$criticalErrors = [];
$messages = [];
$warnings = [];

if (empty($_SESSION['tomos_updater_finalize_token'])) {
    $_SESSION['tomos_updater_finalize_token'] = bin2hex(random_bytes(32));
}

$selfUpdate = new Tomos\UpdaterSelfUpdate($rootDir);
$pending = $selfUpdate->hasPendingUpdate();
$postPasswordHash = (string) ($config['security']['post_password_hash'] ?? '');
$authRemember = new Tomos\PostAuthRememberToken($config, $rootDir);
$authRemember->restoreSession();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = (string) ($_POST['_token'] ?? '');
    if (!hash_equals((string) $_SESSION['tomos_updater_finalize_token'], $token)) {
        $errors[] = '画面の有効期限が切れました。再読み込みしてください。';
    } elseif ($postPasswordHash === '') {
        $errors[] = '管理用合言葉が設定されていません。Tomos Postの設定を確認してください。';
    } elseif (empty($_SESSION['tomos_post_authenticated'])) {
        $rateLimiter = new Tomos\PostRateLimiter($config, $rootDir, clientIp());
        $limit = $rateLimiter->checkAuthAllowed();
        if (!$limit->allowed) {
            $errors[] = $limit->message;
        } elseif (!Tomos\PostPassword::verify((string) ($_POST['post_password'] ?? ''), $postPasswordHash)) {
            $rateLimiter->recordFailure();
            $errors[] = '管理用合言葉が正しくありません。';
        } else {
            $rateLimiter->clearFailures();
            $_SESSION['tomos_post_authenticated'] = true;
            if ((string) ($_POST['remember_post_auth'] ?? '') === '1' && !$authRemember->rememberCurrentBrowser()) {
                $warnings[] = '認証には成功しましたが、このブラウザに30日間の認証情報を保存できませんでした。';
            }
        }
    }
    if ($errors === [] && (class_exists(Tomos\UpdateLock::class) && Tomos\UpdateLock::isActive($rootDir))) {
        $errors[] = 'Tomosの更新中です。完了してからもう一度お試しください。';
    } elseif ($errors === [] && !$pending) {
        $messages[] = '反映待ちのUpdater更新はありません。';
    } elseif ($errors === []) {
        try {
            $result = $selfUpdate->apply();
            if (!empty($result['applied'])) {
                $messages[] = 'Updater本体を更新しました。';
            } else {
                $messages[] = 'Updater本体はすでに同じ内容です。不要な置換は行いませんでした。';
            }
            if (empty($result['recording_ok'])) {
                $warnings[] = 'Updater本体の確認は完了しましたが、結果記録を保存できませんでした。反映待ちデータは再確認のため保持しています。';
            } elseif (empty($result['cleanup_ok'])) {
                $warnings[] = 'Updater本体の確認は完了しましたが、反映待ちデータの後処理を完了できませんでした。';
            }
            $_SESSION['tomos_updater_finalize_token'] = bin2hex(random_bytes(32));
            $pending = $selfUpdate->hasPendingUpdate();
        } catch (Throwable $exception) {
            $stage = $exception instanceof Tomos\UpdaterSelfUpdateException
                ? $exception->stage()
                : 'unexpected';
            error_log('Tomos updater finalize failed at stage: ' . $stage);
            if ($exception instanceof Tomos\UpdaterSelfUpdateException && $exception->rollbackFailed()) {
                $criticalErrors[] = 'Updater更新に失敗し、自動復元も完了できませんでした。保存されたバックアップを管理者が確認してください。';
            } else {
                $errors[] = 'Updater更新を完了できませんでした。現在のUpdaterは変更されていないか、更新前の状態へ復元されています。';
            }
            if ($exception instanceof Tomos\UpdaterSelfUpdateException && $exception->recordingFailed()) {
                $warnings[] = '更新結果の記録を完了できませんでした。';
            }
            $pending = $selfUpdate->hasPendingUpdate();
        }
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Updater更新の反映 | Tomos Post</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.7;margin:0;background:#f6f6f4;color:#222}
main{max-width:720px;margin:48px auto;padding:0 20px}
section{background:#fff;border:1px solid #ddd;border-radius:12px;padding:28px}
h1{font-size:1.6rem;margin-top:0}.notice{padding:12px 14px;border-radius:8px;margin:0 0 16px}.error{background:#fff0f0}.critical{background:#ffe1e1;border:2px solid #a40000}.warning{background:#fff7df}.success{background:#edf8ef}
label{display:block;font-weight:700;margin:18px 0 6px}input[type=password]{box-sizing:border-box;width:100%;padding:10px;border:1px solid #aaa;border-radius:6px}
button{margin-top:18px;padding:10px 18px;border:0;border-radius:6px;background:#222;color:#fff;font-weight:700;cursor:pointer}a{color:inherit}
</style>
</head>
<body>
<main>
<section>
<h1>Updater更新の反映</h1>
<?php foreach ($criticalErrors as $error): ?>
<p class="notice critical"><?= h((string) $error) ?></p>
<?php endforeach; ?>
<?php foreach ($errors as $error): ?>
<p class="notice error"><?= h((string) $error) ?></p>
<?php endforeach; ?>
<?php foreach ($warnings as $warning): ?>
<p class="notice warning"><?= h((string) $warning) ?></p>
<?php endforeach; ?>
<?php foreach ($messages as $message): ?>
<p class="notice success"><?= h((string) $message) ?></p>
<?php endforeach; ?>
<?php if ($pending): ?>
<p>署名済みUpdate ZIPで受け取ったUpdater本体の更新が、反映待ちです。</p>
<p>管理認証後に反映してください。現在のUpdaterは先にバックアップされ、置換に失敗した場合は自動復元されます。</p>
<form method="post">
<input type="hidden" name="_token" value="<?= h((string) $_SESSION['tomos_updater_finalize_token']) ?>">
<?php if (empty($_SESSION['tomos_post_authenticated'])): ?>
<label for="post_password">管理用合言葉</label>
<input id="post_password" name="post_password" type="password" autocomplete="current-password" required>
<label><input name="remember_post_auth" type="checkbox" value="1"> このブラウザで30日間、合言葉の入力を省略する</label>
<?php endif; ?>
<button type="submit">Updater更新を反映する</button>
</form>
<?php else: ?>
<p>反映待ちのUpdater更新はありません。</p>
<?php endif; ?>
<p><a href="../">Tomos Postへ戻る</a></p>
</section>
</main>
</body>
</html>
<?php
function clientIp(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
}
