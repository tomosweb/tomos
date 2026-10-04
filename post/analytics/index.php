<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/ui.php';

session_start();

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) return;
    $relativeClass = substr($class, strlen($prefix));
    $file = dirname(__DIR__, 2) . '/core/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) require_once $file;
});

$rootDir = dirname(__DIR__, 2);
$configPath = $rootDir . '/config.php';
$config = [];
if (is_file($configPath)) {
    $loadedConfig = require $configPath;
    $config = is_array($loadedConfig) ? $loadedConfig : [];
}

$publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
$authRemember = new Tomos\PostAuthRememberToken($config, $rootDir);
if ($config === [] || !$authRemember->restoreSession()) {
    header('Location: ' . Tomos\Security::publicUrl('/post/', $publicBasePath) . '?section=settings&return_to=' . rawurlencode('/post/analytics/'));
    exit;
}

if (empty($_SESSION['tomos_post_settings_token'])) {
    $_SESSION['tomos_post_settings_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$messages = [];
$warnings = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_token'] ?? '');
    if (class_exists(Tomos\UpdateLock::class) && Tomos\UpdateLock::isActive($rootDir)) {
        $errors[] = 'Tomosの更新中です。完了してからもう一度操作してください。';
    } elseif ($token === '' || !hash_equals((string) $_SESSION['tomos_post_settings_token'], $token)) {
        $errors[] = 'フォームの有効期限が切れました。もう一度送信してください。';
    } else {
        [$newConfig, $updateErrors] = Tomos\AnalyticsConfigWriter::update($config, (string) ($_POST['ga4_measurement_id'] ?? ''));
        if ($updateErrors !== []) {
            $errors = array_merge($errors, $updateErrors);
        } elseif (!Tomos\ConfigWriter::write($configPath, $newConfig, $rootDir)) {
            $errors[] = 'config.php を更新できませんでした。';
        } else {
            $config = $newConfig;
            $cache = new Tomos\HtmlCache((string) ($config['paths']['cache_dir'] ?? ($rootDir . DIRECTORY_SEPARATOR . 'cache')), true);
            if (!$cache->clearGenerated()) {
                $warnings[] = 'HTMLキャッシュを削除できませんでした。表示が古い場合は cache/html/ を確認してください。';
            }
            $messages[] = Tomos\Ga4::measurementId($config) === ''
                ? 'Google Analytics 4による計測を無効にしました。'
                : 'Google Analytics 4の測定IDを更新しました。';
            $_SESSION['tomos_post_settings_token'] = bin2hex(random_bytes(32));
        }
    }
}

$token = (string) $_SESSION['tomos_post_settings_token'];
$postSettingsUrl = Tomos\Security::publicUrl('/post/?section=settings', $publicBasePath);
$measurementId = (string) ($config['analytics']['ga4_measurement_id'] ?? '');

echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>Google Analytics設定</title><link rel="stylesheet" href="' . e(Tomos\Security::publicUrl('/post/assets/tomos-post-ui.css', $publicBasePath)) . '"></head><body><main class="wrap">';
echo '<header class="page-heading"><h1>' . tomosPostIcon('analytics') . '<span>Google Analytics</span></h1><p class="hint">サイトのアクセス解析に使用します。</p></header>';
if ($errors !== []) {
    echo '<div class="errors" role="alert"><strong>設定を保存できませんでした。</strong><ul>';
    foreach ($errors as $error) echo '<li>' . e((string) $error) . '</li>';
    echo '</ul></div>';
} elseif ($messages !== []) {
    echo '<div class="success" role="status" aria-live="polite"><ul>';
    foreach ($messages as $message) echo '<li>' . e((string) $message) . '</li>';
    echo '</ul></div>';
}
if ($warnings !== []) {
    echo '<div class="notice" role="status" aria-live="polite"><strong>注意</strong><ul>';
    foreach ($warnings as $warning) echo '<li>' . e((string) $warning) . '</li>';
    echo '</ul></div>';
}
echo '<form method="post" action="">';
echo '<input type="hidden" name="_token" value="' . e($token) . '">';
echo '<label for="ga4_measurement_id">GA4測定ID（任意）</label>';
echo '<input id="ga4_measurement_id" type="text" name="ga4_measurement_id" value="' . e($measurementId) . '" placeholder="G-XXXXXXXXXX" autocomplete="off" autocapitalize="characters" spellcheck="false">';
echo '<details class="technical-note"><summary>測定IDについて</summary><p class="hint">Google Analytics 4の <code>G-</code> から始まるIDを入力します。タグ全体やJavaScriptは入力しません。空欄で保存すると計測を停止します。テーマを変更しても設定は維持されます。</p></details>';
echo '<div class="actions"><button type="submit">' . tomosPostIcon('check') . '<span>保存</span></button></div></form>';
echo tomosPostReturnLink($postSettingsUrl);
echo '</main></body></html>';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
