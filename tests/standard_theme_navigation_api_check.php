<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$themes = ['tomos-minimal', 'tomos-note', 'tomos-90s', 'tomos-dark', 'tomos-journal', 'tomos-blog'];

foreach ($themes as $theme) {
    $layout = $root . '/themes/' . $theme . '/templates/layout.html';
    $source = is_file($layout) ? (string) file_get_contents($layout) : '';
    if (strpos($source, 'nav.primary_items') === false && strpos($source, 'nav.primary_links') === false) {
        throw new RuntimeException($theme . ' does not consume the generic primary navigation API');
    }
}

echo "standard_theme_navigation_api_check: all six bundled themes consume generic primary navigation API\n";
