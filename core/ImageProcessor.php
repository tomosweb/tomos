<?php

declare(strict_types=1);

namespace Tomos;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'ImageProcessingSupport.php';

final class ImageProcessResult
{
    public bool $ok;
    public string $path;
    public string $error;
    /** @var string[] */
    public array $warnings;

    /**
     * @param string[] $warnings
     */
    public function __construct(bool $ok, string $path = '', string $error = '', array $warnings = [])
    {
        $this->ok = $ok;
        $this->path = $path;
        $this->error = $error;
        $this->warnings = $warnings;
    }
}

final class ImageProcessor
{
    private const MAX_LONG_EDGE = 2048;
    private const JPEG_QUALITY = 82;
    private const WEBP_QUALITY = 82;
    private const PNG_COMPRESSION = 6;

    private bool $forceOriginal;
    private string $diagnosticLogPath;
    private string $lastSourcePath = '';

    public function __construct(bool $forceOriginal = false, string $diagnosticLogPath = '')
    {
        $this->forceOriginal = $forceOriginal;
        $this->diagnosticLogPath = $diagnosticLogPath;
    }

    public function process(string $sourcePath, string $extension, string $tempDir): ImageProcessResult
    {
        $this->lastSourcePath = $sourcePath;
        $extension = $this->normalizeExtension($extension);
        if ($extension === '') {
            return new ImageProcessResult(false, '', '画像形式を確認できませんでした。');
        }

        $info = @getimagesize($sourcePath);
        if (!is_array($info)) {
            return new ImageProcessResult(false, '', '画像を読み込めませんでした。別の画像を選んでください。');
        }

        $mimeType = strtolower((string) ($info['mime'] ?? ''));
        if (!$this->extensionMatchesMime($extension, $mimeType)) {
            return new ImageProcessResult(
                false,
                '',
                '画像の拡張子（' . strtoupper($extension) . '）と画像データの形式（' . $this->mimeLabel($mimeType) . '）が一致しません。元画像を確認してください。'
            );
        }

        $sourceFacts = ImageProcessingSupport::inspect($sourcePath);
        ImageProcessingSupport::log($this->diagnosticLogPath, 'article-image', 'input', [
            'bytes' => $sourceFacts['bytes'], 'mime' => $sourceFacts['mime'],
            'width' => $sourceFacts['width'], 'height' => $sourceFacts['height'],
            'orientation' => $sourceFacts['orientation'],
        ]);

        $orientationWarnings = [];
        if ($extension === 'jpg' && !$this->canReadExif()) {
            $this->logExif('EXIF extension or exif_read_data is unavailable; orientation correction cannot run.');
            $orientationWarnings[] = '画像の向きを自動調整できなかったため、元の向きで保存しました。';
        }

        $tempPath = $this->tempPath($tempDir, $extension);
        if ($tempPath === '') {
            return new ImageProcessResult(false, '', '画像の一時保存先を作成できませんでした。');
        }

        if ($extension === 'gif') {
            ImageProcessingSupport::log($this->diagnosticLogPath, 'article-image', 'stored_original', ['reason' => 'animated_gif_preserved']);
            return $this->copyOriginal($sourcePath, $tempPath);
        }

        if ($this->forceOriginal || !$this->canProcessWithGd($extension)) {
            if (!$this->forceOriginal) {
                $this->logProcessingFallback('GD image processing is unavailable; original image was preserved.');
            }
            return $this->copyOriginalWithWarnings(
                $sourcePath,
                $tempPath,
                $orientationWarnings
            );
        }

        if (!$this->hasEnoughMemoryForGd($info, $extension, $sourcePath)) {
            $this->logProcessingFallback('GD memory safety check rejected processing; original image was preserved.');
            return $this->copyOriginalWithWarnings(
                $sourcePath,
                $tempPath,
                $orientationWarnings
            );
        }

        try {
            $result = $this->processWithGd($sourcePath, $tempPath, $extension, $info);
            if ($result->ok) {
                $source = ImageProcessingSupport::inspect($sourcePath);
                $output = ImageProcessingSupport::inspect($result->path);
                ImageProcessingSupport::log($this->diagnosticLogPath, 'article-image', 'processed', [
                    'source_bytes' => $source['bytes'], 'source_mime' => $source['mime'],
                    'source_width' => $source['width'], 'source_height' => $source['height'],
                    'orientation' => $source['orientation'], 'output_bytes' => $output['bytes'],
                    'output_mime' => $output['mime'], 'output_width' => $output['width'],
                    'output_height' => $output['height'],
                ]);
            }
            return $result;
        } catch (\Throwable $exception) {
            @unlink($tempPath);
            $this->logProcessingFallback('GD image processing failed; original image was preserved.');
            return $this->copyOriginalWithWarnings(
                $sourcePath,
                $tempPath,
                $orientationWarnings
            );
        }
    }

