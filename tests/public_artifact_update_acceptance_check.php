<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdatePackageDownloader.php';
require_once dirname(__DIR__) . '/core/UpdateReleaseProvider.php';
require_once dirname(__DIR__) . '/tests/public_update_chain_helper.php';

use Tomos\UpdatePackageDownloader;
use Tomos\UpdateReleaseProvider;

$root = dirname(__DIR__);
$options = getopt('', [
    'child',
    'fixture:',
    'package:',
    'baseline:',
    'from:',
    'target:',
    'rules-hash:',
    'scenario:',
]);

if (isset($options['child'])) {
    runChildTransition(
        $root,
        (string) ($options['baseline'] ?? ''),
        (string) ($options['package'] ?? ''),
        (string) ($options['from'] ?? ''),
        (string) ($options['target'] ?? ''),
        (string) ($options['rules-hash'] ?? ''),
        (string) ($options['scenario'] ?? 'success')
    );
    exit(0);
}

try {
    $targetVersion = trim((string) file_get_contents($root . '/VERSION'));
    if ($targetVersion === '' || !preg_match('/\A[0-9]+(?:\.[0-9]+)*(?:-[0-9A-Za-z.-]+)?\z/', $targetVersion)) {
        fail('target VERSION is missing or invalid');
    }

    $catalogUrl = getenv('TOMOS_PUBLIC_CATALOG_URL') ?: UpdateReleaseProvider::CATALOG_URL;
    $rulesPath = $root . '/docs/theme/theme-rules.json';
    if (!is_file($rulesPath)) {
        fail('runtime Theme rules file is missing from the checkout');
    }
    $rulesHash = strtolower((string) hash_file('sha256', $rulesPath));

    $startVersion = '0.6.1';
    $baselineUrlTemplate = getenv('TOMOS_PUBLIC_BASELINE_URL_TEMPLATE')
        ?: 'https://github.com/tomosweb/tomos/releases/download/v%s/tomos-%s.zip';
    $configuredWorkdir = getenv('TOMOS_PUBLIC_GATE_OUTPUT_DIR') ?: '';
    $tmp = $configuredWorkdir !== '' ? rtrim($configuredWorkdir, DIRECTORY_SEPARATOR) : sys_get_temp_dir() . '/tomos-public-artifact-gate-' . bin2hex(random_bytes(8));
    if ($configuredWorkdir !== '' && (file_exists($tmp) || is_link($tmp))) {
        fail('configured gate workspace already exists: ' . $tmp);
    }
    if (!mkdir($tmp, 0700, true)) {
        fail('could not create gate workspace');
    }

    try {
        $provider = new UpdateReleaseProvider(null, $catalogUrl);
        $chain = resolvePublicUpdateChain($provider, $startVersion, $targetVersion);
        check($chain !== [], "catalog chain exists for {$startVersion}");
        check(end($chain)['to'] === $targetVersion, "catalog chain reaches {$targetVersion}");

        $packages = [];
        $baselineUrls = [];
        foreach ($chain as $step) {
            $from = $step['from'];
            $to = $step['to'];
            $packagePath = $tmp . '/tomos-update-' . str_replace('.', '_', $from) . '-to-' . str_replace('.', '_', $to) . '.zip';
            $download = (new UpdatePackageDownloader())->download($step['package_url'], $step['sha256'], $packagePath);
            check((int) $download['size'] > 0, "public Update ZIP has content for {$from} -> {$to}");
            assertDownloadedPackageHash($packagePath, $step['sha256']);
            echo 'PUBLIC UPDATE ' . $from . ' -> ' . $to . ': ' . $download['url'] . ' size=' . $download['size'] . ' sha256=' . $download['sha256'] . PHP_EOL;
            $packages[$from] = $packagePath;
            $baselineUrls[$from] = sprintf($baselineUrlTemplate, $from, $from);
        }

        $baselines = [];
        foreach ($baselineUrls as $version => $url) {
            $path = $tmp . '/tomos-' . str_replace('.', '_', $version) . '.zip';
            $download = downloadGithubArtifact($url, $path);
            check((int) $download['size'] > 0, "public distribution ZIP has content for {$version}");
            check(isValidZip($path), "public distribution ZIP is readable for {$version}");
            echo 'PUBLIC BASELINE ' . $version . ': ' . $download['url'] . ' status=' . $download['status'] . ' size=' . $download['size'] . ' sha256=' . $download['sha256'] . PHP_EOL;
            $baselines[$version] = $path;
        }

        foreach ($chain as $step) {
            $from = $step['from'];
            $to = $step['to'];
            runTransitionChild($root, $baselines[$from], $packages[$from], $from, $to, $rulesHash, 'success');
            echo "TRANSITION {$from} -> {$to}: PASS" . PHP_EOL;
        }

        $first = $chain[0];
        runTransitionChild($root, $baselines[$first['from']], $packages[$first['from']], $first['from'], $first['to'], $rulesHash, 'rollback');
        echo 'ROLLBACK negative package: PASS' . PHP_EOL;
        echo 'public_artifact_update_acceptance_check: PASS' . PHP_EOL;
    } finally {
        if ($configuredWorkdir === '') {
            removeTree($tmp);
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

function runTransitionChild(string $root, string $baseline, string $package, string $from, string $target, string $rulesHash, string $scenario): void
{
    foreach ([$baseline, $package] as $path) {
        if ($path === '' || !is_file($path)) {
            fail('transition input is missing: ' . $path);
        }
    }
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__FILE__)
        . ' --child'
        . ' --baseline=' . escapeshellarg($baseline)
        . ' --package=' . escapeshellarg($package)
        . ' --from=' . escapeshellarg($from)
        . ' --target=' . escapeshellarg($target)
        . ' --rules-hash=' . escapeshellarg($rulesHash)
        . ' --scenario=' . escapeshellarg($scenario);
    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException(implode(PHP_EOL, $output));
    }
}

function runChildTransition(string $root, string $baseline, string $package, string $from, string $target, string $rulesHash, string $scenario): void
{
    if ($baseline === '' || !is_file($baseline) || $package === '' || !is_file($package)) {
        throw new RuntimeException('child transition input is missing');
    }
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZipArchive is required for the public artifact gate');
    }

    $fixture = sys_get_temp_dir() . '/tomos-public-transition-' . bin2hex(random_bytes(8));
    if (!mkdir($fixture, 0700, true)) {
        throw new RuntimeException('could not create transition fixture');
    }
    try {
        $zip = new ZipArchive();
        if ($zip->open($baseline) !== true || !$zip->extractTo($fixture)) {
            throw new RuntimeException('could not extract public baseline artifact');
        }
        $zip->close();
        seedFixture($fixture);
        $before = protectedSnapshot($fixture);

        require_once $fixture . '/core/UpdateLock.php';
        require_once $fixture . '/core/UpdateService.php';

        $service = new Tomos\UpdateService($fixture);
        if ($service->currentVersion() !== $from) {
            throw new RuntimeException('baseline VERSION mismatch: expected ' . $from . ', got ' . $service->currentVersion());
        }
        $summary = $service->stageDownloadedPackage($package, 'public-artifact-gate-' . $from . '-' . $scenario, $from, $target);

        if ($scenario === 'rollback') {
            $result = $service->apply((string) $summary['id'], 'public-artifact-gate-' . $from . '-' . $scenario);
            require_once $fixture . '/core/InstalledIntegrityVerifier.php';
            $missingRuntimeFile = $fixture . '/core/ThemeRules.php';
            if (!is_file($missingRuntimeFile) || !unlink($missingRuntimeFile)) {
                throw new RuntimeException('negative fixture could not remove a runtime required file');
            }
            try {
                (new Tomos\InstalledIntegrityVerifier($fixture))->verifyAfterUpdate($result);
            } catch (Tomos\UpdateException $exception) {
                if ($exception->stage() === '') {
                    throw new RuntimeException('rollback failure had no stage');
                }
                if ($service->currentVersion() !== $from || protectedSnapshot($fixture) !== $before) {
                    throw new RuntimeException('rollback did not restore VERSION and protected data');
                }
                return;
            }
            throw new RuntimeException('missing runtime payload was accepted');
        }

        $result = $service->apply((string) $summary['id'], 'public-artifact-gate-' . $from . '-' . $scenario);
        require_once $fixture . '/core/InstalledIntegrityVerifier.php';
        $verified = (new Tomos\InstalledIntegrityVerifier($fixture))->verifyAfterUpdate($result);
        if (empty($verified['ok'])) {
            throw new RuntimeException('runtime required-file validation failed');
        }
        require_once $fixture . '/core/UpdaterSelfUpdate.php';
        $selfUpdate = new Tomos\UpdaterSelfUpdate($fixture);
        if ($selfUpdate->hasPendingUpdate()) {
            $selfResult = $selfUpdate->apply();
            if (empty($selfResult['applied'])) {
                throw new RuntimeException('pending updater self-update did not complete');
            }
        }
        if (trim((string) file_get_contents($fixture . '/VERSION')) !== $target) {
            throw new RuntimeException('updated VERSION mismatch');
        }
        $rulesPath = $fixture . '/docs/theme/theme-rules.json';
        if (!is_file($rulesPath) || !hash_equals($rulesHash, strtolower((string) hash_file('sha256', $rulesPath)))) {
            throw new RuntimeException('runtime Theme rules file is missing or has the wrong hash');
        }
        foreach (['core/ThemeRules.php', 'core/ContentSecurityPolicy.php'] as $required) {
            if (!is_file($fixture . '/' . $required)) {
                throw new RuntimeException('runtime required file is missing: ' . $required);
            }
        }
        if (protectedSnapshot($fixture) !== $before) {
            throw new RuntimeException('protected data changed during public artifact update');
        }
        runtimeSmoke($fixture);
    } finally {
        removeTree($fixture);
    }
}

