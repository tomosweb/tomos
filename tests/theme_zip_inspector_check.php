<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/ThemeZipInspector.php';

use Tomos\ThemeZipInspector;

if (!class_exists('ZipArchive')) {
    echo "Theme ZIP check: SKIP (zip extension unavailable)\n";
    exit(0);
}

function fixtureZip(array $files): string
{
    $path = tempnam(sys_get_temp_dir(), 'tomos-zip-');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('fixture ZIP open failed');
    }
    foreach ($files as $name => $body) {
        $zip->addFromString($name, $body);
    }
    $zip->close();
    return $path;
}

function fails(array $files): void
{
    $path = fixtureZip($files);
    try {
        try {
            ThemeZipInspector::inspect($path);
            throw new RuntimeException('Unsafe ZIP passed validation');
        } catch (InvalidArgumentException $expected) {
        }
    } finally {
        unlink($path);
    }
}

$valid = [
    'custom/theme.json' => '{"name":"custom","display_name":"Custom","version":"1.0.0"}',
];
require_once dirname(__DIR__) . '/core/ThemeRules.php';
foreach (Tomos\ThemeRules::requiredFiles() as $name) {
    if ($name !== 'theme.json') {
        $valid['custom/' . $name] = 'test';
    }
}
$path = fixtureZip($valid);
try {
    $result = ThemeZipInspector::inspect($path);
    if ($result['name'] !== 'custom' || !isset($result['entries']['theme.json'])) {
        throw new RuntimeException('Valid theme ZIP not recognized');
    }
} finally {
    unlink($path);
}

fails(array_merge($valid, ['../escape.txt' => 'unsafe']));
fails(array_merge($valid, ['custom/evil.php' => '<?php']));
fails(['theme.json' => '{"name":"wrong/unsafe","display_name":"Test","version":"1.0.0"}']);
echo "Theme ZIP inspector: PASS\n";
