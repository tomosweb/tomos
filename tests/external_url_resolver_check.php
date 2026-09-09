<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Tomos\ExternalUrlResolver;

function resolverAssertSame(?string $expected, ?string $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL . "Expected: {$expected}\nActual: {$actual}\n");
        exit(1);
    }
}

$resolver = new ExternalUrlResolver();
$expected = '<div class="youtube-embed"><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="YouTube video player" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>';

resolverAssertSame(
    $expected,
    $resolver->resolve('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30s'),
    'YouTube resolver output changed'
);
resolverAssertSame(
    null,
    $resolver->resolve('https://example.com/item'),
    'unsupported external URL should remain unresolved'
);

echo "external_url_resolver_check: OK\n";
