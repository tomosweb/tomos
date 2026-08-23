<?php

declare(strict_types=1);

if (!class_exists(ZipArchive::class) || !function_exists('proc_open') || !function_exists('curl_init')) {
    fwrite(STDERR, "SKIP: browser update prerequisites are unavailable.\n");
    exit(2);
}

$sourceRoot = dirname(__DIR__);
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-lab-browser-update-' . bin2hex(random_bytes(8));
$process = null;

try {
    copyTree($sourceRoot, $testRoot);
    $packageSource = $testRoot . '/theme-packages/tomos-lab';
    if (!is_dir($packageSource)) {
        throw new RuntimeException('distributable tomos-lab theme package source is missing');
    }
    if (!is_dir($testRoot . '/themes')) {
        mkdir($testRoot . '/themes', 0755, true);
    }
    copyTree($packageSource, $testRoot . '/themes/tomos-lab');

    foreach (['storage', 'cache', 'trash', 'theme-assets', 'content'] as $directory) {
        if (!is_dir($testRoot . '/' . $directory)) {
            mkdir($testRoot . '/' . $directory, 0755, true);
        }
    }

    $config = require $testRoot . '/config.sample.php';
    $config['site']['url'] = 'http://127.0.0.1';
    $config['paths']['content_dir'] = $testRoot . '/content';
    $config['paths']['cache_dir'] = $testRoot . '/cache';
    $config['paths']['theme_dir'] = $testRoot . '/themes';
    $config['theme']['name'] = 'tomos-lab';
    $config['security']['post_password_hash'] = password_hash('test-password', PASSWORD_DEFAULT);
    $config['security']['rate_limit_salt'] = bin2hex(random_bytes(16));
    $config['setup_completed'] = true;
    file_put_contents($testRoot . '/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    file_put_contents($testRoot . '/theme-settings.php', "<?php return ['hero'=>['title'=>'Browser Lab']];\n");
    file_put_contents($testRoot . '/theme-assets/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    file_put_contents($testRoot . '/content/index.md', "---\ntitle: Home\n---\n\nBrowser lab content.\n");

    $preserved = hashes($testRoot, ['config.php', 'theme-settings.php', 'theme-assets/logo.svg', 'content/index.md']);
    $zipPath = $testRoot . '/tomos-lab-1.0.1.zip';
    makeLabUpdateZip($packageSource, $zipPath);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if (!is_resource($socket)) {
        throw new RuntimeException('cannot reserve local port: ' . $errorMessage);
    }
    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1);
    $baseUrl = 'http://127.0.0.1:' . $port;
    $logPath = $testRoot . '/php-server.log';
    $descriptor = [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']];
    $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $testRoot], $descriptor, $pipes, $testRoot);
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start PHP server');
    }
    fclose($pipes[0]);
    waitForServer($baseUrl);

    $cookie = $testRoot . '/cookies.txt';
    $loginPage = curlRequest($baseUrl . '/post/', $cookie);
    $login = curlRequest($baseUrl . '/post/?post_api=start', $cookie, [
        '_token' => csrfToken($loginPage),
        'post_password' => 'test-password',
        'expected_images' => '[]',
        'submission_id' => hiddenValue($loginPage, 'submission_id'),
    ]);
    $loginJson = json_decode($login, true);
    assertTrue(is_array($loginJson) && !empty($loginJson['ok']), 'Tomos Post authentication failed');

    $uploadPage = curlRequest($baseUrl . '/post/theme/add/', $cookie);
    assertContains('テーマZIPを追加・更新', $uploadPage);
    assertContains('同一versionの再アップロードも可能です', $uploadPage);

    $inspectionPage = curlMultipart($baseUrl . '/post/theme/add/', $cookie, [
        '_token' => csrfToken($uploadPage),
        'theme_zip' => new CURLFile($zipPath, 'application/zip', 'tomos-lab-1.0.1.zip'),
    ]);
    assertContains('テーマZIPの検査が完了しました', $inspectionPage);
    assertContains('tomos-lab', $inspectionPage);
    assertContains('現在のversion:</strong> 1.0.0', $inspectionPage);
    assertContains('アップロードversion:</strong> 1.0.1', $inspectionPage);
    assertContains('このテーマを更新する', $inspectionPage);
    assertContains('theme-settings.php、theme-assets/、content/は更新対象ではありません', $inspectionPage);

    $resultPage = curlRequest($baseUrl . '/post/theme/add/confirm/', $cookie, [
        '_token' => csrfToken($inspectionPage),
    ]);
    assertContains('テーマを更新しました。', $resultPage);
    assertContains('version: 1.0.0 → 1.0.1', $resultPage);
    assertContains('テーマ選択設定は変更されていません', $resultPage);

    $themeJson = json_decode((string) file_get_contents($testRoot . '/themes/tomos-lab/theme.json'), true);
    assertSame('1.0.1', (string) ($themeJson['version'] ?? ''), 'updated theme version is wrong');
    assertContains('browser update 1.0.1', (string) file_get_contents($testRoot . '/themes/tomos-lab/assets/style.css'));
    assertSame($preserved, hashes($testRoot, array_keys($preserved)), 'site-specific files changed');

    $updatedConfig = require $testRoot . '/config.php';
    assertSame('tomos-lab', (string) ($updatedConfig['theme']['name'] ?? ''), 'active theme selection changed');

    $publicPage = curlRequest($baseUrl . '/', $cookie);
    assertNotContains('Fatal error', $publicPage);
    assertContains('Browser lab content', $publicPage);

    echo "lab_theme_browser_update_check: ordinary browser update, active selection and site-data preservation passed\n";
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

function makeLabUpdateZip(string $sourceTheme, string $zipPath): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('cannot create lab update ZIP');
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceTheme, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($sourceTheme) + 1));
        $content = (string) file_get_contents($file->getPathname());
        if ($relative === 'theme.json') {
            $theme = json_decode($content, true);
            $theme['version'] = '1.0.1';
            $content = json_encode($theme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        } elseif ($relative === 'assets/style.css') {
            $content .= "\n/* browser update 1.0.1 */\n";
        }
        $name = 'tomos-lab/' . $relative;
        $zip->addFromString($name, $content);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
    }
    $zip->close();
}

function hashes(string $root, array $paths): array
{
    $result = [];
    foreach ($paths as $path) {
        $result[$path] = hash_file('sha256', $root . '/' . $path);
    }
    return $result;
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
    curl_close($handle);
    return $response;
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
    if (preg_match('/<input[^>]+name=["\']' . $quotedName . '["\'][^>]+value=["\']([^"\']*)["\']/i', $html, $matches) !== 1) {
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
