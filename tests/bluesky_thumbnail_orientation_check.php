<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/SocialPublishResult.php';
require_once dirname(__DIR__) . '/core/SocialProvider.php';
require_once dirname(__DIR__) . '/core/BlueskyProvider.php';

use Tomos\BlueskyProvider;

if (
    !function_exists('imagecreatetruecolor')
    || !function_exists('imagejpeg')
    || !function_exists('imagecreatefromstring')
    || !function_exists('imagecopyresampled')
    || !function_exists('exif_read_data')
    || !function_exists('getimagesizefromstring')
    || !function_exists('getimagesize')
) {
    echo "bluesky_thumbnail_orientation_check: skipped (GD or EXIF unavailable)\n";
    exit(0);
}

function releaseTestImage($image): void
{
    if (PHP_VERSION_ID < 80000 && is_resource($image)) {
        imagedestroy($image);
    }
}

function encodeJpeg($image, int $quality = 100): string
{
    ob_start();
    $saved = imagejpeg($image, null, $quality);
    $body = ob_get_clean();
    if (!$saved || !is_string($body) || $body === '') {
        throw new RuntimeException('JPEG fixture could not be encoded.');
    }
    return $body;
}

function withExifOrientation(string $jpeg, int $orientation): string
{
    $tiff = "II" . pack('v', 42) . pack('V', 8);
    $ifd = pack('v', 1)
        . pack('v', 0x0112)
        . pack('v', 3)
        . pack('V', 1)
        . pack('v', $orientation)
        . pack('v', 0)
        . pack('V', 0);
    $payload = "Exif\0\0" . $tiff . $ifd;
    $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

    if (substr($jpeg, 0, 2) !== "\xFF\xD8") {
        throw new RuntimeException('JPEG fixture must begin with SOI.');
    }

    return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
}

function createQuadrantJpeg(int $width, int $height, int $quality = 100): string
{
    $image = imagecreatetruecolor($width, $height);
    if ($image === false) {
        throw new RuntimeException('Quadrant fixture image could not be created.');
    }

    $colors = [
        imagecolorallocate($image, 255, 0, 0),
        imagecolorallocate($image, 0, 255, 0),
        imagecolorallocate($image, 0, 0, 255),
        imagecolorallocate($image, 255, 255, 0),
    ];
    foreach ($colors as $color) {
        if ($color === false) {
            releaseTestImage($image);
            throw new RuntimeException('Quadrant fixture color could not be allocated.');
        }
    }

    $middleX = (int) floor($width / 2);
    $middleY = (int) floor($height / 2);
    imagefilledrectangle($image, 0, 0, $middleX - 1, $middleY - 1, $colors[0]);
    imagefilledrectangle($image, $middleX, 0, $width - 1, $middleY - 1, $colors[1]);
    imagefilledrectangle($image, 0, $middleY, $middleX - 1, $height - 1, $colors[2]);
    imagefilledrectangle($image, $middleX, $middleY, $width - 1, $height - 1, $colors[3]);

    $jpeg = encodeJpeg($image, $quality);
    releaseTestImage($image);
    return $jpeg;
}

function invokePrepare(ReflectionMethod $method, $provider, string $body, string $mimeType): array
{
    /** @var array{0:string,1:string} $prepared */
    $prepared = $method->invoke($provider, $body, $mimeType);
    return $prepared;
}

function invokeLocalPrepare(ReflectionMethod $method, $provider, string $path, string $mimeType): array
{
    /** @var array{0:string,1:string} $prepared */
    $prepared = $method->invoke($provider, $path, $mimeType);
    return $prepared;
}

function assertDimensions(string $body, int $width, int $height, string $label): void
{
    $image = @imagecreatefromstring($body);
    if ($image === false) {
        throw new RuntimeException($label . ': prepared body is not a valid image.');
    }
    $actualWidth = imagesx($image);
    $actualHeight = imagesy($image);
    releaseTestImage($image);
    if ($actualWidth !== $width || $actualHeight !== $height) {
        throw new RuntimeException(sprintf(
            '%s: expected %dx%d, got %dx%d.',
            $label,
            $width,
            $height,
            $actualWidth,
            $actualHeight
        ));
    }
}

