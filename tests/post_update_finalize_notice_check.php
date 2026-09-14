<?php

declare(strict_types=1);

$sourceRoot = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/tomos-post-update-finalize-notice-' . bin2hex(random_bytes(8));
$fixture = $tmp . '/fixture';
$sessionDir = $tmp . '/sessions';
$runner = $tmp . '/render-page.php';

function finalizeCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeFinalizeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeFinalizeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

function renderFinalizePage(string $runner, string $fixture, string $sessions, string $route, string $session, bool $authenticated, string $method = 'GET', string $token = ''): string
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner)
        . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($sessions)
        . ' ' . escapeshellarg($route) . ' ' . escapeshellarg($session)
        . ' ' . escapeshellarg($authenticated ? '1' : '0') . ' ' . escapeshellarg($method) . ' ' . escapeshellarg($token);
    $output = [];
    $status = 0;
    exec($command, $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('page runner failed for ' . $route . ': ' . implode("\n", $output));
    }
    return implode("\n", $output);
}

try {
    finalizeCheck(mkdir($fixture, 0700, true) && mkdir($sessionDir, 0700, true), 'could not create fixture');
    $archive = 'git -C ' . escapeshellarg($sourceRoot) . ' archive HEAD | tar -x -C ' . escapeshellarg($fixture);
    passthru($archive, $archiveStatus);
    finalizeCheck($archiveStatus === 0, 'could not archive fixture');
    finalizeCheck(copy($sourceRoot . '/post/index.php', $fixture . '/post/index.php'), 'could not overlay Post entry point');
    finalizeCheck(copy($sourceRoot . '/core/TomosMessageRecurrence.php', $fixture . '/core/TomosMessageRecurrence.php'), 'could not overlay Tomos Message recurrence runtime');
    finalizeCheck(copy($sourceRoot . '/post/update-finalize/index.php', $fixture . '/post/update-finalize/index.php'), 'could not overlay finalize entry point');
    $config = <<<'PHP'
<?php
return [
    'site' => ['public_base_path' => '/subdirectory'],
    'features' => ['post' => true],
    'security' => ['post_password_hash' => '$2y$10$7AVYEmtR0vEoDwpi4WKC7OPM59B4UVtt9M2lqBxpY9tNPvFy8wRkG'],
];
PHP;
    finalizeCheck(file_put_contents($fixture . '/config.php', $config, LOCK_EX) !== false, 'could not create config');
    $runnerSource = <<<'PHP'
<?php
declare(strict_types=1);
[$script, $root, $sessionDir, $route, $sessionId, $authenticated, $method, $token] = $argv;
session_save_path($sessionDir);
session_id($sessionId);
session_start();
if ($authenticated === '1') {
    $_SESSION['tomos_post_authenticated'] = true;
}
session_write_close();
$_GET = [];
$_POST = $method === 'POST' ? ['_token' => $token] : [];
$_COOKIE = [];
$_FILES = [];
$_SERVER = ['REQUEST_METHOD' => $method, 'SCRIPT_NAME' => '/subdirectory/' . $route, 'REMOTE_ADDR' => '127.0.0.1'];
require $root . '/' . $route;
PHP;
    finalizeCheck(file_put_contents($runner, $runnerSource, LOCK_EX) !== false, 'could not create runner');
    foreach (['storage/update-tmp', 'storage/update-backups', 'storage/update-logs'] as $directory) {
        finalizeCheck(mkdir($fixture . '/' . $directory, 0700, true), 'could not create runtime directory');
    }
    $pending = $fixture . '/core/updater-pending';
    finalizeCheck(mkdir($pending, 0700, true), 'could not create pending directory');
    $payload = $pending . '/update-index.php';
    finalizeCheck(copy($fixture . '/update/index.php', $payload), 'could not create pending payload');
    $metadata = json_encode(['target' => 'update/index.php', 'sha256' => hash_file('sha256', $payload)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    finalizeCheck(is_string($metadata) && file_put_contents($pending . '/update-index.json', $metadata, LOCK_EX) !== false, 'could not create pending metadata');

    $post = renderFinalizePage($runner, $fixture, $sessionDir, 'post/index.php', 'post-' . bin2hex(random_bytes(6)), true);
    finalizeCheck(strpos($post, 'Tomos Updateの仕上げが必要です。') !== false, 'Post did not render pending completion notice');
    finalizeCheck(strpos($post, '更新の仕上げが残っています。') !== false, 'Post did not render approved notice copy');
    finalizeCheck(strpos($post, 'href="/subdirectory/post/update-finalize/"') !== false, 'Post finalize URL is not subdirectory safe');

    $guest = renderFinalizePage($runner, $fixture, $sessionDir, 'post/update-finalize/index.php', 'guest-' . bin2hex(random_bytes(6)), false);
    foreach (['Tomos Updateを完了します', '更新の仕上げが残っています。', '「更新を完了する」を押してください。', '>更新を完了する</button>', 'Tomos Postへ戻る', 'name="post_password"'] as $required) {
        finalizeCheck(strpos($guest, $required) !== false, 'guest finalize missing: ' . $required);
    }

    $session = 'auth-' . bin2hex(random_bytes(6));
    $authenticated = renderFinalizePage($runner, $fixture, $sessionDir, 'post/update-finalize/index.php', $session, true);
    finalizeCheck(strpos($authenticated, 'name="post_password"') === false, 'authenticated finalize requested passphrase');
    finalizeCheck(preg_match('/name="_token" value="([^"]+)"/', $authenticated, $matches) === 1, 'authenticated finalize lacks CSRF token');
    $completed = renderFinalizePage($runner, $fixture, $sessionDir, 'post/update-finalize/index.php', $session, true, 'POST', $matches[1]);
    finalizeCheck(strpos($completed, 'Tomos Updateがすべて完了しました') !== false, 'finalize completion heading missing');
    finalizeCheck(strpos($completed, 'Tomosの更新が完了しました。') !== false, 'finalize completion copy missing');
    finalizeCheck(!is_dir($pending), 'finalize did not remove pending payload');
    echo "post_update_finalize_notice_check: OK\n";
} finally {
    removeFinalizeTree($tmp);
}
