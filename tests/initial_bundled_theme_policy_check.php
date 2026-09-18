<?php

declare(strict_types=1);

use Tomos\InitialBundledThemes;
use Tomos\ThemeRepository;
use Tomos\ThemeValidator;

require_once dirname(__DIR__) . '/core/ThemeRules.php';
require_once dirname(__DIR__) . '/core/ThemeValidator.php';
require_once dirname(__DIR__) . '/core/ThemeRepository.php';
require_once dirname(__DIR__) . '/core/InitialBundledThemes.php';

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function makeTheme(string $root, string $name): void
{
    $themeDir = $root . DIRECTORY_SEPARATOR . $name;
    foreach (['templates', 'assets'] as $directory) {
        if (!mkdir($themeDir . DIRECTORY_SEPARATOR . $directory, 0777, true) && !is_dir($themeDir . DIRECTORY_SEPARATOR . $directory)) {
            fail('failed to create fixture directory: ' . $directory);
        }
    }

    $metadata = [
        'name' => $name,
        'display_name' => $name . ' fixture',
        'version' => '1.0.0',
        'description' => 'fixture',
        'author' => 'Tomos',
    ];
    file_put_contents($themeDir . '/theme.json', json_encode($metadata, JSON_THROW_ON_ERROR));
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

$expected = [
    'tomos-minimal',
    'tomos-note',
    'tomos-90s',
    'tomos-dark',
    'tomos-journal',
    'tomos-blog',
];
if (InitialBundledThemes::names() !== $expected) {
    fail('initial bundled Theme definition does not match the distribution policy.');
}

$root = sys_get_temp_dir() . '/tomos-initial-themes-' . bin2hex(random_bytes(6));
$themesDir = $root . '/themes';
if (!mkdir($themesDir, 0777, true) && !is_dir($themesDir)) {
    fail('failed to create Theme fixture root.');
}
file_put_contents($root . '/VERSION', "1.0.4\n");

try {
    foreach (array_merge($expected, ['tomos-quiet', 'tomos-index', 'custom-theme']) as $themeName) {
        makeTheme($themesDir, $themeName);
    }

    $allThemes = (new ThemeRepository($themesDir, new ThemeValidator($themesDir)))->all();
    foreach (['tomos-quiet', 'tomos-index', 'custom-theme'] as $extraTheme) {
        if (!array_key_exists($extraTheme, $allThemes)) {
            fail('Post Theme manager repository no longer exposes installed extra Theme: ' . $extraTheme);
        }
    }

    $setupThemes = InitialBundledThemes::only($allThemes);
    if (array_keys($setupThemes) !== $expected) {
        fail('Setup Theme list does not match the initial bundled allowlist.');
    }
    foreach (['tomos-quiet', 'tomos-index', 'custom-theme'] as $extraTheme) {
        if (array_key_exists($extraTheme, $setupThemes)) {
            fail('non-bundled Theme leaked into Setup list: ' . $extraTheme);
        }
    }

    $setupSource = (string) file_get_contents(dirname(__DIR__) . '/setup/index.php');
    if (strpos($setupSource, 'InitialBundledThemes::only') === false) {
        fail('Setup must apply the initial bundled Theme policy.');
    }
    $postSource = (string) file_get_contents(dirname(__DIR__) . '/post/theme/index.php');
    if (strpos($postSource, '->all()') === false || strpos($postSource, 'InitialBundledThemes::only') !== false) {
        fail('Post Theme manager must continue to list all installed Themes.');
    }

    echo 'initial_bundled_theme_policy_check: OK (' . count($expected) . ' bundled themes; extra Themes remain Post-managed)' . PHP_EOL;
} finally {
    removeTree($root);
}