function assertCornerColor(string $body, int $x, int $y, array $expected, string $label): void
{
    $image = @imagecreatefromstring($body);
    if ($image === false) {
        throw new RuntimeException($label . ': prepared body is not a valid image.');
    }
    $color = imagecolorat($image, $x, $y);
    $actual = [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
    releaseTestImage($image);
    $distance = abs($actual[0] - $expected[0])
        + abs($actual[1] - $expected[1])
        + abs($actual[2] - $expected[2]);
    if ($distance > 120) {
        throw new RuntimeException(sprintf(
            '%s: expected color near [%d,%d,%d], got [%d,%d,%d].',
            $label,
            $expected[0],
            $expected[1],
            $expected[2],
            $actual[0],
            $actual[1],
            $actual[2]
        ));
    }
}

$reflection = new ReflectionClass(BlueskyProvider::class);
$provider = $reflection->newInstanceWithoutConstructor();

$remoteMethod = $reflection->getMethod('prepareThumbnailForBluesky');
$localMethod = $reflection->getMethod('prepareLocalThumbnailForBluesky');
$memoryMethod = $reflection->getMethod('hasEnoughMemoryForBlueskyDimensions');
if (PHP_VERSION_ID < 80100) {
    $remoteMethod->setAccessible(true);
    $localMethod->setAccessible(true);
    $memoryMethod->setAccessible(true);
}

$base = createQuadrantJpeg(40, 20);
$prepared = invokePrepare($remoteMethod, $provider, $base, 'image/jpeg');
if ($prepared[0] !== $base || $prepared[1] !== 'image/jpeg') {
    throw new RuntimeException('JPEG without EXIF below target should keep its original bytes.');
}

$orientation1 = withExifOrientation($base, 1);
$prepared = invokePrepare($remoteMethod, $provider, $orientation1, 'image/jpeg');
if ($prepared[0] !== $orientation1 || $prepared[1] !== 'image/jpeg') {
    throw new RuntimeException('Orientation=1 JPEG below target should keep its original bytes.');
}

$expectedCorners = [
    'red' => [255, 0, 0],
    'green' => [0, 255, 0],
    'blue' => [0, 0, 255],
    'yellow' => [255, 255, 0],
];

$orientationExpectations = [
    2 => [40, 20, $expectedCorners['green']],
    3 => [40, 20, $expectedCorners['yellow']],
    4 => [40, 20, $expectedCorners['blue']],
    5 => [20, 40, $expectedCorners['red']],
    6 => [20, 40, $expectedCorners['blue']],
    7 => [20, 40, $expectedCorners['yellow']],
    8 => [20, 40, $expectedCorners['green']],
];

foreach ($orientationExpectations as $orientation => [$expectedWidth, $expectedHeight, $expectedTopLeft]) {
    $input = withExifOrientation($base, $orientation);
    [$body, $mimeType] = invokePrepare($remoteMethod, $provider, $input, 'image/jpeg');
    if ($body === '' || $mimeType !== 'image/jpeg') {
        throw new RuntimeException('Remote Orientation=' . $orientation . ' JPEG was not prepared.');
    }

    assertDimensions($body, $expectedWidth, $expectedHeight, 'Remote Orientation=' . $orientation);
    assertCornerColor($body, 5, 5, $expectedTopLeft, 'Remote Orientation=' . $orientation . ' top-left');
}

$smallOrientation6 = withExifOrientation($base, 6) . str_repeat('A', 100000);
if (strlen($smallOrientation6) > 1800000) {
    throw new RuntimeException('Small orientation fixture unexpectedly exceeds target size.');
}
[$smallPrepared, $smallMime] = invokePrepare($remoteMethod, $provider, $smallOrientation6, 'image/jpeg');
if ($smallPrepared === '' || $smallMime !== 'image/jpeg' || $smallPrepared === $smallOrientation6) {
    throw new RuntimeException('Sub-1.8 MB oriented remote JPEG must be regenerated.');
}
assertDimensions($smallPrepared, 20, 40, 'Remote sub-1.8 MB Orientation=6');

$largeBase = createQuadrantJpeg(2000, 1000, 90);
$largeInput = withExifOrientation($largeBase, 6) . str_repeat('B', 1850000);
if (strlen($largeInput) <= 1800000) {
    throw new RuntimeException('Large JPEG fixture must exceed the 1.8 MB target.');
}
[$largePrepared, $largeMime] = invokePrepare($remoteMethod, $provider, $largeInput, 'image/jpeg');
if ($largePrepared === '' || $largeMime !== 'image/jpeg') {
    throw new RuntimeException('Oversized remote JPEG was not prepared.');
}
if (strlen($largePrepared) > 1800000 || strlen($largePrepared) >= 2000000) {
    throw new RuntimeException('Oversized remote JPEG was not reduced below the Bluesky limits.');
}
assertDimensions($largePrepared, 800, 1600, 'Remote oversized Orientation=6');

$tempPath = tempnam(sys_get_temp_dir(), 'tomos-bluesky-test-');
if (!is_string($tempPath) || $tempPath === '') {
    throw new RuntimeException('Could not create local thumbnail fixture.');
}
$localPath = $tempPath . '.jpg';
@unlink($tempPath);
if (@file_put_contents($localPath, withExifOrientation($base, 6)) === false) {
    throw new RuntimeException('Could not write local thumbnail fixture.');
}
try {
    [$localPrepared, $localMime] = invokeLocalPrepare($localMethod, $provider, $localPath, 'image/jpeg');
    if ($localPrepared === '' || $localMime !== 'image/jpeg') {
        throw new RuntimeException('Local Orientation=6 JPEG was not prepared.');
    }
    assertDimensions($localPrepared, 20, 40, 'Local Orientation=6');
    assertCornerColor($localPrepared, 5, 5, $expectedCorners['blue'], 'Local Orientation=6 top-left');
} finally {
    @unlink($localPath);
}

$pngImage = imagecreatetruecolor(20, 10);
if ($pngImage === false) {
    throw new RuntimeException('PNG fixture image could not be created.');
}
$pngColor = imagecolorallocate($pngImage, 12, 34, 56);
if ($pngColor === false) {
    releaseTestImage($pngImage);
    throw new RuntimeException('PNG fixture color could not be allocated.');
}
imagefilledrectangle($pngImage, 0, 0, 19, 9, $pngColor);
ob_start();
$pngSaved = imagepng($pngImage);
$png = ob_get_clean();
releaseTestImage($pngImage);
if (!$pngSaved || !is_string($png) || $png === '') {
    throw new RuntimeException('PNG fixture could not be encoded.');
}
[$pngPrepared, $pngMime] = invokePrepare($remoteMethod, $provider, $png, 'image/png');
if ($pngPrepared !== $png || $pngMime !== 'image/png') {
    throw new RuntimeException('Small PNG behavior regressed.');
}

if (function_exists('imagewebp')) {
    $webpImage = imagecreatetruecolor(20, 10);
    if ($webpImage === false) {
        throw new RuntimeException('WebP fixture image could not be created.');
    }
    $webpColor = imagecolorallocate($webpImage, 65, 43, 21);
    if ($webpColor === false) {
        releaseTestImage($webpImage);
        throw new RuntimeException('WebP fixture color could not be allocated.');
    }
    imagefilledrectangle($webpImage, 0, 0, 19, 9, $webpColor);
    ob_start();
    $webpSaved = imagewebp($webpImage, null, 80);
    $webp = ob_get_clean();
    releaseTestImage($webpImage);
    if (!$webpSaved || !is_string($webp) || $webp === '') {
        throw new RuntimeException('WebP fixture could not be encoded.');
    }
    [$webpPrepared, $webpMime] = invokePrepare($remoteMethod, $provider, $webp, 'image/webp');
    if ($webpPrepared !== $webp || $webpMime !== 'image/webp') {
        throw new RuntimeException('Small WebP behavior regressed.');
    }
}

$originalMemoryLimit = (string) ini_get('memory_limit');
$memoryLimitChanged = @ini_set('memory_limit', '64M');
if ($memoryLimitChanged !== false) {
    try {
        $allowed = $memoryMethod->invoke($provider, 12000, 12000, true);
        if ($allowed !== false) {
            throw new RuntimeException('Huge oriented image should be rejected by the Bluesky GD memory guard.');
        }
    } finally {
        @ini_set('memory_limit', $originalMemoryLimit);
    }
}

echo sprintf(
    "bluesky_thumbnail_orientation_check: passed (remote orientations=2-8; local orientation=6; small_jpeg=%d->%d bytes; oversized_jpeg=%d->%d bytes)\n",
    strlen($smallOrientation6),
    strlen($smallPrepared),
    strlen($largeInput),
    strlen($largePrepared)
);
