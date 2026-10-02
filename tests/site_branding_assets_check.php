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

use Tomos\SiteBrandingAssets;

$root = sys_get_temp_dir() . '/tomos-site-branding-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);

try {
    $assets = new SiteBrandingAssets($root);
    assertSame(null, $assets->asset('favicon'), 'favicon must initially be absent');
    assertSame('', $assets->publicUrl('favicon', ''), 'missing favicon URL must be empty');

    $png = $root . '/upload.png';
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

    $savedFavicon = $assets->saveUploaded('favicon', [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $png,
        'name' => 'favicon.png',
    ]);
    assertTrue($savedFavicon['ok'], 'favicon upload must succeed');
    $favicon = $assets->asset('favicon');
    assertTrue(is_array($favicon), 'favicon asset must exist');
    assertSame('favicon.png', $favicon['file'] ?? '', 'favicon filename');
    assertSame('image/png', $favicon['mime'] ?? '', 'favicon MIME');
    assertContains('/theme-assets/favicon.png?v=', $assets->publicUrl('favicon', ''), 'favicon public URL');
    assertContains('https://example.test/base/theme-assets/favicon.png?v=', $assets->absoluteUrl('favicon', 'https://example.test/base', '/base'), 'favicon absolute URL');

    $invalid = $root . '/invalid.txt';
    file_put_contents($invalid, 'not an image');
    $invalidResult = $assets->saveUploaded('favicon', [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $invalid,
        'name' => 'bad.png',
    ]);
    assertTrue(!$invalidResult['ok'], 'invalid image must be rejected');
    assertTrue(is_array($assets->asset('favicon')), 'rejected image must preserve previous favicon');

    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    $savedOgp = $assets->saveUploaded('ogp', [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $png,
        'name' => 'ogp.png',
    ]);
    assertTrue($savedOgp['ok'], 'OGP upload must succeed');
    assertContains('/theme-assets/ogp.png?v=', $assets->publicUrl('ogp', ''), 'OGP public URL');

    assertTrue($assets->remove('favicon'), 'favicon removal must succeed');
    assertSame(null, $assets->asset('favicon'), 'favicon must be removed');
    assertTrue(is_array($assets->asset('ogp')), 'favicon removal must preserve OGP');

    echo "site_branding_assets_check: OK\n";
} finally {
    removeTree($root);
}

/** @param mixed $expected @param mixed $actual */
function assertSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

function assertContains(string $needle, string $haystack, string $label): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($label . ': missing ' . $needle);
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
