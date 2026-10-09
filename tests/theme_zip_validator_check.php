<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/ThemeZipValidator.php';

use Tomos\ThemeZipValidator;

if (!class_exists('ZipArchive')) {
    echo "Theme ZIP validator: SKIP (zip extension unavailable)\n";
    exit(0);
}

$source = dirname(__DIR__) . '/themes/tomos-minimal';
$zipPath = tempnam(sys_get_temp_dir(), 'tomos-theme-valid-');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Unable to prepare fixture ZIP');
}
$entries = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)
);
foreach ($entries as $file) {
    if ($file->isFile()) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        $zip->addFile($file->getPathname(), 'tomos-minimal/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative));
    }
}
$zip->close();

try {
    $result = ThemeZipValidator::validate($zipPath);
    if ($result['name'] !== 'tomos-minimal') {
        throw new RuntimeException('Validated ZIP theme name mismatch');
    }
    echo "Theme ZIP validator: PASS\n";
} finally {
    unlink($zipPath);
}
