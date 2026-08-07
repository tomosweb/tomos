<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostPasswordHashUpdater.php';

use Tomos\PostPasswordHashUpdater;

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-post-password-updater-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
$configPath = $tmp . DIRECTORY_SEPARATOR . 'config.php';
$original = <<<'PHP'
<?php
// keep this comment
return [
    'site' => ['url' => 'https://example.com'],
    'security' => [
        'post_password_hash' /* keep spacing */ => '$2y$10$OLDHASH',
        'other' => 'keep-me',
    ],
];
PHP;
file_put_contents($configPath, $original);

try {
    $updater = new PostPasswordHashUpdater($tmp);
    $newHash = '$2y$10$NEWHASHVALUE';
    $updater->update($newHash);

    $updated = file_get_contents($configPath);
    if (!is_string($updated)) {
        throw new RuntimeException('updated config could not be read');
    }
    assertContains("'post_password_hash' /* keep spacing */ => '" . $newHash . "'", $updated, 'hash must be replaced');
    assertContains('// keep this comment', $updated, 'comments must be preserved');
    assertContains("'other' => 'keep-me'", $updated, 'unrelated config must be preserved');

    file_put_contents($configPath, "<?php return ['security' => ['other' => 'x']];\n");
    assertThrows(function () use ($updater, $newHash): void {
        $updater->update($newHash);
    }, 'missing hash key must fail');

    echo "post_password_hash_updater_check: OK\n";
} finally {
    if (is_file($configPath)) unlink($configPath);
    if (is_dir($tmp)) rmdir($tmp);
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}

function assertThrows(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        return;
    }
    throw new RuntimeException($message);
}
