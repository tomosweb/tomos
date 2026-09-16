<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/Security.php';
require_once dirname(__DIR__) . '/core/NavigationBuilder.php';

use Tomos\NavigationBuilder;

function failCheck(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$pages = [];
for ($i = 1; $i <= 35; $i++) {
    $slug = sprintf('post-%02d', $i);
    $pages[] = [
        'path' => 'blog/' . $slug . '.md',
        'url' => '/blog/' . $slug,
        'title' => 'Post ' . $i,
        'date' => sprintf('2026-09-%02d', (($i - 1) % 28) + 1),
        'draft' => false,
    ];
}

$navigation = new NavigationBuilder('');

$latest = $navigation->latestPageList($pages, 12);
if (substr_count($latest, '<li') !== 12) {
    failCheck('latestPageList must render exactly 12 homepage entries when limit=12');
}
if (strpos($latest, 'folder-pagination') !== false || strpos($latest, '?page=2') !== false) {
    failCheck('homepage latest list must not render pagination');
}

$page1 = $navigation->folderPageList($pages, 'blog', 1, 30);
if (preg_match('/<ul class="page-list-items">(.*?)<\/ul>/s', $page1, $matches) !== 1) {
    failCheck('folder page 1 did not render page-list-items');
}
if (substr_count($matches[1], '<li') !== 30) {
    failCheck('folder page 1 must render 30 entries');
}
if (strpos($page1, '?page=2') === false) {
    failCheck('folder page 1 must link to page 2 when more than 30 entries exist');
}

$page2 = $navigation->folderPageList($pages, 'blog', 2, 30);
if (preg_match('/<ul class="page-list-items">(.*?)<\/ul>/s', $page2, $matches) !== 1) {
    failCheck('folder page 2 did not render page-list-items');
}
if (substr_count($matches[1], '<li') !== 5) {
    failCheck('folder page 2 must render the remaining 5 entries');
}
if (strpos($page2, 'rel="prev"') === false) {
    failCheck('folder page 2 must link back to the previous page');
}

echo "PASS: homepage=12 without pagination; folder list=30 with pagination\n";
