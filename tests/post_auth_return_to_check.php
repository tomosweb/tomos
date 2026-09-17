<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/Security.php';
require_once dirname(__DIR__) . '/core/PostAuthReturnTo.php';

use Tomos\PostAuthReturnTo;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

check(PostAuthReturnTo::normalize('/post/theme/') === '/post/theme/', 'Theme route must be allowed');
check(PostAuthReturnTo::normalize('/post/site-settings.php') === '/post/site-settings.php', 'Site Settings route must be allowed');
check(PostAuthReturnTo::normalize('https://example.com/') === '/post/?section=settings', 'absolute URL must use the safe default');
check(PostAuthReturnTo::normalize('//example.com/') === '/post/?section=settings', 'protocol-relative URL must use the safe default');
check(PostAuthReturnTo::normalize('/post/theme/?next=https://example.com/') === '/post/?section=settings', 'query-bearing route must use the safe default');
check(PostAuthReturnTo::normalize('/post/../security/') === '/post/?section=settings', 'traversal route must use the safe default');
check(PostAuthReturnTo::url('/post/theme/', '/theme-labo') === '/theme-labo/post/theme/', 'subdirectory Theme URL');
check(PostAuthReturnTo::url('/post/site-settings.php', '/theme-labo') === '/theme-labo/post/site-settings.php', 'subdirectory Site Settings URL');

echo "post_auth_return_to_check: safe allowlist, fallback, open redirect, and subdirectory URL checks passed\n";
