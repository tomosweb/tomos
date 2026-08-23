<?php

declare(strict_types=1);

if (!function_exists('proc_open') || !class_exists('DOMDocument')) {
    fwrite(STDERR, "SKIP: proc_open or DOMDocument is unavailable.\n");
    exit(2);
}

$sourceRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-site-settings-http-' . bin2hex(random_bytes(8));
$docRoot = $testRoot . DIRECTORY_SEPARATOR . 'htdocs';
$installRoot = $docRoot . DIRECTORY_SEPARATOR . 'theme-labo';
$server = null;

try {
    copyTree($sourceRoot, $installRoot);
    foreach (['storage', 'cache', 'trash'] as $directory) {
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
    $config['site']['url'] = 'http://127.0.0.1:' . $port . '/theme-labo';
    $config['site']['base_path'] = '/theme-labo';
    $config['site']['public_base_path'] = '/theme-labo';
    $config['paths']['content_dir'] = $installRoot . '/content';
    $config['paths']['cache_dir'] = $installRoot . '/cache';
    $config['paths']['theme_dir'] = $installRoot . '/themes';
    $config['security']['post_password_hash'] = password_hash('test-password', PASSWORD_DEFAULT);
    $config['security']['rate_limit_salt'] = bin2hex(random_bytes(16));
    $config['setup_completed'] = true;
    file_put_contents($installRoot . '/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");

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

    $baseUrl = 'http://127.0.0.1:' . $port . '/theme-labo';
    waitForServer($baseUrl . '/post/');
    $cookie = $testRoot . '/cookies.txt';

    $postHome = request($baseUrl . '/post/', $cookie);
    assertSame(200, $postHome['status'], 'Tomos Post initial response');
    $login = request($baseUrl . '/post/?post_api=start', $cookie, [
        '_token' => hiddenValue($postHome['body'], '_token'),
        'post_password' => 'test-password',
        'expected_images' => '[]',
        'submission_id' => hiddenValue($postHome['body'], 'submission_id'),
    ]);
    assertSame(200, $login['status'], 'Tomos Post login response');
    $loginJson = json_decode($login['body'], true);
    assertTrue(is_array($loginJson) && !empty($loginJson['ok']), 'normal Tomos Post login failed');
    assertTrue(stripos($postHome['headers'], 'path=/theme-labo/post/') !== false, 'session cookie path: ' . $postHome['headers']);

    $settingsHome = request($baseUrl . '/post/?section=settings', $cookie);
    assertSame(200, $settingsHome['status'], 'settings card response');
    assertSettingsNavigationSemantics($settingsHome['body']);
    assertContains('href="/theme-labo/post/site-settings.php"', $settingsHome['body'], 'rendered Site Settings href');
    assertContains('href="/theme-labo/post/theme/"', $settingsHome['body'], 'rendered Theme href');

    $site = request($baseUrl . '/post/site-settings.php', $cookie);
    assertSame(200, $site['status'], 'Site Settings status');
    assertSame($baseUrl . '/post/site-settings.php', $site['url'], 'Site Settings final URL');
    assertContains('サイト設定', $site['body'], 'Site Settings response marker');
    assertNotContains('Fatal error', $site['body'], 'Site Settings fatal error');

    $theme = request($baseUrl . '/post/theme/', $cookie);
    assertSame(200, $theme['status'], 'Theme status');
    assertSame($baseUrl . '/post/theme/', $theme['url'], 'Theme final URL');
    assertContains('テーマを切り替える', $theme['body'], 'Theme response marker');
    assertNotContains('Fatal error', $theme['body'], 'Theme fatal error');

    $legacy = request($baseUrl . '/post/settings/', $cookie);
    assertSame(200, $legacy['status'], 'legacy Site Settings status');
    assertContains('サイト設定', $legacy['body'], 'legacy Site Settings response marker');

    echo "site_settings_subdirectory_http_check: fresh /theme-labo/ login, rendered links, status, cookies, final URLs, control Theme, and legacy bookmark passed\n";
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
    $prefix = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-http-response-' . bin2hex(random_bytes(6));
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

function assertNotContains(string $needle, string $haystack, string $label): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($label . ': found ' . $needle);
    }
}

function assertTrue(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

function assertSettingsNavigationSemantics(string $html): void
{
    $document = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        throw new RuntimeException('settings page HTML could not be parsed');
    }

    $links = [];
    foreach ($document->getElementsByTagName('a') as $anchor) {
        $classAttribute = $anchor->attributes->getNamedItem('class');
        $className = $classAttribute instanceof DOMAttr ? (string) $classAttribute->nodeValue : '';
        if (preg_match('/(?:^|\\s)settings-link(?:\\s|$)/', $className) === 1) {
            $links[] = $anchor;
        }
    }
    if (count($links) < 2) {
        throw new RuntimeException('settings navigation cards are missing');
    }

    foreach ($links as $anchor) {
        if (strtolower($anchor->nodeName) !== 'a') {
            throw new RuntimeException('settings navigation control is not an anchor');
        }
        foreach (['onclick', 'onmousedown', 'onmouseup', 'ontouchstart'] as $attribute) {
            if ($anchor->hasAttribute($attribute)) {
                throw new RuntimeException('settings navigation anchor has inline click interception: ' . $attribute);
            }
        }
        for ($parent = $anchor->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (in_array(strtolower($parent->tagName), ['form', 'button'], true)) {
                throw new RuntimeException('settings navigation anchor is nested in an interactive control');
            }
        }
        $href = (string) $anchor->getAttribute('href');
        if ($href === '' || strpos($href, '#') === 0) {
            throw new RuntimeException('settings navigation anchor is not natively navigable');
        }
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
