<?php

declare(strict_types=1);

if (!function_exists('proc_open')) {
    fwrite(STDERR, "SKIP: proc_open is unavailable.\n");
    exit(2);
}

$sourceRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-daily-message-http-' . bin2hex(random_bytes(8));
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
    file_put_contents($installRoot . '/config.php', "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX);
    file_put_contents($installRoot . '/content/runtime-message.md', "---\ntitle: Runtime Message\ndate: 2026-09-11\n---\nRuntime message fixture.\n", LOCK_EX);

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

    $cookie = $testRoot . '/first-session.txt';
    $auth = request($baseUrl . '/post/', $cookie);
    assertSame(200, $auth['status'], 'initial auth wall status');
    $login = request($baseUrl . '/post/', $cookie, [
        'action' => 'auth_gate_login',
        '_token' => hiddenValue($auth['body'], '_token'),
        'post_password' => 'test-password',
    ]);
    assertSame(302, $login['status'], 'initial login status');
    assertContains('location: /tomos-edit/post/', strtolower($login['headers']), 'initial login destination');

    $first = request($baseUrl . '/post/', $cookie);
    assertSame(1, messageCount($first['body']), 'first authenticated Upload must show one message');
    assertContains('class="tomos-message"', $first['body'], 'first Upload message markup');
    assertSame(1, fontLinkCount($first['body']), 'first Upload must load Klee One exactly once');
    assertTrue(strpos($first['body'], 'class="tomos-message"') < strpos($first['body'], 'class="nav"'), 'first Upload message must appear before navigation');
    assertContains('viewBox="0 0 88 56"', $first['body'], 'first Upload must render the curved airplane treatment');
    assertNotContains('stroke-dasharray', $first['body'], 'first Upload must not render a dotted airplane trajectory');
    assertNotContains('Tomos Writeなどで作成したMarkdownファイルをTomosに投稿し、必要に応じて投稿済みページをWeb上から外します。', $first['body'], 'old Post description must not be rendered');
    assertNotContains('TOMOS MESSAGE', $first['body'], 'Tomos Message heading must not be rendered');
    assertContains('1. Markdownを投稿する', $first['body'], 'first Upload section');

    $reload = request($baseUrl . '/post/', $cookie);
    assertSame(0, messageCount($reload['body']), 'same-session reload must hide the message');
    assertSame(0, fontLinkCount($reload['body']), 'same-session reload must not load Klee One');
    foreach (['published', 'drafts', 'settings', 'upload'] as $section) {
        $page = request($baseUrl . '/post/?section=' . rawurlencode($section), $cookie);
        assertSame(0, messageCount($page['body']), 'same-session section must hide the message: ' . $section);
        assertSame(0, fontLinkCount($page['body']), 'same-session section must not load Klee One: ' . $section);
    }

    $logoutPage = request($baseUrl . '/post/', $cookie);
    $logout = request($baseUrl . '/post/', $cookie, [
        'action' => 'logout',
        '_token' => hiddenValue($logoutPage['body'], '_token'),
    ]);
    assertSame(302, $logout['status'], 'same-session logout status');
    $reAuth = request($baseUrl . '/post/', $cookie);
    $reLogin = request($baseUrl . '/post/', $cookie, [
        'action' => 'auth_gate_login',
        '_token' => hiddenValue($reAuth['body'], '_token'),
        'post_password' => 'test-password',
    ]);
    assertSame(302, $reLogin['status'], 'same-session relogin status');
    assertSame(0, messageCount(request($baseUrl . '/post/', $cookie)['body']), 'logout and relogin in the same PHP session must not show the message again');

    $newCookie = $testRoot . '/new-session.txt';
    $newAuth = request($baseUrl . '/post/', $newCookie);
    $newLogin = request($baseUrl . '/post/', $newCookie, [
        'action' => 'auth_gate_login',
        '_token' => hiddenValue($newAuth['body'], '_token'),
        'post_password' => 'test-password',
    ]);
    assertSame(302, $newLogin['status'], 'new-session login status');
    $newFirst = request($baseUrl . '/post/', $newCookie);
    assertSame(1, messageCount($newFirst['body']), 'new PHP session must be eligible for one message');
    assertSame(1, fontLinkCount($newFirst['body']), 'new PHP session first Upload must load Klee One');

    echo "daily_use_message_http_check: PASS\n";
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

function messageCount(string $body): int
{
    return substr_count($body, 'class="tomos-message"');
}

function fontLinkCount(string $body): int
{
    return substr_count($body, 'fonts.googleapis.com/css2?family=Klee+One&display=swap');
}

function request(string $url, string $cookie, ?array $fields = null): array
{
    $prefix = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-daily-message-response-' . bin2hex(random_bytes(6));
    $headerPath = $prefix . '.headers';
    $bodyPath = $prefix . '.body';
    $command = ['curl', '-sS', '--max-time', '10', '-D', $headerPath, '-o', $bodyPath, '-c', $cookie, '-b', $cookie];
    if ($fields !== null) {
        $command[] = '--data';
        $command[] = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }
    $command[] = $url;
    $command[] = '-w';
    $command[] = "\n__STATUS__%{http_code}";
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
    foreach ($output as $line) {
        if (strpos($line, '__STATUS__') === 0) {
            $status = (int) substr($line, 10);
        }
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $body];
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

function assertNotContains(string $needle, string $haystack, string $label): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($label . ': unexpected ' . $needle);
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
