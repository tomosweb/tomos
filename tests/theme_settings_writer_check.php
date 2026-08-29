<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/Security.php';
require_once dirname(__DIR__) . '/core/ConfigWriteLock.php';
require_once dirname(__DIR__) . '/core/ThemeSettingsConfigWriter.php';

use Tomos\ThemeSettingsConfigWriter;

function assertThemeWriter(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/tomos-theme-settings-writer-' . bin2hex(random_bytes(6));
mkdir($root . '/storage', 0700, true);
$path = $root . '/theme-settings.php';
$original = <<<'PHP'
<?php
return [
    'hero' => ['enabled' => true, 'title' => 'Keep Hero'],
    'news' => ['enabled' => false, 'limit' => 3],
    'design' => ['key_color' => '#123456'],
    'folders' => ['news' => ['title' => 'Updates']],
    'navigation' => ['mode' => 'auto', 'items' => []],
];
PHP;
file_put_contents($path, $original . "\n");

try {
    [$current, $loadErrors] = ThemeSettingsConfigWriter::load($path);
    assertThemeWriter($loadErrors === [], 'valid theme settings must load');
    $autoItems = [
        ['path' => '/', 'label' => 'Home'],
        ['path' => '/about', 'label' => 'About'],
        ['path' => '/news/', 'label' => 'News'],
    ];
    [$updated, $errors] = ThemeSettingsConfigWriter::update($current, [
        'navigation_mode' => 'manual',
        'navigation_items' => [
            ['path' => '/news/', 'label' => 'Updates', 'hidden' => '1'],
            ['path' => '/about', 'label' => ''],
        ],
    ], $autoItems);
    assertThemeWriter($errors === [], 'valid navigation update must pass');
    assertThemeWriter(($updated['hero']['title'] ?? '') === 'Keep Hero', 'hero settings must be preserved');
    assertThemeWriter(($updated['news']['limit'] ?? 0) === 3, 'news settings must be preserved');
    assertThemeWriter(($updated['design']['key_color'] ?? '') === '#123456', 'design settings must be preserved');
    assertThemeWriter(($updated['folders']['news']['title'] ?? '') === 'Updates', 'folder settings must be preserved');
    assertThemeWriter(($updated['navigation']['items'][0]['path'] ?? '') === '/news/', 'manual order must be preserved');
    assertThemeWriter(!empty($updated['navigation']['items'][0]['hidden']), 'hidden must be preserved');

    [$uiUpdated, $uiErrors] = ThemeSettingsConfigWriter::update($current, [
        'navigation_mode' => 'manual',
        'navigation_items' => [
            ['path' => '/news', 'label' => 'News UI'],
            ['path' => '/about', 'label' => ''],
        ],
    ], $autoItems);
    assertThemeWriter($uiErrors === [], 'UI navigation paths without trailing slashes must pass');
    assertThemeWriter(($uiUpdated['navigation']['items'][0]['label'] ?? '') === 'News UI', 'UI navigation label must be accepted');

    [, $unsafeErrors] = ThemeSettingsConfigWriter::update($current, [
        'navigation_mode' => 'manual',
        'navigation_items' => [['path' => 'https://example.com/', 'label' => 'External']],
    ], $autoItems);
    assertThemeWriter($unsafeErrors !== [], 'external destinations must be rejected');
    [, $unknownErrors] = ThemeSettingsConfigWriter::update($current, [
        'navigation_mode' => 'manual',
        'navigation_items' => [['path' => '/unknown/', 'label' => 'Unknown']],
    ], $autoItems);
    assertThemeWriter($unknownErrors !== [], 'unknown destinations must be rejected');

    assertThemeWriter(ThemeSettingsConfigWriter::write($path, $current, $updated, $root), 'valid update must write atomically');
    [$saved, $saveErrors] = ThemeSettingsConfigWriter::load($path);
    assertThemeWriter($saveErrors === [] && ($saved['navigation']['mode'] ?? '') === 'manual', 'saved navigation settings must reload');
    assertThemeWriter(($saved['hero']['title'] ?? '') === 'Keep Hero', 'saved update must retain other settings');

    [$autoSettings, $autoErrors] = ThemeSettingsConfigWriter::update($saved, [
        'navigation_mode' => 'auto',
        'navigation_items' => [
            ['path' => '/', 'label' => '', 'hidden' => '1'],
        ],
    ], $autoItems);
    assertThemeWriter($autoErrors === [] && ($autoSettings['navigation']['mode'] ?? '') === 'auto', 'auto mode must be saveable');

    $before = (string) file_get_contents($path);
    $stale = $current;
    $stale['hero']['title'] = 'stale';
    assertThemeWriter(!ThemeSettingsConfigWriter::write($path, $stale, $current, $root), 'stale write must be rejected');
    assertThemeWriter((string) file_get_contents($path) === $before, 'rejected write must preserve existing file');

    echo "theme_settings_writer_check: preservation, validation, atomic write, and stale-write protection passed\n";
} finally {
    @unlink($path);
    @unlink($root . '/storage/config-write.lock');
    @rmdir($root . '/storage');
    @rmdir($root);
}
