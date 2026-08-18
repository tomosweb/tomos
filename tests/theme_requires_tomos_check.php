<?php

declare(strict_types=1);

use Tomos\ThemeValidator;

require_once dirname(__DIR__) . '/core/ThemeValidator.php';

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function makeTheme(string $themesDir, string $id, ?string $requiresTomos): void
{
    $themeDir = $themesDir . DIRECTORY_SEPARATOR . $id;
    @mkdir($themeDir . DIRECTORY_SEPARATOR . 'templates', 0777, true);
    @mkdir($themeDir . DIRECTORY_SEPARATOR . 'assets', 0777, true);

    $metadata = [
        'name' => $id,
        'display_name' => 'Compatibility Test',
        'version' => '1.0.0',
        'description' => 'test',
        'author' => 'Tomos',
        'supports' => ['responsive' => true],
    ];
    if ($requiresTomos !== null) {
        $metadata['requires_tomos'] = $requiresTomos;
    }

    file_put_contents($themeDir . '/theme.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($themeDir . '/templates/layout.html', '<main>{{{ page.body }}}</main>');
    file_put_contents($themeDir . '/templates/page.html', '{{{ page.content }}}');
    file_put_contents($themeDir . '/templates/list.html', '{{{ list.pages }}}');
    file_put_contents($themeDir . '/assets/style.css', 'body{}');
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($child) && !is_link($child)) {
            removeTree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . '/tomos-theme-compat-' . bin2hex(random_bytes(6));
$themesDir = $root . '/storage/theme-upload-tmp/fixture/extracted';
@mkdir($themesDir, 0777, true);
file_put_contents($root . '/VERSION', "0.1.0-beta.1\n");

try {
    makeTheme($themesDir, 'legacy-theme', null);
    makeTheme($themesDir, 'compatible-theme', '0.1.0-beta.1');
    makeTheme($themesDir, 'future-theme', '0.1.0-beta.2');
    makeTheme($themesDir, 'invalid-requirement', '>=0.1.0-beta.1');

    $validator = new ThemeValidator($themesDir);

    $legacy = $validator->validate('legacy-theme');
    if (empty($legacy['valid'])) {
        fail('Theme without requires_tomos must remain backward compatible.');
    }
    if (($legacy['theme']['requires_tomos'] ?? null) !== '') {
        fail('Missing requires_tomos must normalize to an empty string.');
    }

    $compatible = $validator->validate('compatible-theme');
    if (empty($compatible['valid'])) {
        fail('Theme requiring the current Tomos version must be valid.');
    }
    if (($compatible['theme']['requires_tomos'] ?? '') !== '0.1.0-beta.1') {
        fail('requires_tomos metadata must be preserved.');
    }

    $future = $validator->validate('future-theme');
    if (!empty($future['valid'])) {
        fail('Theme requiring a newer Tomos version must be rejected.');
    }
    $futureErrors = implode("\n", $future['errors'] ?? []);
    if (strpos($futureErrors, 'Tomos 0.1.0-beta.2 以上') === false) {
        fail('Future-version rejection must explain the required Tomos version.');
    }

    $invalid = $validator->validate('invalid-requirement');
    if (!empty($invalid['valid'])) {
        fail('requires_tomos range expressions must not be accepted in v1.');
    }
    $invalidErrors = implode("\n", $invalid['errors'] ?? []);
    if (strpos($invalidErrors, 'requires_tomos') === false) {
        fail('Invalid requires_tomos syntax must be reported.');
    }

    echo "theme_requires_tomos_check: OK\n";
} finally {
    removeTree($root);
}
