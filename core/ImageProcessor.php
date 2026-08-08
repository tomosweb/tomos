<?php

declare(strict_types=1);

namespace Tomos;

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

    public function __construct(bool $forceOriginal = false)
    {
        $this->forceOriginal = $forceOriginal;
    }

    public function process(string $sourcePath, string $extension, string $tempDir): ImageProcessResult
    {
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
            return $this->copyOriginal($sourcePath, $tempPath);
        }

        if ($this->forceOriginal || !$this->canProcessWithGd($extension)) {
            return $this->copyOriginalWithWarnings(
                $sourcePath,
                $tempPath,
                array_merge(['画像加工機能が使えないため、画像を元のまま保存しました。'], $orientationWarnings)
            );
        }

        if (!$this->hasEnoughMemoryForGd($info, $extension, $sourcePath)) {
            return $this->copyOriginalWithWarnings(
                $sourcePath,
                $tempPath,
                array_merge(['サーバーの画像加工用メモリが不足する可能性があるため、画像を元のまま保存しました。'], $orientationWarnings)
            );
        }

        try {
            return $this->processWithGd($sourcePath, $tempPath, $extension, $info);
        } catch (\Throwable $exception) {
            @unlink($tempPath);
            return $this->copyOriginalWithWarnings(
                $sourcePath,
                $tempPath,
                array_merge(['画像を加工できなかったため、画像を元のまま保存しました。'], $orientationWarnings)
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
            $source = $this->applyJpegOrientation($source, $sourcePath, $warnings);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($source);
            return new ImageProcessResult(false, '', '画像サイズを確認できませんでした。');
        }

        [$targetWidth, $targetHeight] = $this->targetSize($width, $height);
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$this->isGdImage($canvas)) {
            imagedestroy($source);
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
            imagedestroy($source);
            imagedestroy($canvas);
            return new ImageProcessResult(false, '', '画像を加工できませんでした。');
        }

        $saved = $this->saveImage($canvas, $targetPath, $extension);
        imagedestroy($source);
        imagedestroy($canvas);

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

    private function applyJpegOrientation($image, string $sourcePath, array &$warnings)
    {
        if (!$this->canReadExif()) {
            $warnings[] = '画像の向きを自動調整できなかったため、元の向きで保存しました。';
            return $image;
        }

        $exif = @exif_read_data($sourcePath);
        if (!is_array($exif)) {
            $this->logExif('EXIF data could not be read; no orientation correction was applied.');
            return $image;
        }

        $orientation = $this->orientationFromExif($exif);
        if ($orientation === null || $orientation === 1) {
            return $image;
        }
        $this->logExif('EXIF orientation detected: ' . $orientation);

        $adjusted = $this->transformOrientation($image, $orientation);
        if ($this->isGdImage($adjusted)) {
            return $adjusted;
        }

        $this->logExif('EXIF orientation correction failed for value: ' . $orientation);
        $warnings[] = '画像の向きを自動調整できなかったため、元の向きで保存しました。';
        return $image;
    }

    private function orientationFromExif(array $exif): ?int
    {
        if (isset($exif['Orientation'])) {
            return (int) $exif['Orientation'];
        }
        if (isset($exif['IFD0']) && is_array($exif['IFD0']) && isset($exif['IFD0']['Orientation'])) {
            return (int) $exif['IFD0']['Orientation'];
        }
        return null;
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
            if ($this->isGdImage($working)) imagedestroy($working);
            return false;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            $flipMode = $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL;
            if (!@imageflip($working, $flipMode)) {
                imagedestroy($working);
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
            imagedestroy($image);
            return $working;
        }
        $rotated = @imagerotate($working, $degrees, 0);
        if (!$this->isGdImage($rotated)) {
            imagedestroy($working);
            return false;
        }
        imagedestroy($working);
        imagedestroy($image);
        return $rotated;
    }

    private function logExif(string $message): void
    {
        error_log('[Tomos ImageProcessor] ' . $message);
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

        [$targetWidth, $targetHeight] = $this->targetSize($width, $height);
        $sourceBytes = $width * $height * 4;
        $targetBytes = $targetWidth * $targetHeight * 4;
        $peakBytes = $sourceBytes + $targetBytes;

        if ($extension === 'jpg' && $this->jpegNeedsOrientationTransform($sourcePath)) {
            // transformOrientation() can temporarily keep the original, working copy,
            // and rotated image alive at the same time. Account for that peak before
            // entering GD so low-memory environments fall back instead of fatally exiting.
            $peakBytes = max($peakBytes, $sourceBytes * 3);
        }

        $estimatedBytes = (int) ceil($peakBytes * 1.8);

        $memoryLimit = $this->memoryLimitBytes((string) ini_get('memory_limit'));
        if ($memoryLimit <= 0) {
            return true;
        }

        $reserveBytes = 16 * 1024 * 1024;
        return memory_get_usage(true) + $estimatedBytes + $reserveBytes < $memoryLimit;
    }

    private function jpegNeedsOrientationTransform(string $sourcePath): bool
    {
        if (!$this->canReadExif()) {
            return false;
        }

        $exif = @exif_read_data($sourcePath);
        if (!is_array($exif)) {
            return false;
        }

        $orientation = $this->orientationFromExif($exif);
        return $orientation !== null && $orientation >= 2 && $orientation <= 8;
    }

    private function memoryLimitBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        if ($unit === 'g') {
            $number *= 1024;
            $unit = 'm';
        }
        if ($unit === 'm') {
            $number *= 1024;
            $unit = 'k';
        }
        if ($unit === 'k') {
            $number *= 1024;
        }

        return $number > 0 ? (int) $number : -1;
    }

    private function isGdImage($value): bool
    {
        if (is_resource($value)) {
            return true;
        }

        return class_exists('GdImage') && $value instanceof \GdImage;
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
