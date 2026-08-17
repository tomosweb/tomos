<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/ImageProcessor.php';

use Tomos\ImageProcessor;

$root = sys_get_temp_dir() . '/tomos-image-fallback-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);

try {
    $source = $root . '/source.png';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nNwAAAAASUVORK5CYII=', true);
    if (!is_string($png) || file_put_contents($source, $png) === false) {
        throw new RuntimeException('PNG fixture could not be created.');
    }

    $result = (new ImageProcessor(true))->process($source, 'png', $root . '/tmp');
    if (!$result->ok || !is_file($result->path)) {
        throw new RuntimeException('forced original fallback must still save the image successfully');
    }
    if ($result->warnings !== []) {
        throw new RuntimeException('successful implementation fallback must not expose a user warning');
    }

    $imageSource = (string) file_get_contents(dirname(__DIR__) . '/core/ImageProcessor.php');
    foreach ([
        '画像加工機能が使えないため、画像を元のまま保存しました。',
        'サーバーの画像加工用メモリが不足する可能性があるため、画像を元のまま保存しました。',
        '画像を加工できなかったため、画像を元のまま保存しました。',
    ] as $internalWarning) {
        if (strpos($imageSource, $internalWarning) !== false) {
            throw new RuntimeException('internal fallback reason must not remain as a user-facing warning: ' . $internalWarning);
        }
    }

    if (strpos($imageSource, 'logProcessingFallback') === false) {
        throw new RuntimeException('fallback reasons should remain diagnosable through internal logging');
    }

    echo "image_fallback_warning_check: OK\n";
} finally {
    removeTree($root);
}

function removeTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        removeTree($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}
