<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PublishedMetadata.php';
require_once dirname(__DIR__) . '/core/PostPublisher.php';
require_once dirname(__DIR__) . '/core/PostUpload.php';

function assertIssue52(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-issue-52-' . bin2hex(random_bytes(6));
mkdir($root . '/content', 0775, true);
mkdir($root . '/cache', 0775, true);
$upload = new Tomos\PostUpload([
    'paths' => ['content_dir' => $root . '/content', 'cache_dir' => $root . '/cache'],
    'site' => ['timezone' => 'Asia/Tokyo'],
], $root);
$method = new ReflectionMethod($upload, 'withInitialPublishedMetadata');

$emptyDate = $method->invoke($upload, "---\ntitle: Empty\ndate:\ndraft: false\n---\n# Empty\n");
assertIssue52(preg_match('/^date: \d{4}-\d{2}-\d{2}$/m', $emptyDate) === 1, 'empty date was not completed');
assertIssue52(preg_match('/^published: \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+09:00$/m', $emptyDate) === 1, 'published was not added in the site timezone');

$missingDate = $method->invoke($upload, "---\ntitle: Missing\ndraft: false\n---\n# Missing\n");
assertIssue52(preg_match('/^date: \d{4}-\d{2}-\d{2}$/m', $missingDate) === 1, 'missing date was not added');
assertIssue52(preg_match('/^published: \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+09:00$/m', $missingDate) === 1, 'published was not added for missing date');

$explicitDate = "---\ntitle: Explicit\ndate: 2026-07-01\ndraft: false\n---\n# Explicit\n";
$explicitResult = $method->invoke($upload, $explicitDate);
assertIssue52(strpos($explicitResult, 'date: 2026-07-01') !== false, 'explicit date was changed');
assertIssue52(substr_count($explicitResult, 'date:') === 1, 'explicit date was duplicated');

$existingPublished = "---\ntitle: Existing\ndate:\npublished: 2026-07-01T10:00:00+09:00\ndraft: false\n---\n# Existing\n";
$existingResult = $method->invoke($upload, $existingPublished);
assertIssue52(preg_match('/^date: \d{4}-\d{2}-\d{2}$/m', $existingResult) === 1, 'date was not completed with existing published');
assertIssue52(strpos($existingResult, 'published: 2026-07-01T10:00:00+09:00') !== false, 'existing published was changed');

$draft = "---\ntitle: Draft\ndate:\ndraft: true\n---\n# Draft\n";
assertIssue52($method->invoke($upload, $draft) === $draft, 'draft received initial metadata');

$noFrontMatter = $method->invoke($upload, "# No Front Matter\n");
assertIssue52(strpos($noFrontMatter, "date: ") !== false && strpos($noFrontMatter, "published: ") !== false, 'front matter was not added');

$updated = $root . '/content/existing.md';
file_put_contents($updated, "---\ntitle: Existing\ndate: 2026-07-01\npublished: 2026-07-01T10:00:00+09:00\n---\n# Existing\n");
$publisher = new Tomos\PostPublisher($root . '/content', $root . '/cache', new Tomos\FrontMatterParser(), false, false, ['timezone' => 'Asia/Tokyo']);
$update = $publisher->updateExisting($updated, "---\ntitle: Existing\ndate: 2026-07-01\npublished: 2026-07-01T10:00:00+09:00\n---\n# Updated\n", [], '');
assertIssue52($update->ok, 'existing article update failed');
assertIssue52(strpos((string) file_get_contents($updated), 'date:') !== false && strpos((string) file_get_contents($updated), 'date: 2026-07-01') !== false, 'existing article date was changed during update');

echo "issue_52_date_completion_check: OK\n";