function seedFixture(string $fixture): void
{
    foreach (['storage/update-tmp', 'storage/update-backups', 'storage/update-logs', 'core/updater-pending', 'content', 'uploads', 'themes/custom-theme'] as $directory) {
        if (!is_dir($fixture . '/' . $directory) && !mkdir($fixture . '/' . $directory, 0700, true)) {
            throw new RuntimeException('could not prepare fixture directory: ' . $directory);
        }
    }
    $config = [
        'site' => [
            'name' => 'Public Acceptance Fixture',
            'description' => 'Public artifact acceptance test',
            'url' => 'http://127.0.0.1',
            'base_path' => '',
            'public_base_path' => '',
            'language' => 'ja',
            'timezone' => 'Asia/Tokyo',
        ],
        'paths' => [
            'content_dir' => $fixture . '/content',
            'cache_dir' => $fixture . '/cache',
            'theme_dir' => $fixture . '/themes',
        ],
        'theme' => ['name' => 'tomos-minimal'],
        'analytics' => ['ga4_measurement_id' => ''],
        'features' => ['search' => true, 'tags' => true, 'rss' => true, 'sitemap' => true, 'html_cache' => false, 'post' => false, 'metadata_cache' => true],
        'feed' => ['path_prefix' => ''],
        'metadata' => ['include_drafts' => false],
        'debug' => ['performance_log' => false],
        'security' => [
            'allow_raw_html' => false,
            'allow_external_scripts' => false,
            'allowed_file_extensions' => ['md', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
            'disable_setup_after_install' => true,
            'hide_error_detail' => true,
            'content_security_policy' => true,
            'post_password_hash' => '',
            'inbox_api_token_hash' => '',
            'rate_limit_salt' => '',
        ],
        'setup_completed' => true,
    ];
    file_put_contents($fixture . '/config.php', "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX);
    file_put_contents($fixture . '/content/public-acceptance.md', "---\ntitle: Public acceptance\ndate: 2026-09-02\npublished: true\n---\n\n# Acceptance\n\nKeep this content.\n", LOCK_EX);
    file_put_contents($fixture . '/uploads/public-acceptance.txt', "keep uploads\n", LOCK_EX);
    file_put_contents($fixture . '/themes/custom-theme/theme.json', "{}\n", LOCK_EX);
    file_put_contents($fixture . '/themes/custom-theme/custom-marker.txt', "keep custom theme\n", LOCK_EX);
}

function protectedSnapshot(string $root): array
{
    $snapshot = [];
    foreach (['config.php', 'content', 'uploads', 'themes/custom-theme'] as $relative) {
        $path = $root . '/' . $relative;
        if (is_file($path)) {
            $snapshot[$relative] = hash_file('sha256', $path);
            continue;
        }
        if (!is_dir($path)) {
            throw new RuntimeException('protected fixture path is missing: ' . $relative);
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $fileRelative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            $snapshot[$fileRelative] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($snapshot);
    return $snapshot;
}

function runtimeSmoke(string $root): void
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required for runtime smoke');
    }
    $port = random_int(18000, 18999);
    $log = $root . '/runtime-smoke.log';
    $command = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'ab'], 2 => ['file', $log, 'ab']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('could not start PHP runtime');
    }
    fclose($pipes[0]);
    try {
        $paths = ['/', '/update/'];
        $responses = [];
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $responses = [];
            foreach ($paths as $path) {
                $responses[$path] = localHttpGet('http://127.0.0.1:' . $port . $path);
            }
            if (($responses['/']['status'] ?? 0) !== 0) {
                break;
            }
            usleep(100000);
        }
        foreach ($paths as $path) {
            $response = $responses[$path] ?? [];
            if (($response['status'] ?? 0) !== 200) {
                throw new RuntimeException('runtime smoke failed for ' . $path . ' with HTTP ' . ($response['status'] ?? 0) . "\n" . (is_file($log) ? file_get_contents($log) : ''));
            }
            if (strpos((string) ($response['body'] ?? ''), 'Tomos Error') !== false) {
                throw new RuntimeException('runtime smoke returned an application error for ' . $path);
            }
        }
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
}

