<?php

declare(strict_types=1);

use Tomos\UpdateReleaseProvider;

function resolvePublicUpdateChain(UpdateReleaseProvider $provider, string $from, string $target): array
{
    if (version_compare($from, $target, '>=')) {
        throw new RuntimeException('update chain must start before its target');
    }

    $chain = [];
    $visited = [];
    $current = $from;
    for ($hop = 0; $hop < 32 && $current !== $target; $hop++) {
        if (isset($visited[$current])) {
            throw new RuntimeException('update chain contains a loop at ' . $current);
        }
        $visited[$current] = true;

        $release = $provider->getNextUpdate($current);
        if (($release['update_available'] ?? false) !== true) {
            throw new RuntimeException('catalog chain stops at ' . $current);
        }
        $next = (string) ($release['next_version'] ?? '');
        if ($next === '' || version_compare($next, $current, '<=')) {
            throw new RuntimeException('catalog chain does not advance from ' . $current);
        }
        if (isset($visited[$next])) {
            throw new RuntimeException('update chain contains a loop at ' . $next);
        }
        if (version_compare($next, $target, '>')) {
            throw new RuntimeException('catalog chain overshoots target ' . $target);
        }

        $packageUrl = (string) ($release['package_url'] ?? '');
        $sha256 = strtolower((string) ($release['sha256'] ?? ''));
        if ($packageUrl === '' || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1) {
            throw new RuntimeException('catalog package metadata is invalid for ' . $current . ' -> ' . $next);
        }
        assertPublicUpdatePackageMetadata($current, $next, $packageUrl);

        $chain[] = [
            'from' => $current,
            'to' => $next,
            'package_url' => $packageUrl,
            'sha256' => $sha256,
        ];
        $current = $next;
    }

    if ($current !== $target) {
        throw new RuntimeException('catalog chain did not reach target ' . $target);
    }
    return $chain;
}

function assertPublicUpdatePackageMetadata(string $from, string $to, string $packageUrl): void
{
    $parts = parse_url($packageUrl);
    $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
    $prefix = '/assets/updates/releases/' . rawurlencode($to) . '/';
    $filename = $path !== '' ? basename($path) : '';
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($parts['host'] ?? '')) !== 'tomoswords.org'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        || strpos($path, $prefix) !== 0
        || preg_match('/\Atomos-update-[0-9A-Za-z.-]+(?:-from-[0-9A-Za-z.-]+)?(?:-recovery)?\.zip\z/', $filename) !== 1
    ) {
        throw new RuntimeException('catalog package URL is inconsistent for ' . $from . ' -> ' . $to);
    }
}

function assertDownloadedPackageHash(string $path, string $expectedSha256): void
{
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('downloaded package is missing or unsafe');
    }
    $actualSha256 = strtolower((string) hash_file('sha256', $path));
    if (!hash_equals(strtolower($expectedSha256), $actualSha256)) {
        throw new RuntimeException('downloaded package SHA-256 does not match catalog');
    }
}
