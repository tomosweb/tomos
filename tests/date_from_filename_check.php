<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PublishedMetadata.php';
require_once dirname(__DIR__) . '/core/FrontMatterParser.php';
require_once dirname(__DIR__) . '/core/PageSorter.php';

use Tomos\FrontMatterParser;
use Tomos\PageSorter;

function failDateCheck(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$parser = new FrontMatterParser();

$missing = $parser->buildPageMetadata(
    ['title' => 'Missing date'],
    '# Missing date',
    'news/2026-08-18-missing-date.md'
);
if (($missing['date'] ?? null) !== '2026-08-18') {
    failDateCheck('Missing date must be inferred from a valid YYYY-MM-DD filename prefix.');
}

$explicit = $parser->buildPageMetadata(
    ['title' => 'Explicit date', 'date' => '2026-07-01'],
    '# Explicit date',
    'news/2026-08-18-explicit-date.md'
);
if (($explicit['date'] ?? null) !== '2026-07-01') {
    failDateCheck('Explicit Front Matter date must take precedence over filename date.');
}

$undated = $parser->buildPageMetadata(
    ['title' => 'Undated'],
    '# Undated',
    'news/undated.md'
);
if (($undated['date'] ?? null) !== null) {
    failDateCheck('Files without a YYYY-MM-DD prefix must remain undated.');
}

$invalid = $parser->buildPageMetadata(
    ['title' => 'Invalid date'],
    '# Invalid date',
    'news/2026-02-30-invalid.md'
);
if (($invalid['date'] ?? null) !== null) {
    failDateCheck('Invalid calendar dates in filenames must not be inferred.');
}

$pages = [
    ['path' => 'news/2026-08-06-old.md', 'date' => '2026-08-06', 'published' => null],
    ['path' => 'news/2026-08-18-new.md', 'date' => $missing['date'], 'published' => null],
];
$pages = PageSorter::sort($pages);
if (($pages[0]['path'] ?? '') !== 'news/2026-08-18-new.md') {
    failDateCheck('Inferred date must participate in the standard PageSorter ordering.');
}

echo "date_from_filename_check: OK\n";