function localHttpGet(string $url): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    if (PHP_VERSION_ID < 80500) {
        curl_close($curl);
    }
    return ['status' => $body === false ? 0 : $status, 'body' => $body === false ? $error : $body];
}

function downloadGithubArtifact(string $url, string $destination): array
{
    $allowedHosts = ['github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com'];
    $current = $url;
    for ($redirect = 0; $redirect <= 3; $redirect++) {
        assertAllowedHost($current, $allowedHosts);
        $outputHandle = @fopen($destination, 'wb');
        if (!is_resource($outputHandle)) {
            throw new RuntimeException('could not create public baseline destination');
        }
        $location = null;
        $tooLarge = false;
        $bytes = 0;
        $curl = curl_init($current);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location): int {
                if (stripos(trim($line), 'Location:') === 0) {
                    $location = trim(substr(trim($line), 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $data) use ($outputHandle, &$bytes, &$tooLarge): int {
                $bytes += strlen($data);
                if ($bytes > UpdatePackageDownloader::MAX_ZIP_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $written = fwrite($outputHandle, $data);
                return $written === false ? 0 : $written;
            },
        ]);
        $ok = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentLength = (int) curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T);
        if (PHP_VERSION_ID < 80500) {
            curl_close($curl);
        }
        fclose($outputHandle);
        if ($tooLarge) {
            throw new RuntimeException('public baseline exceeds size limit');
        }
        if ($ok === false) {
            throw new RuntimeException('public baseline download failed: ' . $error);
        }
        if ($status >= 300 && $status < 400 && is_string($location) && $location !== '') {
            $current = resolveUrl($current, $location);
            continue;
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('public baseline returned HTTP ' . $status);
        }
        $size = filesize($destination);
        if ($size === false || $size < 1 || $size > UpdatePackageDownloader::MAX_ZIP_BYTES || ($contentLength >= 0 && $size !== $contentLength)) {
            throw new RuntimeException('public baseline content length is invalid');
        }
        return ['url' => $current, 'status' => $status, 'size' => (int) $size, 'sha256' => (string) hash_file('sha256', $destination)];
    }
    throw new RuntimeException('public baseline redirect limit exceeded');
}

