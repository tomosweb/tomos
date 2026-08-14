<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$passes = 0;
$failures = [];

function check(bool $condition, string $label): void
{
    global $passes, $failures;
    if ($condition) {
        $passes++;
        return;
    }
    $failures[] = $label;
}

function expectInvalid(array $server, string $label): void
{
    try {
        Tomos\SetupUrlResolver::resolve($server);
        check(false, $label);
    } catch (InvalidArgumentException $exception) {
        check(true, $label);
    }
}

$cases = [
    [
        'server' => [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'example.com',
            'SCRIPT_NAME' => '/setup/index.php',
        ],
        'expected' => ['site_url' => 'https://example.com', 'base_path' => ''],
        'label' => 'document root setup',
    ],
    [
        'server' => [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'example.com',
            'SCRIPT_NAME' => '/tomos/setup/',
        ],
        'expected' => ['site_url' => 'https://example.com/tomos', 'base_path' => '/tomos'],
        'label' => 'one-level subdirectory setup',
    ],
    [
        'server' => [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'example.com',
            'SCRIPT_NAME' => '/foo/bar/setup/index.php',
        ],
        'expected' => ['site_url' => 'https://example.com/foo/bar', 'base_path' => '/foo/bar'],
        'label' => 'nested subdirectory setup',
    ],
    [
        'server' => [
            'HTTPS' => '',
            'HTTP_HOST' => 'localhost:8080',
            'SCRIPT_NAME' => '/tomos/setup/index.php',
        ],
        'expected' => ['site_url' => 'http://localhost:8080/tomos', 'base_path' => '/tomos'],
        'label' => 'HTTP and non-default port',
    ],
    [
        'server' => [
            'HTTPS' => 'on',
            'HTTP_HOST' => '[2001:db8::1]:8443',
            'SCRIPT_NAME' => '/tomos/setup/index.php',
        ],
        'expected' => ['site_url' => 'https://[2001:db8::1]:8443/tomos', 'base_path' => '/tomos'],
        'label' => 'IPv6 and non-default port',
    ],
];

foreach ($cases as $case) {
    check(
        Tomos\SetupUrlResolver::resolve($case['server']) === $case['expected'],
        (string) $case['label']
    );
}

$queryCase = Tomos\SetupUrlResolver::resolve([
    'HTTPS' => 'on',
    'HTTP_HOST' => 'example.com',
    'SCRIPT_NAME' => '/tomos/setup/index.php',
    'REQUEST_URI' => '/tomos/setup/?step=1',
]);
check($queryCase['site_url'] === 'https://example.com/tomos' && $queryCase['base_path'] === '/tomos', 'query string is excluded');

expectInvalid([
    'HTTP_HOST' => "example.com\r\nX-Test: injected",
    'SCRIPT_NAME' => '/setup/index.php',
], 'control characters in host are rejected');
expectInvalid([
    'HTTP_HOST' => 'user@example.com',
    'SCRIPT_NAME' => '/setup/index.php',
], 'userinfo in host is rejected');
expectInvalid([
    'HTTP_HOST' => 'example.com:bad',
    'SCRIPT_NAME' => '/setup/index.php',
], 'invalid port is rejected');

$normalized = Tomos\SetupUrlResolver::normalizeSiteUrl('https://example.com/tomos/');
check($normalized === ['site_url' => 'https://example.com/tomos', 'base_path' => '/tomos'], 'site URL normalization');
check(Tomos\SetupUrlResolver::normalizeSiteUrl('https://example.com/tomos?x=1') === null, 'query in site URL is rejected');
check(Tomos\SetupUrlResolver::normalizeBasePath('/tomos/../other') === null, 'dot segments are rejected');

$router = new Tomos\Router('/tomos');
$route = $router->resolve('/tomos/');
check($route->isValid && $route->urlPath === '/' && $route->contentPathCandidates === ['index.md'], 'subdirectory root routes to index.md');

$rootDir = dirname(__DIR__);
$writerInput = [
    'site_name' => 'Tomos Site',
    'site_description' => '',
    'site_url' => 'https://example.com/tomos',
    'base_path' => '',
    'public_base_path' => '',
    'language' => 'ja',
    'timezone' => 'Asia/Tokyo',
    'theme_name' => 'tomos-minimal',
    'feature_html_cache' => '1',
    'feature_post' => '',
];
[$writtenConfig, $writerErrors] = Tomos\ConfigWriter::build($writerInput, [], $rootDir);
check($writerErrors === [], 'ConfigWriter accepts site URL without a separate base path');
check(($writtenConfig['site']['base_path'] ?? null) === '/tomos', 'ConfigWriter derives base path from site URL');

$mismatchInput = $writerInput;
$mismatchInput['base_path'] = '/wrong';
[, $mismatchErrors] = Tomos\ConfigWriter::build($mismatchInput, [], $rootDir);
check(in_array('base_path はサイトURLの設置パスと一致している必要があります。', $mismatchErrors, true), 'ConfigWriter rejects inconsistent base path');

$setupHtml = (string) file_get_contents($rootDir . '/setup/index.php');
check(strpos($setupHtml, "input('サイトURL'") === false, 'setup has no site URL input');
check(strpos($setupHtml, "input('URL上の設置パス base_path'") === false, 'setup has no base path input');
check(strpos($setupHtml, "input('公開URL補正 public_base_path'") === false, 'setup has no public base path input');
check(strpos($setupHtml, 'このサーバーから自動的に取得しました。') !== false, 'setup displays detected URL guidance');

if ($failures !== []) {
    fwrite(STDERR, "setup_url_check: " . count($failures) . " failed\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "- " . $failure . "\n");
    }
    exit(1);
}

echo "setup_url_check: {$passes} checks passed\n";
