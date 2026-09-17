<?php
if (!defined('TOMOS_THEME_SETTINGS_CONTEXT') || TOMOS_THEME_SETTINGS_CONTEXT !== true) {
    http_response_code(404);
    return [];
}

return [
    'hero' => [
        'enabled' => true,
        'image' => 'hero.svg',
        'title' => 'Exploring Molecular Interfaces',
        'subtitle' => 'Example Research Group, Example University',
        'button_label' => 'Our Research',
        'button_url' => '/research/',
    ],
    'news' => [
        'enabled' => true,
        'path' => '/news/',
        'limit' => 5,
        'heading' => 'NEWS',
        'more_label' => 'View all',
    ],
    'design' => [
        'logo' => 'logo.svg',
        'key_color' => '#2f6175',
    ],
    'navigation' => [
        'mode' => 'manual',
        'items' => [
            ['path' => '/research/', 'label' => 'Our Research'],
            ['path' => '/members/', 'label' => 'People'],
            ['path' => '/about/'],
            ['path' => '/publications/', 'hidden' => true],
            ['path' => '/search/', 'label' => 'Find'],
        ],
    ],
    'folders' => [
        'research' => ['title' => 'Research'],
        'members' => ['title' => 'Members'],
        'publications' => ['title' => 'Publications'],
        'news' => ['title' => 'News'],
    ],
];
