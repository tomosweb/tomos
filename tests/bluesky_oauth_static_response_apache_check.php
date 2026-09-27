<?php

declare(strict_types=1);

function staticResponseAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeStaticResponseTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        removeStaticResponseTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

function fetchStaticResponse(string $url, string $root): array
{
    $headers = $root . '/response.headers';
    $body = $root . '/response.body';
    $command = 'curl -sS --max-time 5 --max-redirs 0 -D ' . escapeshellarg($headers)
        . ' -o ' . escapeshellarg($body) . ' ' . escapeshellarg($url);
    exec($command, $output, $status);
    staticResponseAssert($status === 0, 'curl request failed: ' . $url);
    $headerText = (string) file_get_contents($headers);
    preg_match('/\AHTTP\/\S+\s+(\d+)/', $headerText, $statusMatch);
    preg_match('/^Content-Type:\s*([^\r\n]+)/mi', $headerText, $contentTypeMatch);
    preg_match('/^Location:\s*([^\r\n]+)/mi', $headerText, $locationMatch);
    preg_match('/^X-Powered-By:\s*([^\r\n]+)/mi', $headerText, $poweredByMatch);
    return [
        'status' => (int) ($statusMatch[1] ?? 0),
        'content_type' => trim((string) ($contentTypeMatch[1] ?? '')),
        'location' => trim((string) ($locationMatch[1] ?? '')),
        'powered_by' => trim((string) ($poweredByMatch[1] ?? '')),
        'headers' => $headerText,
        'body' => (string) file_get_contents($body),
    ];
}

$sourceRoot = dirname(__DIR__);
$root = sys_get_temp_dir() . '/tomos-bluesky-static-response-' . bin2hex(random_bytes(6));
$siteRoot = $root . '/site';
$installRoot = $siteRoot . '/tomos';
$started = false;

