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

function handoffResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    handoffResponse(['ok' => false, 'message' => 'この操作は利用できません。'], 405);
}

$rootDir = dirname(__DIR__);
$configPath = $rootDir . '/config.php';
if (!is_file($configPath)) {
    handoffResponse(['ok' => false, 'message' => 'Tomosの設定を確認できませんでした。'], 503);
}

$loadedConfig = require $configPath;
$config = is_array($loadedConfig) ? $loadedConfig : [];
if ($config === [] || empty($config['features']['post'])) {
    handoffResponse(['ok' => false, 'message' => 'Tomos Postを利用できません。'], 503);
}

if (empty($_SESSION['tomos_post_authenticated'])) {
    handoffResponse(['ok' => false, 'message' => 'Tomos Postの認証が必要です。公開済み一覧を開き直してください。'], 403);
}

$sessionToken = (string) ($_SESSION['tomos_post_token'] ?? '');
$requestToken = (string) ($_POST['_token'] ?? '');
if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
    handoffResponse(['ok' => false, 'message' => '画面の有効期限が切れました。公開済み一覧を再読み込みしてください。'], 403);
}

$contentPath = (string) ($_POST['content_path'] ?? '');
try {
    if (!(new Tomos\PostPublished($config, $rootDir))->isPublishedPath($contentPath)) {
        handoffResponse(['ok' => false, 'message' => '公開済み投稿を確認できませんでした。一覧を再読み込みしてください。'], 404);
    }

    $download = (new Tomos\PostEditableMarkdown($config, $rootDir))->download($contentPath);
} catch (Throwable $exception) {
    handoffResponse(['ok' => false, 'message' => '編集用Markdownを生成できませんでした。'], 500);
}

if (empty($download['ok'])) {
    handoffResponse([
        'ok' => false,
        'message' => (string) ($download['error'] ?? '編集用Markdownを生成できませんでした。'),
    ], 400);
}

$content = (string) ($download['content'] ?? '');
$downloadName = basename((string) ($download['download_name'] ?? 'download.md'));
if ($downloadName === '' || !preg_match('/\.(?:md|markdown|txt)$/i', $downloadName)) {
    $downloadName = 'download.md';
}

if (strlen($content) > 2 * 1024 * 1024) {
    handoffResponse(['ok' => false, 'message' => 'この記事はブラウザ連携の上限2MBを超えています。Markdownを取得して編集してください。'], 413);
}

handoffResponse([
    'ok' => true,
    'filename' => $downloadName,
    'markdown' => $content,
]);
