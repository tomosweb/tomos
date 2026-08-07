<?php

declare(strict_types=1);

if (!class_exists(ZipArchive::class) || !function_exists('proc_open')) {
    fwrite(STDERR, "SKIP: HTTP integration prerequisites are unavailable.\n");
    exit(2);
}

$sourceRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-theme-http-' . bin2hex(random_bytes(8));
$process = null;

try {
    copyTree($sourceRoot, $testRoot);
    foreach (['storage', 'cache', 'trash'] as $directory) {
        if (!is_dir($testRoot . DIRECTORY_SEPARATOR . $directory)) {
            mkdir($testRoot . DIRECTORY_SEPARATOR . $directory, 0700, true);
        }
    }
    $config = require $testRoot . '/config.sample.php';
    $config['site']['url'] = 'http://127.0.0.1';
    $config['paths']['content_dir'] = $testRoot . '/content';
    $config['paths']['cache_dir'] = $testRoot . '/cache';
    $config['paths']['theme_dir'] = $testRoot . '/themes';
    $config['security']['post_password_hash'] = password_hash('test-password', PASSWORD_DEFAULT);
    $config['security']['rate_limit_salt'] = bin2hex(random_bytes(16));
    $config['setup_completed'] = true;
    file_put_contents($testRoot . '/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");

    $zipPath = $testRoot . '/theme.zip';
    makeThemeZip($zipPath);
    $phpZipPath = $testRoot . '/theme-with-php.zip';
    makeThemeZip($phpZipPath, true);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if (!is_resource($socket)) {
        throw new RuntimeException('cannot reserve local port: ' . $errorMessage);
    }
    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1);
    $baseUrl = 'http://127.0.0.1:' . $port;
    $logPath = $testRoot . '/php-server.log';
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ];
    $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $testRoot], $descriptor, $pipes, $testRoot);
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start PHP server');
    }
    fclose($pipes[0]);
    waitForServer($baseUrl);

    $cookie = $testRoot . '/cookies.txt';
    $loginPage = curlRequest($baseUrl . '/post/', $cookie);
    $postToken = csrfToken($loginPage);
    $submissionId = hiddenValue($loginPage, 'submission_id');
    $login = curlRequest($baseUrl . '/post/?post_api=start', $cookie, [
        '_token' => $postToken,
        'post_password' => 'test-password',
        'expected_images' => '[]',
        'submission_id' => $submissionId,
    ]);
    $loginJson = json_decode($login, true);
    assertTrue(is_array($loginJson) && !empty($loginJson['ok']), 'Tomos Post authentication failed');

    $themePage = curlRequest($baseUrl . '/post/theme/', $cookie);
    assertContains('テーマZIPを追加', $themePage);
    assertContains('tomos-minimal', $themePage);
    $uploadPage = curlRequest($baseUrl . '/post/theme/add/', $cookie);
    assertContains('テーマZIPの上限：最大10 MB', $uploadPage);
    $uploadToken = csrfToken($uploadPage);

    $inspectionPage = curlMultipart($baseUrl . '/post/theme/add/', $cookie, [
        '_token' => $uploadToken,
        'theme_zip' => new CURLFile($zipPath, 'application/zip', 'tomos-theme-http-test-1.0.0.zip'),
    ]);
    assertContains('テーマZIPの検査が完了しました', $inspectionPage);
    assertContains('tomos-http-test', $inspectionPage);
    assertTrue(!is_dir($testRoot . '/themes/tomos-http-test'), 'theme exists before confirmation');
    $confirmToken = csrfToken($inspectionPage);

    $resultPage = curlRequest($baseUrl . '/post/theme/add/confirm/', $cookie, ['_token' => $confirmToken]);
    assertContains('テーマを追加しました。', $resultPage);
    assertTrue(is_dir($testRoot . '/themes/tomos-http-test'), 'theme missing after confirmation');
    assertTrue(!file_exists($testRoot . '/storage/theme-upload.lock'), 'theme lock remains');
    $temporaryItems = is_dir($testRoot . '/storage/theme-upload-tmp')
        ? array_values(array_diff(scandir($testRoot . '/storage/theme-upload-tmp') ?: [], ['.', '..']))
        : [];
    assertSame([], $temporaryItems, 'temporary package remains');

    $afterAdd = curlRequest($baseUrl . '/post/theme/', $cookie);
    assertContains('tomos-http-test', $afterAdd);
    $themeToken = csrfToken($afterAdd);
    $switchConfirm = curlRequest($baseUrl . '/post/theme/confirm/', $cookie, [
        '_token' => $themeToken,
        'theme_name' => 'tomos-http-test',
    ]);
    assertContains('テーマ変更確認', $switchConfirm);
    $switchToken = csrfToken($switchConfirm);
    $switched = curlRequest($baseUrl . '/post/theme/confirm/', $cookie, [
        '_token' => $switchToken,
        'theme_name' => 'tomos-http-test',
        'action' => 'apply',
    ]);
    assertContains('テーマを変更しました。', $switched);
    $publicPage = curlRequest($baseUrl . '/', $cookie);
    assertContains('<!doctype html>', strtolower($publicPage));

    $doubleSubmit = curlRequest($baseUrl . '/post/theme/add/confirm/', $cookie, ['_token' => $confirmToken]);
    assertContains('有効期限が切れました', $doubleSubmit);
    $matchingThemes = glob($testRoot . '/themes/tomos-http-test') ?: [];
    assertTrue(count($matchingThemes) === 1, 'duplicate theme was created');

    $badCsrf = curlRequest($baseUrl . '/post/theme/add/', $cookie, ['_token' => 'invalid']);
    assertContains('フォームの有効期限が切れました', $badCsrf);
    assertNotContains($testRoot, $badCsrf);

    $invalidUploadPage = curlRequest($baseUrl . '/post/theme/add/', $cookie);
    $invalidUploadToken = csrfToken($invalidUploadPage);
    $phpErrorPage = curlMultipart($baseUrl . '/post/theme/add/', $cookie, [
        '_token' => $invalidUploadToken,
        'theme_zip' => new CURLFile($phpZipPath, 'application/zip', 'theme.zip'),
    ]);
    assertContains('テーマにはPHPファイルを含められません。', $phpErrorPage);
    assertNotContains($testRoot, $phpErrorPage);
    assertTrue(!is_dir($testRoot . '/themes/tomos-php-test'), 'rejected theme was installed');

    $unauthenticatedCookie = $testRoot . '/unauthenticated-cookies.txt';
    $headers = curlHeaders($baseUrl . '/post/theme/add/', $unauthenticatedCookie);
    assertContains('HTTP/1.1 302', $headers);
    assertContains('/post/', $headers);

    $postHome = curlRequest($baseUrl . '/post/', $cookie);
    assertNotContains('Fatal error', $postHome);
    $settingsPage = curlRequest($baseUrl . '/post/settings/', $cookie);
    assertNotContains('Fatal error', $settingsPage);
    $updatePage = curlRequest($baseUrl . '/update/', $cookie);
    assertNotContains('Fatal error', $updatePage);
    echo "theme_package_http_check: browser upload, confirmation, listing, switch, public render, CSRF and double-submit passed\n";
} catch (Throwable $exception) {
    if (is_file($testRoot . '/php-server.log')) {
        fwrite(STDERR, (string) file_get_contents($testRoot . '/php-server.log'));
    }
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    removeTree($testRoot);
}

