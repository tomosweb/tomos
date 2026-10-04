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
check(PostAuthReturnTo::normalize('/post/navigation/') === '/post/navigation/', 'Navigation route must be allowed');
check(PostAuthReturnTo::normalize('/post/analytics/') === '/post/analytics/', 'Analytics route must be allowed');
check(PostAuthReturnTo::normalize('https://example.com/') === '/post/?section=settings', 'absolute URL must use the safe default');
check(PostAuthReturnTo::normalize('//example.com/') === '/post/?section=settings', 'protocol-relative URL must use the safe default');
check(PostAuthReturnTo::normalize('/post/theme/?next=https://example.com/') === '/post/?section=settings', 'query-bearing route must use the safe default');
check(PostAuthReturnTo::normalize('/post/../security/') === '/post/?section=settings', 'traversal route must use the safe default');
check(PostAuthReturnTo::url('/post/theme/', '/theme-labo') === '/theme-labo/post/theme/', 'subdirectory Theme URL');
check(PostAuthReturnTo::url('/post/site-settings.php', '/theme-labo') === '/theme-labo/post/site-settings.php', 'subdirectory Site Settings URL');
check(PostAuthReturnTo::url('/post/navigation/', '/theme-labo') === '/theme-labo/post/navigation/', 'subdirectory Navigation URL');
check(PostAuthReturnTo::url('/post/analytics/', '/theme-labo') === '/theme-labo/post/analytics/', 'subdirectory Analytics URL');

check(PostAuthReturnTo::normalize('/post/?write_import=1&session=0123456789abcdef0123456789abcdef') === '/post/?write_import=1&session=0123456789abcdef0123456789abcdef', 'new draft handoff route must survive authentication');
check(PostAuthReturnTo::url('/post/?write_import=1&session=0123456789abcdef0123456789abcdef', '/theme-labo') === '/theme-labo/post/?write_import=1&session=0123456789abcdef0123456789abcdef', 'subdirectory handoff URL');
check(PostAuthReturnTo::normalize('/post/?write_import=1&session=0123456789abcdef0123456789abcdef&next=https://example.com/') === '/post/?section=settings', 'handoff route must reject extra query parameters');
check(PostAuthReturnTo::normalize('/post/?write_import=1&session=../security') === '/post/?section=settings', 'handoff route must reject unsafe session identifiers');

echo "post_auth_return_to_check: safe allowlist, auth handoff preservation, fallback, open redirect, and subdirectory URL checks passed\n";
