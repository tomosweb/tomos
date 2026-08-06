<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostRateLimiter.php';

use Tomos\PostRateLimiter;

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-rate-test-' . bin2hex(random_bytes(6));
$config = [
    'paths' => ['cache_dir' => $tmp . DIRECTORY_SEPARATOR . 'cache'],
    'security' => ['rate_limit_salt' => str_repeat('a', 64)],
];
$now = 1785900000;

try {
    for ($attempt = 0; $attempt < 5; $attempt += 1) {
        (new PostRateLimiter($config, $tmp, '192.0.2.1', $now + $attempt))->recordFailure();
    }
    $blocked = (new PostRateLimiter($config, $tmp, '192.0.2.1', $now + 5))->checkAuthAllowed();
    assertSame(false, $blocked->allowed, 'five failures must block authentication');
    assertSame('管理用合言葉の入力に複数回失敗したため、15分間操作を停止しています。', $blocked->message, 'authentication block message must be specific');

    $otherIp = (new PostRateLimiter($config, $tmp, '192.0.2.2', $now + 5))->checkAuthAllowed();
    assertSame(true, $otherIp->allowed, 'failure blocking must remain IP-specific');

    $afterBlock = (new PostRateLimiter($config, $tmp, '192.0.2.1', $now + 906))->checkAuthAllowed();
    assertSame(true, $afterBlock->allowed, 'authentication must resume after 15 minutes');

    $reset = new PostRateLimiter($config, $tmp, '192.0.2.3', $now);
    assertSame(true, $reset->checkResetAllowed()->allowed, 'first password reset must be allowed');
    $reset->recordResetAttempt();
    assertSame(false, (new PostRateLimiter($config, $tmp, '192.0.2.3', $now + 30))->checkResetAllowed()->allowed, 'rapid password reset must remain limited');

    foreach (glob($tmp . DIRECTORY_SEPARATOR . 'cache/security/post-rate-limit/*.json') ?: [] as $file) {
        $record = json_decode((string) file_get_contents($file), true);
        if (array_key_exists('last_post_at', $record)) {
            throw new RuntimeException('post interval state must no longer be recorded');
        }
    }

    echo "post_rate_limiter_check: OK\n";
} finally {
    removeTree($tmp);
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException($message);
}

function removeTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($child) ? removeTree($child) : unlink($child);
    }
    rmdir($path);
}