try {
    mkdir($installRoot . '/storage', 0777, true);
    mkdir($installRoot . '/storage/update-backups', 0777, true);
    mkdir($installRoot . '/core', 0777, true);
    require_once $sourceRoot . '/core/BlueskyOAuthHtaccessDiagnostics.php';
    require_once $sourceRoot . '/core/BlueskyOAuthHtaccessMigration.php';
    $sourceHtaccess = (string) file_get_contents($sourceRoot . '/.htaccess');
    $staticBlock = implode("\n", Tomos\BlueskyOAuthHtaccessDiagnostics::managedBlockLines());
    $legacyBlock = implode("\n", Tomos\BlueskyOAuthHtaccessDiagnostics::legacyManagedBlockLines());
    staticResponseAssert(substr_count($sourceHtaccess, $staticBlock) === 1, 'source static managed block is unavailable');
    $legacyHtaccess = str_replace($staticBlock, $legacyBlock, $sourceHtaccess);
    file_put_contents($installRoot . '/.htaccess', $legacyHtaccess, LOCK_EX);
    copy($sourceRoot . '/oauth-client-metadata.json.php', $installRoot . '/oauth-client-metadata.json.php');
    copy($sourceRoot . '/tomos-bluesky-jwks.json.php', $installRoot . '/tomos-bluesky-jwks.json.php');
    copy($sourceRoot . '/core/BlueskyOAuthMetadata.php', $installRoot . '/core/BlueskyOAuthMetadata.php');
    copy($sourceRoot . '/core/BlueskyOAuthKeyStore.php', $installRoot . '/core/BlueskyOAuthKeyStore.php');
    copy($sourceRoot . '/core/BlueskyOAuthStaticBacking.php', $installRoot . '/core/BlueskyOAuthStaticBacking.php');
    copy($sourceRoot . '/core/BlueskyOAuthHtaccessDiagnostics.php', $installRoot . '/core/BlueskyOAuthHtaccessDiagnostics.php');
    copy($sourceRoot . '/core/BlueskyOAuthHtaccessMigration.php', $installRoot . '/core/BlueskyOAuthHtaccessMigration.php');
    $config = [
        'site' => [
            'url' => 'https://example.test',
            'base_path' => '/tomos',
            'public_base_path' => '/tomos',
        ],
    ];
    file_put_contents($installRoot . '/config.php', '<?php return ' . var_export($config, true) . ';' . PHP_EOL, LOCK_EX);

    $migration = Tomos\BlueskyOAuthHtaccessMigration::migrate($installRoot);
    staticResponseAssert(($migration['status'] ?? '') === 'migrated', 'safe static backing migration failed');
    staticResponseAssert(!empty(Tomos\BlueskyOAuthHtaccessDiagnostics::inspect($installRoot)['static_backing_enabled']), 'safe migration did not activate static backing');

    require_once $sourceRoot . '/core/BlueskyOAuthMetadata.php';
    require_once $sourceRoot . '/core/BlueskyOAuthKeyStore.php';
    require_once $sourceRoot . '/core/BlueskyOAuthStaticBacking.php';
    $key = (new Tomos\BlueskyOAuthKeyStore($installRoot))->loadOrCreate();
    staticResponseAssert(is_array($key), 'Apache fixture OAuth key could not be generated');
    Tomos\BlueskyOAuthStaticBacking::generate($installRoot, $config);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    staticResponseAssert(is_resource($socket), 'Apache test port could not be allocated: ' . $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr((string) $address, ':'), 1);
    $phpCgi = trim((string) shell_exec('command -v php-cgi'));
    staticResponseAssert($phpCgi !== '' && is_file($phpCgi), 'php-cgi is required for Apache fallback E2E');

    $configPath = $root . '/httpd.conf';
    $configText = implode("\n", [
        'ServerRoot "/usr"',
        'Listen 127.0.0.1:' . $port,
        'ServerName 127.0.0.1',
        'PidFile "' . $root . '/httpd.pid"',
        'LoadModule mpm_prefork_module /usr/libexec/apache2/mod_mpm_prefork.so',
        'LoadModule unixd_module /usr/libexec/apache2/mod_unixd.so',
        'LoadModule log_config_module /usr/libexec/apache2/mod_log_config.so',
        'LoadModule authz_core_module /usr/libexec/apache2/mod_authz_core.so',
        'LoadModule authz_host_module /usr/libexec/apache2/mod_authz_host.so',
        'LoadModule access_compat_module /usr/libexec/apache2/mod_access_compat.so',
        'LoadModule dir_module /usr/libexec/apache2/mod_dir.so',
        'LoadModule mime_module /usr/libexec/apache2/mod_mime.so',
        'LoadModule actions_module /usr/libexec/apache2/mod_actions.so',
        'LoadModule alias_module /usr/libexec/apache2/mod_alias.so',
        'LoadModule cgi_module /usr/libexec/apache2/mod_cgi.so',
        'LoadModule rewrite_module /usr/libexec/apache2/mod_rewrite.so',
        'ScriptAlias /php-cgi ' . $phpCgi,
        'Action application/x-httpd-php /php-cgi',
        'AddHandler application/x-httpd-php .php',
        'TypesConfig "/private/etc/apache2/mime.types"',
        'ErrorLog "' . $root . '/error.log"',
        'CustomLog "' . $root . '/access.log" common',
        'DocumentRoot "' . $siteRoot . '"',
        '<Directory "' . $siteRoot . '">',
        '    AllowOverride All',
        '    Options +ExecCGI -Indexes',
        '    Require all granted',
        '</Directory>',
        '<Directory "' . dirname($phpCgi) . '">',
        '    Require all granted',
        '</Directory>',
        '',
    ]);
    file_put_contents($configPath, $configText, LOCK_EX);
    exec('httpd -k start -f ' . escapeshellarg($configPath) . ' 2>' . escapeshellarg($root . '/httpd-start.err'), $output, $status);
    staticResponseAssert($status === 0, 'Apache could not start: ' . (string) file_get_contents($root . '/httpd-start.err'));
    $started = true;

    $base = 'http://127.0.0.1:' . $port . '/tomos';
    $metadataStatic = fetchStaticResponse($base . '/oauth-client-metadata.json.php?probe=static', $root);
    $jwksStatic = fetchStaticResponse($base . '/tomos-bluesky-jwks.json.php?probe=static', $root);
    foreach ([$metadataStatic, $jwksStatic] as $response) {
        staticResponseAssert($response['status'] === 200, 'static response must return HTTP 200: ' . var_export($response, true) . "\nApache error log:\n" . (string) @file_get_contents($root . '/error.log'));
        staticResponseAssert(stripos($response['content_type'], 'application/json') === 0, 'static response must return application/json');
        staticResponseAssert($response['location'] === '', 'static response must not redirect');
        staticResponseAssert($response['powered_by'] === '', 'static response must not execute PHP');
    }
    staticResponseAssert($metadataStatic['body'] === (string) file_get_contents($installRoot . '/oauth-client-metadata.static.json'), 'metadata static body mismatch');
    staticResponseAssert($jwksStatic['body'] === (string) file_get_contents($installRoot . '/tomos-bluesky-jwks.static.json'), 'JWKS static body mismatch');

    unlink($installRoot . '/oauth-client-metadata.static.json');
    unlink($installRoot . '/tomos-bluesky-jwks.static.json');
    $metadataFallback = fetchStaticResponse($base . '/oauth-client-metadata.json.php?probe=fallback', $root);
    $jwksFallback = fetchStaticResponse($base . '/tomos-bluesky-jwks.json.php?probe=fallback', $root);
    foreach ([$metadataFallback, $jwksFallback] as $response) {
        staticResponseAssert($response['status'] === 200, 'PHP fallback must return HTTP 200');
        staticResponseAssert(stripos($response['content_type'], 'application/json') === 0, 'PHP fallback must return JSON');
        staticResponseAssert($response['location'] === '', 'PHP fallback must not redirect');
        staticResponseAssert(stripos($response['powered_by'], 'PHP') !== false, 'PHP fallback must execute the legacy endpoint');
        staticResponseAssert(is_array(json_decode($response['body'], true)), 'PHP fallback must return valid JSON');
    }
} finally {
    if ($started) {
        exec('httpd -k stop -f ' . escapeshellarg($root . '/httpd.conf') . ' 2>/dev/null', $output, $status);
    }
    removeStaticResponseTree($root);
}

echo "bluesky_oauth_static_response_apache_check: passed\n";
