<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/tomos-post-session-cookie-' . bin2hex(random_bytes(6));
if (!mkdir($temp, 0775, true) && !is_dir($temp)) {
    fwrite(STDERR, "FAIL: temp directory could not be created\n");
    exit(1);
}

$server = null;
try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        throw new RuntimeException('could not reserve local port: ' . $errstr);
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($name) || strpos($name, ':') === false) {
        throw new RuntimeException('could not resolve local port');
    }
    $port = (int) substr(strrchr($name, ':'), 1);

    $classPath = var_export($root . '/core/PostAuthRememberToken.php', true);
    $cachePath = var_export($temp . '/cache', true);
    $fixture = <<<'PHP'
<?php
require __CLASS_PATH__;
session_set_cookie_params(['path' => '', 'httponly' => true, 'samesite' => 'Lax']);
session_start();
$_SESSION['tomos_post_authenticated'] = true;
$config = [
    'site' => [
        'url' => 'http://127.0.0.1',
        'base_path' => '/theme-labo',
        'public_base_path' => '',
    ],
    'paths' => [
        'cache_dir' => __CACHE_PATH__,
    ],
];
new Tomos\PostAuthRememberToken($config, dirname(__DIR__));
header('Content-Type: text/plain');
echo 'ok';
PHP;
    $fixture = str_replace('__CLASS_PATH__', $classPath, $fixture);
    $fixture = str_replace('__CACHE_PATH__', $cachePath, $fixture);
    file_put_contents($temp . '/index.php', $fixture);

    $command = sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($temp));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $temp . '/server.log', 'a'],
        2 => ['file', $temp . '/server.log', 'a'],
    ];
    $server = proc_open($command, $descriptors, $pipes);
    if (!is_resource($server)) {
        throw new RuntimeException('php test server could not be started');
    }
    fclose($pipes[0]);

    $headers = [];
    $body = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        usleep(100000);
        $body = @file_get_contents('http://127.0.0.1:' . $port . '/');
        if ($body !== false) {
            $headers = $http_response_header ?? [];
            break;
        }
    }
    if ($body !== 'ok') {
        throw new RuntimeException('fixture request failed');
    }

    $sessionCookie = '';
    foreach ($headers as $header) {
        if (stripos($header, 'Set-Cookie: ' . session_name() . '=') === 0) {
            $sessionCookie = $header;
        }
    }
    if ($sessionCookie === '') {
        throw new RuntimeException('PHP session Set-Cookie header was not emitted');
    }
    if (stripos($sessionCookie, 'Path=/theme-labo/post/') === false) {
        throw new RuntimeException('PHP session cookie was not scoped to the Tomos Post subtree: ' . $sessionCookie);
    }

    echo "post_admin_session_cookie_path_check: explicit Post subtree session cookie path passed\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    if (is_file($temp . '/server.log')) {
        fwrite(STDERR, (string) file_get_contents($temp . '/server.log'));
    }
    exit(1);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    removeTree($temp);
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}
