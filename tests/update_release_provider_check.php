<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/UpdateReleaseProvider.php';

use Tomos\UpdateReleaseProvider;
use Tomos\UpdateReleaseProviderException;

$passes = 0;

function check(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

function expectError(callable $callable, string $code, string $label): void
{
    try {
        $callable();
    } catch (UpdateReleaseProviderException $exception) {
        check($exception->errorCode() === $code, $label . ' expected ' . $code . ', got ' . $exception->errorCode());
        return;
    }
    throw new RuntimeException($label . ' did not fail');
}

function validCatalog(): array
{
    return [
        'schema' => 1,
        'product' => 'Tomos',
        'updates' => [
            [
                'from' => '0.1.0-alpha.17',
                'to' => '0.1.0-alpha.18',
                'package_url' => 'https://tomoswords.org/assets/updates/releases/0.1.0-alpha.18/tomos-update-0.1.0-alpha.18.zip',
                'sha256' => str_repeat('a', 64),
            ],
            [
                'from' => '0.1.0-alpha.18',
                'to' => '0.1.0-alpha.19',
                'package_url' => 'https://tomoswords.org/assets/updates/releases/0.1.0-alpha.19/tomos-update-0.1.0-alpha.19.zip',
                'sha256' => str_repeat('b', 64),
            ],
        ],
    ];
}

function providerFor(array $response): UpdateReleaseProvider
{
    return new UpdateReleaseProvider(static function (string $url, int $maxBytes) use (&$response): array {
        check($url === UpdateReleaseProvider::CATALOG_URL, 'fixture uses the fixed catalog URL');
        check($maxBytes === UpdateReleaseProvider::CATALOG_MAX_BYTES, 'fixture receives the catalog size limit');
        return $response;
    });
}

function jsonResponse(array $catalog): array
{
    return ['status' => 200, 'body' => json_encode($catalog, JSON_UNESCAPED_SLASHES)];
}

$catalog = validCatalog();
$result = providerFor(jsonResponse($catalog))->getNextUpdate('0.1.0-alpha.17');
check($result['update_available'] === true, 'normal catalog reports an update');
check($result['next_version'] === '0.1.0-alpha.18', 'alpha.17 advances only to alpha.18');
check($result['sha256'] === str_repeat('a', 64), 'next package hash is returned');

$sequenceCatalog = validCatalog();
$sequenceCatalog['updates'][0]['to'] = '0.1.0-alpha.20';
$sequenceCatalog['updates'][0]['package_url'] = 'https://tomoswords.org/assets/updates/releases/0.1.0-alpha.20/tomos-update-0.1.0-alpha.20.zip';
$sequenceCatalog['updates'][] = [
    'from' => '0.1.0-alpha.19',
    'to' => '0.1.0-alpha.20',
    'package_url' => 'https://tomoswords.org/assets/updates/releases/0.1.0-alpha.20/tomos-update-0.1.0-alpha.20.zip',
    'sha256' => str_repeat('c', 64),
];
expectError(static function () use ($sequenceCatalog): void {
    providerFor(jsonResponse($sequenceCatalog))->getNextUpdate('0.1.0-alpha.17');
}, 'update_sequence', 'catalog rejects a skipped intermediate from-version');

$completeSequence = validCatalog();
$completeSequence['updates'][] = [
    'from' => '0.1.0-alpha.19',
    'to' => '0.1.0-alpha.20',
    'package_url' => 'https://tomoswords.org/assets/updates/releases/0.1.0-alpha.20/tomos-update-0.1.0-alpha.20.zip',
    'sha256' => str_repeat('c', 64),
];
$completeResult = providerFor(jsonResponse($completeSequence))->getNextUpdate('0.1.0-alpha.17');
check($completeResult['next_version'] === '0.1.0-alpha.18', 'complete sequential catalog remains valid');

$noUpdate = providerFor(jsonResponse($catalog))->getNextUpdate('0.1.0-alpha.19');
check($noUpdate === [
    'current_version' => '0.1.0-alpha.19',
    'update_available' => false,
    'next_version' => null,
    'package_url' => null,
    'sha256' => null,
], 'missing from-version is a distinct no-update result');

$cases = [];
$cases['product'] = static function (): array { $c = validCatalog(); $c['product'] = 'Other'; return $c; };
$cases['schema'] = static function (): array { $c = validCatalog(); $c['schema'] = 2; return $c; };
$cases['updates'] = static function (): array { $c = validCatalog(); $c['updates'] = 'bad'; return $c; };
$cases['version'] = static function (): array { $c = validCatalog(); $c['updates'][0]['to'] = 'bad version'; return $c; };
$cases['order'] = static function (): array { $c = validCatalog(); $c['updates'][0]['to'] = $c['updates'][0]['from']; return $c; };
$cases['duplicate_from'] = static function (): array { $c = validCatalog(); $c['updates'][1]['from'] = $c['updates'][0]['from']; return $c; };
$cases['sha256'] = static function (): array { $c = validCatalog(); $c['updates'][0]['sha256'] = 'bad'; return $c; };
$cases['http_url'] = static function (): array { $c = validCatalog(); $c['updates'][0]['package_url'] = 'http://tomoswords.org/update.zip'; return $c; };
$cases['host_url'] = static function (): array { $c = validCatalog(); $c['updates'][0]['package_url'] = 'https://evil.example/update.zip'; return $c; };
$cases['userinfo_url'] = static function (): array { $c = validCatalog(); $c['updates'][0]['package_url'] = 'https://user:pass@tomoswords.org/update.zip'; return $c; };
$cases['fragment_url'] = static function (): array { $c = validCatalog(); $c['updates'][0]['package_url'] = 'https://tomoswords.org/update.zip#fragment'; return $c; };
$cases['port_url'] = static function (): array { $c = validCatalog(); $c['updates'][0]['package_url'] = 'https://tomoswords.org:8443/update.zip'; return $c; };
foreach ($cases as $code => $makeCatalog) {
    $expectedCode = $code;
    if (in_array($code, ['http_url', 'host_url', 'userinfo_url', 'fragment_url', 'port_url'], true)) {
        $expectedCode = 'package_url';
    } elseif (in_array($code, ['product', 'schema', 'updates'], true)) {
        $expectedCode = 'catalog';
    } elseif ($code === 'order') {
        $expectedCode = 'version';
    }
    expectError(static function () use ($makeCatalog): void {
        providerFor(jsonResponse($makeCatalog()))->getNextUpdate('0.1.0-alpha.17');
    }, $expectedCode, $code);
}

expectError(static function (): void {
    providerFor(['status' => 200, 'body' => '{'])->getNextUpdate('0.1.0-alpha.17');
}, 'catalog', 'invalid JSON');
expectError(static function (): void {
    providerFor(['status' => 200, 'body' => str_repeat('x', UpdateReleaseProvider::CATALOG_MAX_BYTES + 1)])->getNextUpdate('0.1.0-alpha.17');
}, 'size', 'catalog size');
expectError(static function (): void {
    providerFor(['status' => 503, 'body' => ''])->getNextUpdate('0.1.0-alpha.17');
}, 'http', 'HTTP error');
expectError(static function (): void {
    providerFor(['status' => 302, 'location' => 'https://evil.example/catalog.json'])->getNextUpdate('0.1.0-alpha.17');
}, 'catalog_url', 'redirect to another host');
expectError(static function (): void {
    $provider = new UpdateReleaseProvider(static function (): array {
        return ['status' => 302, 'location' => '/catalog.json'];
    });
    $provider->getNextUpdate('0.1.0-alpha.17');
}, 'redirect_limit', 'redirect limit');

echo "update_release_provider_check: {$passes} checks passed\n";
