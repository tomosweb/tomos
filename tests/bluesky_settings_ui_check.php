<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/post/social/bluesky/index.php';
$source = (string) file_get_contents($path);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    strpos($source, "post/assets/tomos-post-security.css") !== false,
    'Bluesky settings must use the shared Tomos Post UI stylesheet'
);
$assert(
    strpos($source, '<style>') === false,
    'Bluesky settings must not carry a standalone inline stylesheet'
);
$assert(
    strpos($source, 'class="result ng"') !== false
    && strpos($source, 'class="result ok"') !== false,
    'Bluesky settings notices must use shared result styles'
);
$assert(
    strpos($source, 'class="actions"') !== false,
    'Bluesky settings actions must use the shared actions layout'
);
$assert(
    strpos($source, 'class="danger"') !== false,
    'Disconnect must use the shared danger action style'
);

echo "bluesky_settings_ui_check: OK\n";
