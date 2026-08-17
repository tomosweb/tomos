<?php

declare(strict_types=1);

$files = [
    'MetadataIndex' => dirname(__DIR__) . '/core/MetadataIndex.php',
    'LinkAliasIndex' => dirname(__DIR__) . '/core/LinkAliasIndex.php',
    'ImageReferenceIndex' => dirname(__DIR__) . '/core/ImageReferenceIndex.php',
];

foreach ($files as $label => $path) {
    $source = @file_get_contents($path);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException($label . ' source could not be read');
    }

    if (preg_match('/\.tmp[\'\"]\s*;/', $source) === 1) {
        throw new RuntimeException($label . ' still contains a fixed .tmp staging path');
    }
    if (strpos($source, ".tmp-") === false || strpos($source, 'random_bytes(') === false) {
        throw new RuntimeException($label . ' must use randomized same-target temporary paths');
    }
}

echo "derived_index_random_temp_check: OK\n";
