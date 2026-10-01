<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/ImageProcessingSupport.php';

use Tomos\ImageProcessingSupport;

$highResolutionEstimate = ImageProcessingSupport::estimateGdPeakBytes(5712, 4284, 2048, true);
$browserDerivativeEstimate = ImageProcessingSupport::estimateGdPeakBytes(1536, 2048, 2048, false);
if ($highResolutionEstimate <= 128 * 1024 * 1024) {
    throw new RuntimeException('The original 5712x4284 EXIF image should exceed the conservative 128MiB GD budget.');
}
if ($browserDerivativeEstimate >= 128 * 1024 * 1024) {
    throw new RuntimeException('The 1536x2048 browser derivative should fit the conservative 128MiB GD budget.');
}

$oldLimit = (string) ini_get('memory_limit');
$changedLimit = @ini_set('memory_limit', '128M');
if ($changedLimit !== false) {
    $originalAssessment = ImageProcessingSupport::assessGdMemory($highResolutionEstimate);
    $derivativeAssessment = ImageProcessingSupport::assessGdMemory($browserDerivativeEstimate);
    if ($originalAssessment['allowed']) {
        throw new RuntimeException('The original high-resolution source must be rejected by the 128MiB guard.');
    }
    if (!$derivativeAssessment['allowed']) {
        throw new RuntimeException('The browser-sized derivative should pass the 128MiB guard.');
    }
    @ini_set('memory_limit', $oldLimit);
}

$logPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-image-support-' . bin2hex(random_bytes(6)) . '.log';
try {
    ImageProcessingSupport::log($logPath, 'article-image', 'memory_guard', [
        'bytes' => 3432571,
        'width' => 5712,
        'height' => 4284,
        'estimated_bytes' => $highResolutionEstimate,
        'allowed' => false,
    ]);
    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $entry = is_array($lines) && isset($lines[0]) ? json_decode($lines[0], true) : null;
    if (!is_array($entry) || ($entry['component'] ?? '') !== 'article-image' || ($entry['event'] ?? '') !== 'memory_guard') {
        throw new RuntimeException('Image processing diagnostic entry was not written as JSON Lines.');
    }
    if (($entry['details']['bytes'] ?? 0) !== 3432571 || ($entry['details']['width'] ?? 0) !== 5712) {
        throw new RuntimeException('Image processing diagnostic metadata was not preserved.');
    }
    echo "image_processing_support_check: OK\n";
} finally {
    if (is_file($logPath)) {
        @unlink($logPath);
    }
    if ($changedLimit !== false) {
        @ini_set('memory_limit', $oldLimit);
    }
}
