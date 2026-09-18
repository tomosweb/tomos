<?php

declare(strict_types=1);

namespace Tomos;

final class TomosWriteHandoff
{
    public const PROTOCOL = 'tomos-write-handoff/v1';
    public const CANONICAL_URL = 'https://tomoswords.org/write/';

    public static function canonicalUrl(): string
    {
        return self::CANONICAL_URL;
    }

    public static function origin(): string
    {
        return (string) parse_url(self::CANONICAL_URL, PHP_URL_SCHEME)
            . '://' . (string) parse_url(self::CANONICAL_URL, PHP_URL_HOST);
    }

    /** @return string[] */
    public static function capabilities(): array
    {
        return [
            'ack',
            'bounded-retry',
            'transaction-id',
            'direct-markdown-import',
            'diagnostics',
        ];
    }
}
