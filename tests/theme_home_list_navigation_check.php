<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/ThemeValidator.php';

use Tomos\ThemeValidator;

$root = sys_get_temp_dir() . '/tomos-theme-home-list-' . bin2hex(random_bytes(6));
$themesDir = $root . '/themes';

function failCheck(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function writeTheme(string $themesDir, string $name, string $home): void
{
    $base = $themesDir . '/' . $name;
    mkdir($base . '/templates', 0777, true);
    mkdir($base . '/assets', 0777, true);

    file_put_contents($base . '/theme.json', json_encode([
        'name' => $name,
        'display_name' => $name,
        'version' => '1.0.0',
        'description' => 'fixture',
        'author' => 'Tomos Project',
        'supports' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($base . '/templates/layout.html', '<html><head>{{{ page.seo_head_html }}}</head><body>{{{ page.body }}}</body></html>');
    file_put_contents($base . '/templates/page.html', '<article>{{{ page.content }}}</article>');
    file_put_contents($base . '/templates/list.html', '<section>{{{ list.pages }}}</section>');
    file_put_contents($base . '/templates/home.html', $home);
    file_put_contents($base . '/assets/style.css', 'body { margin: 0; }');
    file_put_contents($base . '/assets/favicon.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
}

try {
    writeTheme($themesDir, 'missing-list-link', '<section>{{{ list.latest_pages }}}</section>');
    writeTheme($themesDir, 'with-sections', '<section>{{{ list.latest_pages }}}<nav>{{{ nav.sections }}}</nav></section>');
    writeTheme($themesDir, 'with-primary-items', '<section>{{{ list.latest_pages }}}{{# nav.primary_items }}<a href="{{ url }}">{{ label }}</a>{{/ nav.primary_items }}</section>');

    $validator = new ThemeValidator($themesDir);

    $missing = $validator->validate('missing-list-link');
    if (!empty($missing['valid'])) {
        failCheck('home.html using list.latest_pages without a list-navigation variable must be invalid');
    }
    $errorText = implode("\n", $missing['errors'] ?? []);
    if (strpos($errorText, '記事一覧への導線') === false) {
        failCheck('missing list-navigation error message was not reported');
    }

    foreach (['with-sections', 'with-primary-items'] as $name) {
        $result = $validator->validate($name);
        if (empty($result['valid'])) {
            failCheck($name . ' should pass the latest-pages navigation contract: ' . implode('; ', $result['errors'] ?? []));
        }
    }

    echo "PASS: latest-pages home templates require article-list navigation\n";
} finally {
    if (is_dir($root)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($root);
    }
}
