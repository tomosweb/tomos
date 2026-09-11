<?php

declare(strict_types=1);

if (!function_exists('proc_open')) {
    fwrite(STDERR, "SKIP: proc_open is unavailable.\n");
    exit(2);
}

$sourceRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-write-handoff-http-' . bin2hex(random_bytes(8));
$docRoot = $testRoot . DIRECTORY_SEPARATOR . 'htdocs';
$installRoot = $docRoot . DIRECTORY_SEPARATOR . 'tomos-edit';
$server = null;

try {
    copyTree($sourceRoot, $installRoot);
    foreach (['storage', 'cache', 'trash', 'content'] as $directory) {
        if (!is_dir($installRoot . DIRECTORY_SEPARATOR . $directory)) {
            mkdir($installRoot . DIRECTORY_SEPARATOR . $directory, 0700, true);
        }
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!is_resource($socket)) {
        throw new RuntimeException('could not reserve local port: ' . $error);
    }
    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1);

    $config = require $installRoot . '/config.sample.php';
    $config['site']['url'] = 'http://127.0.0.1:' . $port . '/tomos-edit';
    $config['site']['base_path'] = '/tomos-edit';
    $config['site']['public_base_path'] = '/tomos-edit';
    $config['paths']['content_dir'] = $installRoot . '/content';
    $config['paths']['cache_dir'] = $installRoot . '/cache';
    $config['paths']['theme_dir'] = $installRoot . '/themes';
    $config['features']['html_cache'] = false;
    $config['features']['metadata_cache'] = false;
    $config['security']['post_password_hash'] = password_hash('test-password', PASSWORD_DEFAULT);
    $config['security']['rate_limit_salt'] = bin2hex(random_bytes(16));
    $config['setup_completed'] = true;
    file_put_contents($installRoot . '/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    file_put_contents(
        $installRoot . '/content/write-handoff-test.md',
        "---\ntitle: Write Handoff Test\ndate: 2026-09-11\n---\nWrite handoff regression body.\n"
    );

    foreach (glob($installRoot . '/cache/index/*') ?: [] as $file) {
        @unlink($file);
    }

    $logPath = $testRoot . '/php-server.log';
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docRoot],
        [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
        $pipes,
        $docRoot
    );
    if (!is_resource($server)) {
        throw new RuntimeException('could not start PHP server');
    }
    fclose($pipes[0]);

    $baseUrl = 'http://127.0.0.1:' . $port . '/tomos-edit';
    waitForServer($baseUrl . '/post/');
    $cookie = $testRoot . '/cookies.txt';

    $authPage = request($baseUrl . '/post/', $cookie);
    assertSame(200, $authPage['status'], 'auth wall status');
    assertContains('name="action" value="auth_gate_login"', $authPage['body'], 'auth wall form');

    $login = request($baseUrl . '/post/', $cookie, [
        'action' => 'auth_gate_login',
        '_token' => hiddenValue($authPage['body'], '_token'),
        'post_password' => 'test-password',
    ]);
    assertSame(302, $login['status'], 'auth wall login status');
    assertContains('location: /tomos-edit/post/', strtolower($login['headers']), 'auth wall login destination');

    $published = request($baseUrl . '/post/?section=published', $cookie);
    assertSame(200, $published['status'], 'published page status');
    assertContains('Write Handoff Test', $published['body'], 'published fixture');
    assertContains('class="secondary tomos-write-edit"', $published['body'], 'Tomos Write button');
    assertContains('src="/tomos-edit/post/assets/write-handoff.js"', $published['body'], 'base-aware Write script URL');

    $asset = request($baseUrl . '/post/assets/write-handoff.js', $cookie);
    assertSame(200, $asset['status'], 'Write handoff JS status');
    assertContains('const WRITE_URL = `${WRITE_ORIGIN}/write/`;', $asset['body'], 'Write handoff JS payload');

    $handoff = request($baseUrl . '/post/write-handoff.php', $cookie, [
        '_token' => hiddenValue($published['body'], '_token'),
        'content_path' => 'write-handoff-test.md',
    ]);
    assertSame(200, $handoff['status'], 'Write handoff endpoint status');
    $payload = json_decode($handoff['body'], true);
    assertTrue(is_array($payload) && !empty($payload['ok']), 'Write handoff endpoint payload');
    assertContains('Write handoff regression body.', (string) ($payload['markdown'] ?? ''), 'Write handoff Markdown payload');

    echo "write_handoff_subdirectory_http_check: auth session, published button, JS asset, and handoff JSON passed\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    if (is_file($testRoot . '/php-server.log')) {
        fwrite(STDERR, (string) file_get_contents($testRoot . '/php-server.log'));
    }
    exit(1);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    removeTree($testRoot);
}

function request(string $url, string $cookie, ?array $fields = null): array
{
    $prefix = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-write-handoff-response-' . bin2hex(random_bytes(6));
    $headerPath = $prefix . '.headers';
    $bodyPath = $prefix . '.body';
    $command = ['curl', '-sS', '--max-time', '10', '-D', $headerPath, '-o', $bodyPath, '-c', $cookie, '-b', $cookie];
    if ($fields !== null) {
        $command[] = '--data';
        $command[] = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }
    $command[] = $url;
    $command[] = '-w';
    $command[] = "\n__STATUS__%{http_code}\n__URL__%{url_effective}";
    $output = [];
    $exitCode = 0;
    exec(implode(' ', array_map('escapeshellarg', $command)), $output, $exitCode);
    $headers = is_file($headerPath) ? (string) file_get_contents($headerPath) : '';
    $body = is_file($bodyPath) ? (string) file_get_contents($bodyPath) : '';
    @unlink($headerPath);
    @unlink($bodyPath);
    if ($exitCode !== 0) {
        throw new RuntimeException('curl request failed for ' . $url);
    }
    $status = 0;
    $effectiveUrl = '';
    foreach ($output as $line) {
        if (strpos($line, '__STATUS__') === 0) {
            $status = (int) substr($line, 10);
        } elseif (strpos($line, '__URL__') === 0) {
            $effectiveUrl = substr($line, 7);
        }
    }
    return ['status' => $status, 'url' => $effectiveUrl, 'headers' => $headers, 'body' => $body];
}

function hiddenValue(string $html, string $name): string
{
    $pattern = '/<input[^>]+name="' . preg_quote($name, '/') . '"[^>]+value="([^"]*)"/';
    if (preg_match($pattern, $html, $matches) !== 1) {
        throw new RuntimeException('hidden field missing: ' . $name);
    }
    return html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack, string $label): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($label . ': missing ' . $needle);
    }
}

function assertTrue(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

function waitForServer(string $url): void
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $output = [];
        $status = 0;
        exec('curl -sS --max-time 1 ' . escapeshellarg($url) . ' >/dev/null 2>&1', $output, $status);
        if ($status === 0) {
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException('PHP server did not start');
}

function copyTree(string $source, string $destination): void
{
    if (!is_dir($destination)) {
        mkdir($destination, 0775, true);
    }
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.git' || $entry === 'build') {
            continue;
        }
        $from = $source . DIRECTORY_SEPARATOR . $entry;
        $to = $destination . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($from)) {
            copyTree($from, $to);
        } else {
            copy($from, $to);
        }
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
}
