<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Tomos\HomeNewsProvider;

function failCheck(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$pages = [
    ['path' => 'news/a.md', 'url' => '/news/a', 'title' => 'A', 'date' => '2026-08-18', 'published' => '2026-08-18T08:00:00+09:00', 'draft' => false],
    ['path' => 'news/b.md', 'url' => '/news/b', 'title' => 'B', 'date' => '2026-08-19', 'published' => '2026-08-19T07:00:00+09:00', 'draft' => false],
    ['path' => 'news/c.md', 'url' => '/news/c', 'title' => 'C', 'date' => '2026-08-19', 'published' => '2026-08-19T08:00:00+09:00', 'draft' => false],
    ['path' => 'news/draft.md', 'url' => '/news/draft', 'title' => 'Draft', 'date' => '2026-08-20', 'published' => '2026-08-20T08:00:00+09:00', 'draft' => true],
    ['path' => 'news/index.md', 'url' => '/news/', 'title' => 'News index', 'date' => '2026-08-21', 'published' => '2026-08-21T08:00:00+09:00', 'draft' => false],
    ['path' => 'blog/x.md', 'url' => '/blog/x', 'title' => 'Other', 'date' => '2026-08-22', 'published' => '2026-08-22T08:00:00+09:00', 'draft' => false],
];

$settings = [
    'news' => [
        'enabled' => true,
        'path' => '/news/',
        'limit' => 2,
    ],
];

$context = (new HomeNewsProvider($pages, $settings, '/tomos'))->context();
if (empty($context['has_news'])) {
    failCheck('Expected News collection to be available.');
}
if (($context['news_url'] ?? '') !== '/tomos/news/') {
    failCheck('News list URL must respect public_base_path.');
}
$items = $context['news_items'] ?? [];
if (count($items) !== 2) {
    failCheck('News limit must be applied.');
}
if (($items[0]['title'] ?? '') !== 'C' || ($items[1]['title'] ?? '') !== 'B') {
    failCheck('News items must reuse PageSorter ordering.');
}
if (($items[0]['url'] ?? '') !== '/tomos/news/c') {
    failCheck('News item URL must respect public_base_path.');
}
if (($items[0]['date'] ?? '') !== '2026-08-19' || ($items[0]['date_display'] ?? '') !== '2026-08-19') {
    failCheck('News date fields must be stable YYYY-MM-DD values.');
}
foreach ($items as $item) {
    if (($item['title'] ?? '') === 'Draft' || ($item['title'] ?? '') === 'News index' || ($item['title'] ?? '') === 'Other') {
        failCheck('Draft, source index, or other-path pages must be excluded.');
    }
}

$disabled = (new HomeNewsProvider($pages, ['news' => ['enabled' => false, 'path' => '/news/', 'limit' => 5]], ''))->context();
if (!empty($disabled['has_news']) || ($disabled['news_items'] ?? []) !== []) {
    failCheck('Disabled News must return no items.');
}

$empty = (new HomeNewsProvider($pages, ['news' => ['enabled' => true, 'path' => '/events/', 'limit' => 5]], ''))->context();
if (!empty($empty['has_news']) || ($empty['news_items'] ?? []) !== []) {
    failCheck('A source path with zero matches must not render an empty News frame.');
}

$custom = (new HomeNewsProvider([
    ['path' => 'updates/a.md', 'url' => '/updates/a', 'title' => 'Update', 'date' => '2026-08-19', 'published' => '2026-08-19T08:00:00+09:00', 'draft' => false],
], ['news' => ['enabled' => true, 'path' => '/updates/', 'limit' => 5]], ''))->context();
if (($custom['news_items'][0]['title'] ?? '') !== 'Update') {
    failCheck('Configured source path must be honored.');
}

echo "home_news_provider_check: OK\n";