function waitForServer(string $baseUrl): void
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $output = [];
        $status = 0;
        exec('curl -sS --max-time 1 ' . escapeshellarg($baseUrl . '/') . ' >/dev/null 2>&1', $output, $status);
        if ($status === 0) {
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException('PHP server did not start');
}

function curlRequest(string $url, string $cookie, ?array $fields = null): string
{
    $command = ['curl', '-sS', '-L', '-c', $cookie, '-b', $cookie];
    if (is_array($fields)) {
        $command[] = '--data';
        $command[] = http_build_query($fields);
    }
    $command[] = $url;
    return runCommand($command);
}

function curlMultipart(string $url, string $cookie, array $fields): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is unavailable');
    }
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $response = curl_exec($handle);
    $error = curl_error($handle);
    if (!is_string($response)) {
        throw new RuntimeException('multipart upload failed: ' . $error);
    }
    return $response;
}

function curlHeaders(string $url, string $cookie): string
{
    return runCommand(['curl', '-sS', '-D', '-', '-o', '/dev/null', '-c', $cookie, '-b', $cookie, $url]);
}

function runCommand(array $arguments): string
{
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    $output = [];
    $status = 0;
    exec($command, $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('command failed: ' . $arguments[0]);
    }
    return implode("\n", $output);
}

