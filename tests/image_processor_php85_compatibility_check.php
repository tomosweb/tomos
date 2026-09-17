<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/ImageProcessor.php';

$root = sys_get_temp_dir() . '/tomos-image-php85-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$deprecated = [];
$previous = set_error_handler(static function (int $severity, string $message) use (&$deprecated): bool {
    if ($severity === E_DEPRECATED) $deprecated[] = $message;
    return false;
});

try {
    $source = $root . '/source.png';
    $image = imagecreatetruecolor(2, 2);
    if ($image === false || imagepng($image, $source) !== true) throw new RuntimeException('PNG fixture could not be created');
    unset($image);
    $result = (new Tomos\ImageProcessor())->process($source, 'png', $root . '/tmp');
    if (!$result->ok || !is_file($result->path)) throw new RuntimeException('GD image processing must preserve the valid PNG fixture');
    if ($deprecated !== []) throw new RuntimeException('unexpected PHP deprecation: ' . implode('; ', $deprecated));
    echo "image_processor_php85_compatibility_check: OK\n";
} finally {
    if ($previous !== null) restore_error_handler();
    removeTree($root);
}

function removeTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($child) && !is_link($child) ? removeTree($child) : @unlink($child);
    }
    @rmdir($path);
}
