<?php

declare(strict_types=1);

// Deliberately independent of UpdateFileSet: detect excluded/deleted runtime too.
function v111Paths(): array
{
    return ['VERSION', 'core/PostPublished.php', 'post/assets/tomos-post-ui.css', 'post/index.php'];
}

function checkV111Scope(string $root, string $baseline = 'v1.1.10'): void
{
    $sha = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse ' . escapeshellarg($baseline . '^{commit}')));
    if ($sha !== '518d5a6c43a07d09e7e8cac51d957e1d1923758e') {
        throw new RuntimeException('v1.1.11 baseline must be the immutable public v1.1.10 commit');
    }
    $lines = [];
    exec('git -C ' . escapeshellarg($root) . ' diff --name-only --no-renames ' . escapeshellarg($sha) . ' HEAD --', $lines, $status);
    if ($status !== 0) {
        throw new RuntimeException('source diff failed');
    }
    $runtime = [];
    foreach ($lines as $path) {
        if (preg_match('#\A(?:tests/|tools/|docs/|\.github/)#', $path) || in_array($path, ['README.md', 'CHANGELOG.md'], true)) {
            // Only runtime docs file is forbidden even under docs/.
            if ($path !== 'docs/theme/theme-rules.json') {
                continue;
            }
        }
        $runtime[] = $path;
    }
    sort($runtime);
    if ($runtime !== v111Paths()) {
        throw new RuntimeException('unexpected source runtime delta: ' . json_encode($runtime));
    }
    if (trim((string) file_get_contents($root . '/VERSION')) !== '1.1.11') {
        throw new RuntimeException('wrong target VERSION');
    }
    echo "PASS: immutable v1.1.10 source runtime delta is exactly four files\n";
}

function checkV111Zip(ZipArchive $zip, string $root): void
{
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->getNameIndex($i);
    }
    $expected = ['manifest.json', 'manifest.sig'];
    foreach (v111Paths() as $path) {
        $expected[] = 'files/' . $path;
        if ($zip->getFromName('files/' . $path) !== file_get_contents($root . '/' . $path)) {
            throw new RuntimeException('ZIP bytes differ from isolated source: ' . $path);
        }
    }
    sort($entries);
    sort($expected);
    if ($entries !== $expected) {
        throw new RuntimeException('unexpected ZIP entries: ' . json_encode($entries));
    }
    echo "PASS: Update ZIP entries and all four source payloads match exactly\n";
}
