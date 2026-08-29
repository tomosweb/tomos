<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/Security.php';
require_once dirname(__DIR__) . '/core/ThemeSettings.php';
require_once dirname(__DIR__) . '/core/PageSorter.php';
require_once dirname(__DIR__) . '/core/NavigationBuilder.php';

use Tomos\NavigationBuilder;
use Tomos\ThemeSettings;

function assertNavigation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/tomos-navigation-settings-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
file_put_contents($root . '/theme-settings.php', <<<'PHP'
<?php
return [
    'navigation' => [
        'mode' => 'manual',
        'items' => [
            ['path' => '/members/', 'hidden' => true],
            ['path' => '/research/', 'label' => 'Our Research'],
            ['path' => '/about/'],
            ['path' => 'https://example.com/'],
            ['path' => '/research/?next=/outside/'],
            ['path' => '/unknown/'],
        ],
    ],
];
PHP
);

try {
    $settings = (new ThemeSettings($root))->settings()['navigation'] ?? [];
    assertNavigation(($settings['mode'] ?? '') === 'manual', 'manual mode must be preserved');
    assertNavigation(count($settings['items'] ?? []) === 4, 'invalid navigation items must be ignored');
    assertNavigation(($settings['items'][0]['path'] ?? '') === '/members/', 'declared navigation order must be preserved');
    assertNavigation(($settings['items'][0]['hidden'] ?? false) === true, 'hidden must preserve true');
    assertNavigation(($settings['items'][1]['label'] ?? '') === 'Our Research', 'label override must be normalized');
    assertNavigation(($settings['items'][2]['label'] ?? '') === '', 'omitted label must remain omitted');

    $pages = [
        ['path' => 'research/index.md', 'url' => '/research/', 'title' => 'Research', 'draft' => false],
        ['path' => 'members/index.md', 'url' => '/members/', 'title' => 'Members', 'draft' => false],
        ['path' => 'publications/index.md', 'url' => '/publications/', 'title' => 'Publications', 'draft' => false],
        ['path' => 'research/project-a.md', 'url' => '/research/project-a', 'title' => 'Article A', 'draft' => false],
    ];
    $auto = new NavigationBuilder('');
    $autoItems = $auto->primaryItems($pages, '/');
    assertNavigation(count($autoItems) === 8, 'auto mode output item count must remain compatible');
    assertNavigation(($autoItems[0]['label'] ?? '') === 'Home' && ($autoItems[1]['label'] ?? '') === 'About', 'auto base order must remain compatible');

    $manual = new NavigationBuilder('', $settings);
    $manualItems = $manual->primaryItems($pages, '/');
    assertNavigation(count($manualItems) === 2, 'hidden and unresolved items must be omitted from primary navigation');
    assertNavigation(($manualItems[0]['label'] ?? '') === 'Our Research', 'manual label override must render');
    assertNavigation(($manualItems[1]['label'] ?? '') === 'About', 'omitted label must use auto label');
    assertNavigation(!in_array('/unknown/', array_column($manualItems, 'path'), true), 'unresolved manual paths must be ignored');
    assertNavigation(($manualItems[0]['url'] ?? '') === '/research/', 'manual order must render the configured URL');
    assertNavigation(array_search('/members/', array_column($pages, 'url'), true) !== false, 'hidden destination must remain publicly reachable');
    assertNavigation(strpos($manual->primaryLinks($pages, '/'), 'Our Research') !== false, 'primary_links must use manual settings');

    $tree = $manual->tree($pages, '/research/project-a');
    assertNavigation(strpos($tree, '<summary>Our Research</summary>') !== false, 'tree must use the manual section label');
    assertNavigation(strpos($tree, 'Publications') === false, 'tree must omit manual-unlisted sections');
    assertNavigation(strpos($tree, 'Members') === false, 'tree must omit hidden sections');
    assertNavigation(strpos($tree, 'Article A') !== false, 'tree must preserve child page titles');

    $sections = $manual->sectionLinks($pages, '/research/');
    assertNavigation(strpos($sections, 'Our Research') !== false, 'section links must use the manual section label');
    assertNavigation(strpos($sections, 'Publications') === false, 'section links must omit manual-unlisted sections');
    assertNavigation($manual->breadcrumbs($pages, '/research/project-a') === '<nav class="breadcrumbs" aria-label="パンくず"><a href="/">Home</a> <span aria-hidden="true">/</span> <a href="/research/">Our Research</a> <span aria-hidden="true">/</span> <span>Article A</span></nav>', 'breadcrumb must apply only the top-level label');

    $orderedSettings = [
        'mode' => 'manual',
        'items' => [
            ['path' => '/publications/', 'label' => 'Pubs'],
            ['path' => '/research/', 'label' => 'Research'],
        ],
    ];
    $orderedTree = (new NavigationBuilder('', $orderedSettings))->tree($pages);
    assertNavigation(strpos($orderedTree, '<summary>Pubs</summary>') < strpos($orderedTree, '<summary>Research</summary>'), 'tree must preserve manual section order');
    $orderedSections = (new NavigationBuilder('', $orderedSettings))->sectionLinks($pages);
    assertNavigation(strpos($orderedSections, 'Pubs') < strpos($orderedSections, 'Research'), 'section links must preserve manual section order');

    $subdirectory = new NavigationBuilder('/theme-labo', $settings);
    assertNavigation(strpos($subdirectory->tree($pages, '/research/project-a'), 'href="/theme-labo/research/"') !== false, 'tree must preserve the public subdirectory');
    assertNavigation(strpos($subdirectory->breadcrumbs($pages, '/research/project-a'), 'href="/theme-labo/research/"') !== false, 'breadcrumbs must preserve the public subdirectory');

    echo "navigation_settings_check: auto compatibility, manual order, labels, hidden, invalid paths, and reachability passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