function assertAllowedHost(string $url, array $allowedHosts): void
{
    $parts = parse_url($url);
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !in_array($host, $allowedHosts, true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        throw new RuntimeException('public baseline URL is not allowed: ' . $url);
    }
}

function resolveUrl(string $base, string $location): string
{
    if (parse_url($location, PHP_URL_SCHEME) !== null) return $location;
    $baseParts = parse_url($base);
    if (!is_array($baseParts)) throw new RuntimeException('could not resolve redirect URL');
    if (strpos($location, '//') === 0) return (string) $baseParts['scheme'] . ':' . $location;
    $prefix = (string) $baseParts['scheme'] . '://' . (string) $baseParts['host'];
    if (isset($baseParts['port'])) $prefix .= ':' . (int) $baseParts['port'];
    if ($location !== '' && $location[0] === '/') return $prefix . $location;
    $path = (string) ($baseParts['path'] ?? '/');
    return $prefix . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
}

function isValidZip(string $path): bool
{
    $zip = new ZipArchive();
    $ok = $zip->open($path) === true;
    if ($ok) $zip->close();
    return $ok;
}

function check(bool $condition, string $message): void
{
    if (!$condition) fail($message);
}

function fail(string $message): void
{
    throw new RuntimeException($message);
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) return;
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) removeTree($path . DIRECTORY_SEPARATOR . $item);
    @rmdir($path);
}
