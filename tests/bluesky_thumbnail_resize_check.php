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
) {
    echo "bluesky_thumbnail_resize_check: skipped (GD unavailable)\n";
    exit(0);
}

// Build a valid JPEG fixture with GD so the test does not depend on a fragile
// embedded binary. Appending bytes after JPEG EOI keeps the image decodable
// while making the input exceed Bluesky's 2 MB card-image limit.
$fixtureImage = imagecreatetruecolor(64, 64);
if ($fixtureImage === false) {
    throw new RuntimeException('JPEG fixture image could not be created.');
}
$background = imagecolorallocate($fixtureImage, 32, 96, 160);
if ($background === false) {
    imagedestroy($fixtureImage);
    throw new RuntimeException('JPEG fixture color could not be created.');
}
imagefilledrectangle($fixtureImage, 0, 0, 63, 63, $background);

ob_start();
$saved = imagejpeg($fixtureImage, null, 90);
$fixture = ob_get_clean();
imagedestroy($fixtureImage);

if (!$saved || !is_string($fixture) || $fixture === '') {
    throw new RuntimeException('JPEG fixture could not be encoded.');
}

$oversized = $fixture . str_repeat('A', 2100000);
if (@imagecreatefromstring($oversized) === false) {
    throw new RuntimeException('oversized JPEG fixture must remain decodable.');
}

$reflection = new ReflectionClass(BlueskyProvider::class);
$provider = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('prepareThumbnailForBluesky');
// PHP 7.4 requires this for private methods. Since PHP 8.1 it is unnecessary,
// and PHP 8.5 deprecates calling it.
if (PHP_VERSION_ID < 80100) {
    $method->setAccessible(true);
}

/** @var array{0:string,1:string} $prepared */
$prepared = $method->invoke($provider, $oversized, 'image/jpeg');
[$body, $mimeType] = $prepared;

if ($body === '' || $mimeType !== 'image/jpeg') {
    throw new RuntimeException('oversized Bluesky thumbnail must be converted to JPEG');
}
if (strlen($body) >= 2000000) {
    throw new RuntimeException('Bluesky thumbnail must be below the 2 MB upload limit');
}
if (@imagecreatefromstring($body) === false) {
    throw new RuntimeException('prepared Bluesky thumbnail must remain a valid image');
}

echo "bluesky_thumbnail_resize_check: passed\n";