function hiddenValue(string $html, string $name): string
{
    $quotedName = preg_quote($name, '/');
    if (preg_match(
        '/<input[^>]+name=["\']' . $quotedName . '["\'][^>]+value=["\']([^"\']*)["\']/i',
        $html,
        $matches
    ) !== 1) {
        throw new RuntimeException('hidden field not found: ' . $name);
    }

    return html_entity_decode((string) $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function csrfToken(string $html): string
{
    if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $html, $matches) !== 1) {
        throw new RuntimeException('CSRF token was not found');
    }
    return $matches[1];
}

function makeThemeZip(string $path, bool $includePhp = false): void
{
    $entries = [
        'tomos-http-test/theme.json' => json_encode([
            'name' => 'tomos-http-test',
            'display_name' => 'Tomos HTTP Test',
            'version' => '1.0.0',
            'description' => 'HTTP fixture',
            'author' => 'Tomos',
        ], JSON_UNESCAPED_SLASHES),
        'tomos-http-test/templates/layout.html' => '<!doctype html><html><body>{{{ page.body }}}</body></html>',
        'tomos-http-test/templates/page.html' => '<article><h1>{{ page.title }}</h1>{{{ page.content }}}</article>',
        'tomos-http-test/templates/list.html' => '<main><h1>{{ page.title }}</h1>{{{ list.pages }}}</main>',
        'tomos-http-test/assets/style.css' => 'body { color: #222; }',
        'tomos-http-test/preview.png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        'tomos-http-test/README.md' => "# HTTP test\n",
        'tomos-http-test/LICENSE' => "Test license\n",
    ];
    if ($includePhp) {
        $renamed = [];
        foreach ($entries as $name => $content) {
            $renamed[str_replace('tomos-http-test', 'tomos-php-test', $name)] = $content;
        }
        $entries = $renamed;
        $theme = json_decode((string) $entries['tomos-php-test/theme.json'], true);
        $theme['name'] = 'tomos-php-test';
        $entries['tomos-php-test/theme.json'] = json_encode($theme, JSON_UNESCAPED_SLASHES);
        $entries['tomos-php-test/assets/unsafe.PHP'] = '<?php echo "unsafe";';
    }
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, (string) $content);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
    }
    $zip->close();
}

function copyTree(string $source, string $destination): void
{
    if (is_dir($source)) {
        mkdir($destination, 0755, true);
        foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $item) {
            if ($item === '.git' || $item === 'build') {
                continue;
            }
            copyTree($source . DIRECTORY_SEPARATOR . $item, $destination . DIRECTORY_SEPARATOR . $item);
        }
        return;
    }
    copy($source, $destination);
}

function assertContains(string $needle, string $haystack): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException('response does not contain: ' . $needle);
    }
}

function assertNotContains(string $needle, string $haystack): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException('response contains forbidden text: ' . $needle);
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
