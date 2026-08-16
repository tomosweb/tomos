<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$passes = 0;

function check(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

function readDocument(string $root, string $relative): string
{
    $path = $root . '/' . $relative;
    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('could not read ' . $relative);
    }
    return $contents;
}

$version = trim(readDocument($root, 'VERSION'));
check(preg_match('/\A[0-9]+(?:\.[0-9]+)*(?:-[0-9A-Za-z.-]+)?\z/', $version) === 1, 'VERSION has a valid format');

$readme = readDocument($root, 'README.md');
$install = readDocument($root, 'INSTALL.md');
$changelog = readDocument($root, 'CHANGELOG.md');
$limitations = readDocument($root, 'KNOWN_LIMITATIONS.md');
$updateGuide = readDocument($root, 'docs/install/update.md');

check(strpos($readme, '現在のバージョンは `v' . $version . '`') !== false, 'README current version matches VERSION');

$releaseNotePath = 'docs/releases/v' . $version . '.md';
$releaseNote = readDocument($root, $releaseNotePath);
check(strpos($releaseNote, '# Tomos v' . $version) === 0, 'release note H1 matches VERSION');

preg_match('/^## (v[^\s]+)(?:\s+-\s+[^\r\n]+)?$/m', $changelog, $heading);
check(($heading[1] ?? null) === 'v' . $version, 'CHANGELOG first version heading matches VERSION');

$staleTokens = [
    '現在のバージョンは v0.1.0-alpha.16',
    'インストール対象バージョン:',
    'Login is not implemented',
    'Admin screens are not implemented',
    'Theme upload is not implemented',
    'Automatic updates are not implemented',
];
foreach (['README.md' => $readme, 'INSTALL.md' => $install, 'KNOWN_LIMITATIONS.md' => $limitations] as $name => $document) {
    foreach ($staleTokens as $token) {
        check(strpos($document, $token) === false, $name . ' has no stale token: ' . $token);
    }
}

check(strpos($install, 'tomos-<VERSION>.zip') !== false, 'INSTALL uses a version-independent distribution ZIP example');
check(preg_match('/tomos-[0-9]+(?:\.[0-9]+)+(?:-[0-9A-Za-z.-]+)?\.zip/', $install) !== 1, 'INSTALL does not hard-code a versioned distribution ZIP');
check(strpos($updateGuide, '## 現在の更新方法（alpha.18以降）') !== false, 'update guide has current update instructions');
check(strpos($updateGuide, 'v0.1.0-alpha.18からv0.1.0-alpha.19への更新') !== false, 'update guide describes alpha.18 to alpha.19');
check(strpos($updateGuide, 'v0.1.0-alpha.17からv0.1.0-alpha.18への移行') !== false, 'update guide describes the alpha.17 bootstrap');
check(strpos($updateGuide, '/post/update-finalize/') !== false, 'update guide preserves finalize instructions');

echo "release_docs_check: {$passes} checks passed\n";
