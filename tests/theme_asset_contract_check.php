<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Tomos\App;

$root = sys_get_temp_dir() . '/tomos-theme-assets-' . bin2hex(random_bytes(8));
mkdir($root . '/assets', 0700, true);
copy(dirname(__DIR__) . '/assets/tomos-default-favicon.png', $root . '/assets/tomos-default-favicon.png');

try {
    writeTheme($root, 'tomos-no-favicon', '1.0.5', false);
    writeTheme($root, 'tomos-custom-favicon', '1.0.5', true);

    $config = config($root, 'tomos-no-favicon');
    $first = render($config);
    assertContains($first, '/themes/tomos-no-favicon/assets/style.css?v=1.0.5', 'stylesheet URL must include the Theme version');
    $canonicalHash = hash_file('sha256', $root . '/assets/tomos-default-favicon.png');
    assertContains($first, '/assets/tomos-default-favicon.png?v=' . $canonicalHash, 'missing favicon must use the Core canonical favicon and fingerprint');
    assertNotContains($first, 'themes/tomos-minimal/assets/favicon.png', 'missing favicon must not use the Minimal Theme as an accidental fallback');

    file_put_contents($root . '/themes/tomos-no-favicon/theme.json', manifest('tomos-no-favicon', '1.0.6'), LOCK_EX);
    $second = render(config($root, 'tomos-no-favicon'));
    assertContains($second, '/themes/tomos-no-favicon/assets/style.css?v=1.0.6', 'stylesheet URL must change when Theme version changes');
    assertNotContains($second, '/themes/tomos-no-favicon/assets/style.css?v=1.0.5', 'stale stylesheet cache key survived a Theme version change');

    $custom = render(config($root, 'tomos-custom-favicon'));
    $customHash = hash_file('sha256', $root . '/themes/tomos-custom-favicon/assets/favicon.png');
    assertContains($custom, '/themes/tomos-custom-favicon/assets/favicon.png?v=' . $customHash, 'custom favicon must remain selected and fingerprinted');
    assertNotContains($custom, '/assets/tomos-default-favicon.png?', 'custom favicon must not be replaced by the Core default');

    echo "theme_asset_contract_check: OK (versioned stylesheet URLs, Core canonical favicon, custom favicon preservation)\n";
} finally {
    removeTree($root);
}

function config(string $root, string $theme): array
{
    if (!is_dir($root . '/content')) {
        mkdir($root . '/content', 0700, true);
    }
    file_put_contents($root . '/content/index.md', "---\ntitle: Home\ndraft: false\n---\nHome\n", LOCK_EX);
    return [
        'site' => ['name' => 'Theme Asset Contract', 'description' => '', 'url' => 'https://example.test', 'base_path' => '', 'public_base_path' => ''],
        'paths' => ['content_dir' => $root . '/content', 'cache_dir' => $root . '/cache', 'theme_dir' => $root . '/themes', 'inbox_dir' => $root . '/inbox'],
        'theme' => ['name' => $theme],
        'features' => ['metadata_cache' => false, 'html_cache' => false, 'rss' => false, 'sitemap' => false],
        'security' => ['allow_raw_html' => false, 'content_security_policy' => false],
        'analytics' => ['ga4_measurement_id' => ''],
    ];
}

function writeTheme(string $root, string $name, string $version, bool $customFavicon): void
{
    $theme = $root . '/themes/' . $name;
    mkdir($theme . '/templates', 0700, true);
    mkdir($theme . '/assets', 0700, true);
    file_put_contents($theme . '/theme.json', manifest($name, $version), LOCK_EX);
    file_put_contents($theme . '/templates/layout.html', '<!doctype html><html><head><link rel="icon" href="{{ theme.favicon_url }}" type="{{ theme.favicon_type }}"><link rel="stylesheet" href="{{ theme.asset_url }}/style.css?v={{ theme.asset_version }}"></head><body>{{{ page.body }}}</body></html>', LOCK_EX);
    file_put_contents($theme . '/templates/home.html', '<main>home</main>', LOCK_EX);
    file_put_contents($theme . '/templates/page.html', '<main>page</main>', LOCK_EX);
    file_put_contents($theme . '/templates/list.html', '<main>list</main>', LOCK_EX);
    file_put_contents($theme . '/assets/style.css', 'body{}', LOCK_EX);
    if ($customFavicon) {
        file_put_contents($theme . '/assets/favicon.png', "custom-favicon\n", LOCK_EX);
    }
}

function manifest(string $name, string $version): string
{
    return json_encode(['name' => $name, 'display_name' => $name, 'version' => $version], JSON_UNESCAPED_SLASHES) . "\n";
}

function render(array $config): string
{
    http_response_code(200);
    ob_start();
    try {
        (new App($config))->run('/');
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

function assertContains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($label . ': missing ' . $needle);
    }
}

function assertNotContains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($label . ': unexpected ' . $needle);
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
