<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/ThemeZipRepositoryPayload.php';

use Tomos\ThemeZipRepositoryPayload;

if (!class_exists('ZipArchive')) {
    echo "Theme GitHub payload: SKIP (zip extension unavailable)\n";
    exit(0);
}

$source = dirname(__DIR__) . '/themes/tomos-minimal';
$zipPath = tempnam(sys_get_temp_dir(), 'tomos-theme-payload-');
$archive = new ZipArchive();
if ($archive->open($zipPath, ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Could not create fixture archive.');
}
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)
);
$original = [];
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($source) + 1));
    $contents = file_get_contents($file->getPathname());
    $archive->addFromString('tomos-minimal/' . $relative, $contents);
    $original['themes/tomos-minimal/' . $relative] = $contents;
}
$archive->close();

try {
    $payload = ThemeZipRepositoryPayload::prepare($zipPath);
    if ($payload['name'] !== 'tomos-minimal' || $payload['count'] !== count($original)) {
        throw new RuntimeException('Theme package metadata mismatch.');
    }
    $paths = [];
    foreach ($payload['files'] as $file) {
        if ($file['mode'] !== '100644' || $file['type'] !== 'blob' || $file['encoding'] !== 'base64') {
            throw new RuntimeException('Unexpected Git blob descriptor.');
        }
        if (!array_key_exists($file['path'], $original) ||
            base64_decode($file['content'], true) !== $original[$file['path']]) {
            throw new RuntimeException('Theme payload bytes differ from source archive.');
        }
        $paths[] = $file['path'];
    }
    $sorted = $paths;
    sort($sorted, SORT_STRING);
    if ($paths !== $sorted) {
        throw new RuntimeException('Git blob descriptors are not sorted.');
    }
    echo "Theme GitHub payload: PASS\n";
} finally {
    unlink($zipPath);
}
