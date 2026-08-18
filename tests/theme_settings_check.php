<?php

declare(strict_types=1);

use Tomos\ThemeSettings;

require_once dirname(__DIR__) . '/core/Security.php';
require_once dirname(__DIR__) . '/core/ThemeSettings.php';

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
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

$root = sys_get_temp_dir() . '/tomos-theme-settings-' . bin2hex(random_bytes(6));
@mkdir($root . '/theme-assets/brand', 0777, true);
file_put_contents($root . '/theme-assets/hero.jpg', 'hero');
file_put_contents($root . '/theme-assets/brand/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h1v1z"/></svg>');
file_put_contents($root . '/theme-assets/bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

try {
    $defaults = (new ThemeSettings($root))->settings();
    if (!empty($defaults['hero']['enabled'])) {
        fail('Hero must be disabled when theme-settings.php is absent.');
    }
    if (($defaults['news']['path'] ?? '') !== '/news/' || ($defaults['news']['limit'] ?? 0) !== 5) {
        fail('News defaults must remain /news/ and 5 items.');
    }

    file_put_contents($root . '/theme-settings.php', <<<'PHP'
<?php
return [
    'hero' => [
        'enabled' => true,
        'image' => 'hero.jpg',
        'title' => '<Lab & Research>',
        'subtitle' => "Research\nGroup",
        'button_label' => 'Our Research',
        'button_url' => '/research/',
        'ignored' => 'must not leak',
    ],
    'news' => [
        'enabled' => false,
        'path' => '/updates/',
        'limit' => 99,
        'heading' => 'LATEST',
        'more_label' => 'All updates',
    ],
    'design' => [
        'logo' => 'brand/logo.svg',
        'key_color' => '#174467',
    ],
    'unknown_group' => ['x' => 'y'],
];
PHP
    );

    $settings = new ThemeSettings($root);
    $normalized = $settings->settings();
    if (($normalized['news']['limit'] ?? 0) !== 10) {
        fail('News limit must be clamped to 10.');
    }
    if (($normalized['design']['key_color'] ?? '') !== '#174467') {
        fail('Valid six-digit key color must be preserved.');
    }

    $context = $settings->templateContext('/tomos');
    if (($context['hero_image_url'] ?? '') !== '/tomos/theme-assets/hero.jpg') {
        fail('Hero asset URL must respect public_base_path.');
    }
    if (($context['logo_url'] ?? '') !== '/tomos/theme-assets/brand/logo.svg') {
        fail('Nested logo asset URL must be generated safely.');
    }
    if (($context['hero_button_url'] ?? '') !== '/tomos/research/') {
        fail('Hero CTA must be an internal public URL.');
    }
    if (empty($context['hero_button_enabled'])) {
        fail('Hero CTA must be enabled only when label and safe URL are present.');
    }
    if (!array_key_exists('news_enabled', $context) || $context['news_enabled'] !== false) {
        fail('News enabled setting must be exposed as a boolean.');
    }

    file_put_contents($root . '/theme-settings.php', <<<'PHP'
<?php
return [
    'hero' => [
        'enabled' => 'yes',
        'image' => '../secret.png',
        'button_label' => 'Bad',
        'button_url' => 'javascript:alert(1)',
    ],
    'news' => [
        'path' => '../private',
        'limit' => 'not-a-number',
    ],
    'design' => [
        'logo' => 'bad.svg',
        'key_color' => 'red',
    ],
];
PHP
    );

    $invalid = new ThemeSettings($root);
    $invalidSettings = $invalid->settings();
    $invalidContext = $invalid->templateContext('');
    if (!empty($invalidSettings['hero']['enabled'])) {
        fail('Non-boolean hero.enabled must fall back safely.');
    }
    if (($invalidContext['hero_image_url'] ?? '') !== '' || ($invalidContext['hero_button_url'] ?? '') !== '') {
        fail('Unsafe hero asset and CTA paths must be rejected.');
    }
    if (($invalidContext['logo_url'] ?? '') !== '') {
        fail('Unsafe SVG must be rejected.');
    }
    if (($invalidSettings['news']['path'] ?? '') !== '/news/' || ($invalidSettings['news']['limit'] ?? 0) !== 5) {
        fail('Invalid News settings must fall back to safe defaults.');
    }
    if (($invalidSettings['design']['key_color'] ?? '') !== '') {
        fail('Invalid key color must be rejected.');
    }

    file_put_contents($root . '/theme-settings.php', "<?php\nreturn [\n");
    $broken = (new ThemeSettings($root))->settings();
    if (!empty($broken['hero']['enabled']) || ($broken['news']['path'] ?? '') !== '/news/') {
        fail('Malformed theme-settings.php must fall back to defaults without breaking rendering.');
    }

    $htaccess = file_get_contents(dirname(__DIR__) . '/.htaccess');
    if (!is_string($htaccess) || strpos($htaccess, 'theme-settings\\.php') === false) {
        fail('theme-settings.php must be denied by the root .htaccess.');
    }

    echo "theme_settings_check: OK\n";
} finally {
    removeTree($root);
}
