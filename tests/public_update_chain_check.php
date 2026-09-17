<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdateReleaseProvider.php';
require_once dirname(__DIR__) . '/tests/public_update_chain_helper.php';

use Tomos\UpdateReleaseProvider;

$passes = 0;

function checkChain(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

function fixtureProvider(array $catalog): UpdateReleaseProvider
{
    return new UpdateReleaseProvider(static function (string $url, int $maxBytes) use ($catalog): array {
        checkChain($url === UpdateReleaseProvider::CATALOG_URL, 'fixture uses the official catalog URL');
        checkChain($maxBytes === UpdateReleaseProvider::CATALOG_MAX_BYTES, 'fixture uses the catalog size limit');
        return ['status' => 200, 'body' => json_encode($catalog, JSON_UNESCAPED_SLASHES)];
    });
}

function chainEntry(string $from, string $to, string $name = ''): array
{
    $name = $name !== '' ? $name : 'tomos-update-' . $from . '-to-' . $to . '.zip';
    return [
        'from' => $from,
        'to' => $to,
        'package_url' => 'https://tomoswords.org/assets/updates/releases/' . $to . '/' . $name,
        'sha256' => str_repeat('a', 64),
    ];
}

function expectChainFailure(array $catalog, string $label): void
{
    try {
        resolvePublicUpdateChain(fixtureProvider($catalog), '0.6.1', '0.6.4');
    } catch (Throwable $exception) {
        checkChain($exception instanceof RuntimeException, $label . ' failed with an unexpected exception');
        return;
    }
    throw new RuntimeException($label . ' was accepted');
}

$complete = [
    'schema' => 1,
    'product' => 'Tomos',
    'updates' => [
        chainEntry('0.6.1', '0.6.3'),
        chainEntry('0.6.3', '0.6.4'),
    ],
];
$chain = resolvePublicUpdateChain(fixtureProvider($complete), '0.6.1', '0.6.4');
checkChain(count($chain) === 2, 'complete sequential chain has two steps');
checkChain($chain[0]['from'] === '0.6.1' && $chain[0]['to'] === '0.6.3', 'first chain step is 0.6.1 -> 0.6.3');
checkChain($chain[1]['from'] === '0.6.3' && $chain[1]['to'] === '0.6.4', 'second chain step is 0.6.3 -> 0.6.4');
checkChain(resolvePublicUpdateChain(fixtureProvider(['schema' => 1, 'product' => 'Tomos', 'updates' => [chainEntry('0.6.3', '0.6.4')]]), '0.6.3', '0.6.4')[0]['to'] === '0.6.4', 'single final step reaches latest');

expectChainFailure(['schema' => 1, 'product' => 'Tomos', 'updates' => [chainEntry('0.6.1', '0.6.3')]], 'chain stopping before latest');
expectChainFailure(['schema' => 1, 'product' => 'Tomos', 'updates' => [chainEntry('0.6.1', '0.6.3'), chainEntry('0.6.3', '0.6.1')]], 'downgrade');
expectChainFailure(['schema' => 1, 'product' => 'Tomos', 'updates' => [chainEntry('0.6.1', '0.6.3'), chainEntry('0.6.3', '0.6.1')]], 'loop');
expectChainFailure(['schema' => 1, 'product' => 'Tomos', 'updates' => [chainEntry('0.6.1', '0.6.3')]], 'missing intermediate candidate');
expectChainFailure(['schema' => 1, 'product' => 'Tomos', 'updates' => [chainEntry('0.6.1', '0.6.3'), chainEntry('0.6.3', '0.6.4', 'tomos-update-0.6.3-to-0.6.4.bin')]], 'package filename mismatch');

$packageFixture = sys_get_temp_dir() . '/tomos-public-chain-package-' . bin2hex(random_bytes(8));
mkdir($packageFixture, 0700, true);
$packagePath = $packageFixture . '/package.zip';
file_put_contents($packagePath, 'fixture package');
assertDownloadedPackageHash($packagePath, hash_file('sha256', $packagePath));
$rejected = false;
try {
    assertDownloadedPackageHash($packagePath, str_repeat('b', 64));
} catch (RuntimeException $exception) {
    $rejected = true;
}
if (!$rejected) {
    throw new RuntimeException('package hash mismatch was accepted');
}
checkChain(true, 'package hash mismatch is rejected');
$rejected = false;
try {
    assertDownloadedPackageHash($packageFixture . '/missing.zip', str_repeat('a', 64));
} catch (RuntimeException $exception) {
    $rejected = true;
}
if (!$rejected) {
    throw new RuntimeException('missing package was accepted');
}
checkChain(true, 'missing package is rejected');
unlink($packagePath);
rmdir($packageFixture);

echo "public_update_chain_check: {$passes} checks passed\n";