    private function processWithGd(string $sourcePath, string $targetPath, string $extension, array $info): ImageProcessResult
    {
        $warnings = [];
        $source = $this->createImage($sourcePath, $extension);
        if (!$this->isGdImage($source)) {
            return new ImageProcessResult(false, '', '画像を加工できませんでした。別の画像を選んでください。');
        }

        if ($extension === 'jpg') {
            if (!$this->canReadExif()) {
                $warnings[] = '画像の向きを自動調整できなかったため、元の向きで保存しました。';
            }
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width <= 0 || $height <= 0) {
            $this->releaseGdImage($source);
            return new ImageProcessResult(false, '', '画像サイズを確認できませんでした。');
        }

        [$targetWidth, $targetHeight] = $this->targetSize($width, $height);
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$this->isGdImage($canvas)) {
            $this->releaseGdImage($source);
            return new ImageProcessResult(false, '', '画像を加工できませんでした。');
        }

        if ($extension === 'png' || $extension === 'webp') {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
            }
        }

        if (!imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
            $this->releaseGdImage($source);
            $this->releaseGdImage($canvas);
            return new ImageProcessResult(false, '', '画像を加工できませんでした。');
        }

        // Resize before applying EXIF orientation so a large source image is never
        // duplicated just to rotate/flip the full-resolution GD bitmap.
        if ($extension === 'jpg') {
            $orientation = ImageProcessingSupport::jpegOrientation($sourcePath);
            if ($orientation >= 2 && $orientation <= 8) {
                $oriented = $this->transformOrientation($canvas, $orientation);
                if ($this->isGdImage($oriented)) {
                    $canvas = $oriented;
                } else {
                    $warnings[] = '画像の向きを自動調整できなかったため、元の向きで保存しました。';
                }
            }
        }

        $saved = $this->saveImage($canvas, $targetPath, $extension);
        $this->releaseGdImage($source);
        $this->releaseGdImage($canvas);

        if (!$saved) {
            @unlink($targetPath);
            return new ImageProcessResult(false, '', '画像を保存できませんでした。');
        }

        return new ImageProcessResult(true, $targetPath, '', array_values(array_unique($warnings)));
    }

    private function createImage(string $sourcePath, string $extension)
    {
        if ($extension === 'jpg') {
            return @imagecreatefromjpeg($sourcePath);
        }
        if ($extension === 'png') {
            return @imagecreatefrompng($sourcePath);
        }
        if ($extension === 'webp') {
            return @imagecreatefromwebp($sourcePath);
        }

        return false;
    }

    private function saveImage($image, string $targetPath, string $extension): bool
    {
        if ($extension === 'jpg') {
            return @imagejpeg($image, $targetPath, self::JPEG_QUALITY);
        }
        if ($extension === 'png') {
            return @imagepng($image, $targetPath, self::PNG_COMPRESSION);
        }
        if ($extension === 'webp') {
            return @imagewebp($image, $targetPath, self::WEBP_QUALITY);
        }

        return false;
    }

    private function transformOrientation($image, int $orientation)
    {
        if ($orientation < 2 || $orientation > 8) {
            return false;
        }

        $needsFlip = in_array($orientation, [2, 4, 5, 7], true);
        $needsRotate = in_array($orientation, [3, 5, 6, 7, 8], true);
        if (($needsFlip && !function_exists('imageflip')) || ($needsRotate && !function_exists('imagerotate'))) {
            return false;
        }

        $working = @imagecreatetruecolor(imagesx($image), imagesy($image));
        if (!$this->isGdImage($working) || !@imagecopy($working, $image, 0, 0, 0, 0, imagesx($image), imagesy($image))) {
            if ($this->isGdImage($working)) $this->releaseGdImage($working);
            return false;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            $flipMode = $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL;
            if (!@imageflip($working, $flipMode)) {
                $this->releaseGdImage($working);
                return false;
            }
        }

        $degrees = 0;
        if ($orientation === 3) {
            $degrees = 180;
        } elseif (in_array($orientation, [5, 8], true)) {
            $degrees = 90;
        } elseif (in_array($orientation, [6, 7], true)) {
            $degrees = -90;
        }

        if ($degrees === 0) {
            $this->releaseGdImage($image);
            return $working;
        }
        $rotated = @imagerotate($working, $degrees, 0);
        if (!$this->isGdImage($rotated)) {
            $this->releaseGdImage($working);
            return false;
        }
        $this->releaseGdImage($working);
        $this->releaseGdImage($image);
        return $rotated;
    }

    private function logExif(string $message): void
    {
        error_log('[Tomos ImageProcessor] ' . $message);
    }

    private function logProcessingFallback(string $message): void
    {
        $source = $this->lastSourcePath !== '' && is_file($this->lastSourcePath)
            ? ImageProcessingSupport::inspect($this->lastSourcePath)
            : ['bytes' => 0, 'mime' => '', 'width' => 0, 'height' => 0, 'orientation' => 1];
        ImageProcessingSupport::log($this->diagnosticLogPath, 'article-image', 'fallback', [
            'reason' => $message,
            'source_bytes' => $source['bytes'], 'source_mime' => $source['mime'],
            'source_width' => $source['width'], 'source_height' => $source['height'],
            'orientation' => $source['orientation'],
        ]);
    }

    /**
     * @return int[]
     */
    private function targetSize(int $width, int $height): array
    {
        $longEdge = max($width, $height);
        if ($longEdge <= self::MAX_LONG_EDGE) {
            return [$width, $height];
        }

        $scale = self::MAX_LONG_EDGE / $longEdge;
        return [
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        ];
    }

    private function canProcessWithGd(string $extension): bool
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) {
            return false;
        }

        if ($extension === 'jpg') {
            return function_exists('imagecreatefromjpeg') && function_exists('imagejpeg');
        }
        if ($extension === 'png') {
            return function_exists('imagecreatefrompng') && function_exists('imagepng');
        }
        if ($extension === 'webp') {
            return function_exists('imagecreatefromwebp') && function_exists('imagewebp');
        }

        return false;
    }

    private function copyOriginal(string $sourcePath, string $targetPath): ImageProcessResult
    {
        if (!@copy($sourcePath, $targetPath)) {
            return new ImageProcessResult(false, '', '画像を保存できませんでした。');
        }

        return new ImageProcessResult(true, $targetPath);
    }

    private function copyOriginalWithWarning(string $sourcePath, string $targetPath, string $warning): ImageProcessResult
    {
        return $this->copyOriginalWithWarnings($sourcePath, $targetPath, [$warning]);
    }

    /** @param string[] $warnings */
    private function copyOriginalWithWarnings(string $sourcePath, string $targetPath, array $warnings): ImageProcessResult
    {
        $result = $this->copyOriginal($sourcePath, $targetPath);
        if (!$result->ok) {
            return $result;
        }

        return new ImageProcessResult(true, $result->path, '', array_values(array_unique($warnings)));
    }

    private function canReadExif(): bool
    {
        return extension_loaded('exif') && function_exists('exif_read_data');
    }

    /**
     * Compressed image size does not reflect the memory GD needs after decoding.
     * Keep a reserve for PHP and use a conservative multiplier for GD buffers.
     *
     * @param array<int|string,mixed> $info
     */
    private function hasEnoughMemoryForGd(array $info, string $extension, string $sourcePath): bool
    {
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $orientation = $extension === 'jpg' ? ImageProcessingSupport::jpegOrientation($sourcePath) : 1;
        $estimate = ImageProcessingSupport::estimateGdPeakBytes(
            $width,
            $height,
            self::MAX_LONG_EDGE,
            $orientation >= 2 && $orientation <= 8
        );
        $assessment = ImageProcessingSupport::assessGdMemory($estimate);
        if (!$assessment['allowed']) {
            ImageProcessingSupport::log($this->diagnosticLogPath, 'article-image', 'memory_guard', [
                'source_bytes' => max(0, (int) (@filesize($sourcePath) ?: 0)),
                'mime' => strtolower((string) ($info['mime'] ?? '')),
                'width' => $width, 'height' => $height, 'orientation' => $orientation,
                'estimated_bytes' => $assessment['estimated_bytes'],
                'memory_usage_bytes' => $assessment['usage_bytes'],
                'memory_limit_bytes' => $assessment['limit_bytes'],
                'reserve_bytes' => $assessment['reserve_bytes'],
                'allowed' => false,
            ]);
        }
        return $assessment['allowed'];
    }

    private function isGdImage($value): bool
    {
        if (is_resource($value)) {
            return true;
        }

        return class_exists('GdImage') && $value instanceof \GdImage;
    }

    private function releaseGdImage($image): void
    {
        if (PHP_VERSION_ID < 80000 && is_resource($image)) {
            imagedestroy($image);
        }
    }

    private function tempPath(string $tempDir, string $extension): string
    {
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            return '';
        }

        try {
            return rtrim($tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.tomos-image-' . bin2hex(random_bytes(12)) . '.' . $extension;
        } catch (\Throwable $exception) {
            return '';
        }
    }

    private function normalizeExtension(string $extension): string
    {
        $extension = strtolower($extension);
        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    private function extensionMatchesMime(string $extension, string $mimeType): bool
    {
        if ($extension === 'jpg') {
            return $mimeType === 'image/jpeg';
        }
        if ($extension === 'png') {
            return $mimeType === 'image/png';
        }
        if ($extension === 'gif') {
            return $mimeType === 'image/gif';
        }
        if ($extension === 'webp') {
            return $mimeType === 'image/webp';
        }

        return false;
    }

    private function mimeLabel(string $mimeType): string
    {
        $labels = [
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
            'image/gif' => 'GIF',
            'image/webp' => 'WebP',
        ];
        return $labels[$mimeType] ?? '不明';
    }
}
