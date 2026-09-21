<?php

declare(strict_types=1);

namespace Tomos;

final class InboxApiCors
{
    /** @return list<string> */
    public static function allowedOrigins(array $config): array
    {
        $configured = $config['security']['inbox_api_allowed_origins'] ?? null;
        $origins = is_array($configured)
            ? $configured
            : [
                'https://tomos-workspace.al-8720554p.workers.dev',
                'http://localhost:5173',
                'http://127.0.0.1:5173',
            ];

        $normalized = [];
        foreach ($origins as $origin) {
            if (!is_string($origin)) {
                continue;
            }
            $value = rtrim(trim($origin), '/');
            if ($value === '' || preg_match('#\Ahttps?://[^/]+\z#i', $value) !== 1) {
                continue;
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    public static function isAllowed(string $origin, array $config): bool
    {
        $normalized = rtrim(trim($origin), '/');
        if ($normalized === '') {
            return false;
        }
        return in_array($normalized, self::allowedOrigins($config), true);
    }

    /** @return array<string,string> */
    public static function responseHeaders(string $origin, array $config): array
    {
        if (!self::isAllowed($origin, $config)) {
            return [];
        }

        return [
            'Access-Control-Allow-Origin' => rtrim(trim($origin), '/'),
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => implode(', ', [
                'Content-Type',
                'Authorization',
                'X-Tomos-Token',
                'X-Tomos-Action',
                'X-Tomos-Request-Id',
                'X-Tomos-Upload-Id',
                'X-Tomos-Image-Name',
                'X-Tomos-Image-Type',
                'X-Tomos-Chunk-Index',
                'X-Tomos-Chunk-Count',
                'X-Tomos-Total-Size',
            ]),
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ];
    }
}
